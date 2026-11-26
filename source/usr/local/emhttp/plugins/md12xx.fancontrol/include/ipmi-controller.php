<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/ipmi.php';
@mkdir(COOLING_IPMI_RUNTIME, 0755, true);
$singleton = fopen(COOLING_IPMI_RUNTIME . '/ipmi-controller.lock', 'c+');
if (!$singleton || !flock($singleton, LOCK_EX | LOCK_NB)) exit(1);
$lease = COOLING_IPMI_LEASE;
$recoverStartup = is_file($lease);
$latch = COOLING_IPMI_RUNTIME . '/ipmi-fault-latched';
$running = true;
$previousAutoPercent = null;
$previousProfile = null;
$lastHealthy = null;
$sensorGuard = [];
$pendingMarker = COOLING_IPMI_RUNTIME . '/ipmi-confirmation-pending.json';
$incidentFile = COOLING_IPMI_RUNTIME . '/ipmi-sensor-incidents.json';
// A pending restart is never a clean startup, even though handback removed the ownership lease.
$priorStatus = cooling_ipmi_read(COOLING_IPMI_RUNTIME . '/ipmi-status.json');
if (is_file(COOLING_IPMI_RECOVERY) || is_file($pendingMarker) || ($priorStatus['sensorConfirmationPending'] ?? false) === true) {
    cooling_ipmi_latch('Controller restarted during sensor confirmation',
        ['pendingConfirmation' => cooling_ipmi_read($pendingMarker), 'previousStatus' => $priorStatus]);
    if (is_file($latch)) @unlink($pendingMarker);
}
if (is_file($incidentFile)) {
    try { $sensorGuard = cooling_ipmi_incident_history(cooling_ipmi_read($incidentFile), hrtime(true) / 1e9); }
    catch (Throwable $error) { cooling_ipmi_latch('Invalid persisted sensor incident history'); }
}

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, function () use (&$running): void { $running = false; });
}
register_shutdown_function(function () use ($lease): void {
    if (!cooling_ipmi_restore('cooling_ipmi_run', $lease)) error_log('Cooling: iDRAC restoration failed; manual recovery required');
});
while ($running) {
    $guard = fopen(COOLING_IPMI_RUNTIME . '/ipmi-command.lock', 'c+');
    if (!$guard || !flock($guard, LOCK_EX | LOCK_NB)) { sleep(1); continue; }
    try {
        $deadlineStart = $sensorGuard['handbackAt'] ?? $sensorGuard['pendingSince'] ?? null;
        $GLOBALS['cooling_ipmi_read_deadline'] = $deadlineStart === null ? null : $deadlineStart + COOLING_IPMI_CONFIRMATION_LIMIT;
        $capability = cooling_ipmi_capability();
        $config = cooling_ipmi_config(cooling_ipmi_read(COOLING_IPMI_CONFIG));
        if ($recoverStartup) {
            if (!cooling_ipmi_restore('cooling_ipmi_run', $lease)) cooling_ipmi_latch('Startup iDRAC restoration failed');
            $recoverStartup = false;
        }
        $globalConfig = cooling_ipmi_read('/boot/config/plugins/md12xx.fancontrol/config.json');
        $globalCurve = cooling_validate_curve($globalConfig['curve'] ?? cooling_default_curve());
        $config['effectiveCurve'] = cooling_device_curve($config, $globalCurve, 15);
        $config['effectiveHysteresisC'] = cooling_device_hysteresis($config, cooling_validate_hysteresis($globalConfig['hysteresisC'] ?? 1.0));
        $profile = json_encode([$config['enabled'], $config['mode'], $config['curveMode'], $config['effectiveCurve'], $config['effectiveHysteresisC']]);
        if ($profile !== $previousProfile) {
            if (isset($sensorGuard['pendingSince'])) cooling_ipmi_latch('Cooling settings changed during sensor recovery');
            $previousAutoPercent = null;
            $sensorGuard = ['episodes' => $sensorGuard['episodes'] ?? []];
        }
        $previousProfile = $profile;
        $sampleStarted = hrtime(true) / 1e9;
        $output = $capability['available'] ? cooling_ipmi_run(['sdr', 'elist', 'all']) : ['code' => 1, 'output' => ''];
        $sensors = cooling_ipmi_sensors($output['output'], $capability['cpuCount']);
        $sampleFinished = hrtime(true) / 1e9;
        $fresh = $output['code'] === 0 && $sampleFinished - $sampleStarted < 15;
        if (!$fresh) $sensors['healthy'] = false;
        $policy = ['state' => [], 'pending' => false, 'latch' => false, 'reason' => ''];
        $wasPending = isset($sensorGuard['pendingSince']);
        if ($config['enabled'] && $config['mode'] !== 'firmware' && $capability['available'] && !is_file($latch)) {
            $policy = cooling_ipmi_sensor_guard($sensorGuard, $sensors, $fresh, $sampleFinished,
                $config['mode'] === 'auto' && (is_file($lease) || $wasPending));
            $sensorGuard = $policy['state'];
        } else { $sensorGuard = ['episodes' => $sensorGuard['episodes'] ?? []]; }
        $target = cooling_ipmi_target($config, $sensors, $previousAutoPercent);
        if ($policy['pending'] || $policy['latch'])
            $target = ['mode' => 'firmware', 'percent' => null, 'reason' => $policy['reason']];
        if (!$capability['available'] || is_file($latch)) {
            $firstFault = cooling_ipmi_read(COOLING_IPMI_RUNTIME . '/ipmi-fault.json');
            $target = ['mode' => 'firmware', 'percent' => null, 'reason' => is_file($latch)
                ? 'Control paused after a fault: ' . ($firstFault['reason'] ?? 'original cause unavailable for this older latch') . '; review and save settings to re-arm'
                : $capability['message']];
        }
        // Persist BEFORE firmware handback can remove ownership; a crash in any later gap latches at restart.
        if ($policy['pending'] && !$wasPending) {
            try {
                if (!is_file($lease)) throw new RuntimeException('Missing ownership before firmware handback');
                cooling_ipmi_store(COOLING_IPMI_RECOVERY, ['startedAt' => time(), 'sensorExitCode' => $output['code']]);
                cooling_ipmi_store($pendingMarker, ['schema' => 1, 'pendingSince' => $sensorGuard['pendingSince'], 'at' => $sampleFinished]);
                cooling_ipmi_store($incidentFile, ['schema' => 1, 'at' => $sampleFinished, 'episodes' => $sensorGuard['episodes'] ?? []]);
            } catch (Throwable $error) {
                cooling_ipmi_latch('Cannot persist sensor confirmation safety state');
                $policy['latch'] = true; $policy['pending'] = false;
                $target = ['mode' => 'firmware', 'percent' => null, 'reason' => 'Sensor confirmation persistence failed'];
            }
        }
        // A manual latch inserted during collection always dominates a previously calculated recovery.
        if ($wasPending && is_file($lease)) cooling_ipmi_latch('Ownership changed during iDRAC recovery');
        if (is_file($latch) && $target['mode'] !== 'firmware') $target = ['mode' => 'firmware', 'percent' => null, 'reason' => 'Control paused after a latched fault'];
        if ($wasPending && !$policy['pending'] && !$policy['latch'] && !is_file($latch) && $target['mode'] === 'auto') {
            try {
                cooling_ipmi_store($incidentFile, ['schema' => 1, 'at' => $sampleFinished, 'episodes' => $sensorGuard['episodes']]);
            } catch (Throwable $error) {
                cooling_ipmi_latch('Could not persist the automatic recovery cooldown');
                $target = ['mode' => 'firmware', 'percent' => null, 'reason' => 'Automatic recovery cooldown persistence failed'];
            }
        }
        $applied = cooling_ipmi_apply($target, 'cooling_ipmi_run', $lease);
        if ($policy['pending'] && !$wasPending && $applied['ok'] && $applied['mode'] === 'firmware' && !is_file($lease)) {
            $sensorGuard['handbackAt'] = hrtime(true) / 1e9;
        }
        if ($wasPending && !$policy['pending'] && $applied['ok'] && $applied['mode'] === 'auto' &&
            (!is_file(COOLING_IPMI_RECOVERY) || !@unlink(COOLING_IPMI_RECOVERY))) {
            cooling_ipmi_latch('Could not clear the automatic recovery marker');
            $target = ['mode' => 'firmware', 'percent' => null, 'reason' => 'Automatic recovery marker cleanup failed'];
            $applied = cooling_ipmi_apply($target, 'cooling_ipmi_run', $lease);
        }
        if (!$applied['ok'] || ($config['enabled'] && $config['mode'] !== 'firmware' && (!$capability['available'] || $policy['latch']))) {
            $reason = !$applied['ok'] ? $applied['message'] : (!$capability['available'] ? $capability['message']
                : ($output['code'] !== 0 ? 'Sensor query failed (exit ' . $output['code'] . ')' : $target['reason']));
            cooling_ipmi_latch($reason, ['sensorExitCode' => $output['code'], 'sensors' => $sensors,
                'collectionSeconds' => $sampleFinished - $sampleStarted, 'sampleFresh' => $fresh, 'sensorConfirmation' => $policy,
                'lastHealthy' => $lastHealthy, 'requestedTarget' => $target, 'applied' => $applied]);
        }
        if (!$policy['pending'] && ($applied['ok'] || is_file($latch))) @unlink($pendingMarker);
        if ($sensors['healthy']) $lastHealthy = ['generatedAt' => time(), 'sensors' => $sensors];
        $fault = !$applied['ok'] || is_file($latch);
        $previousAutoPercent = !$fault && $applied['mode'] === 'auto' ? $target['percent'] : null;
        cooling_ipmi_store(COOLING_IPMI_RUNTIME . '/ipmi-status.json', array_merge($sensors, [
            'generatedAt' => time(), 'state' => $fault ? 'fault' : ($policy['pending'] ? 'recovering' : 'normal'), 'enabled' => $config['enabled'],
            'mode' => $applied['mode'], 'targetPercent' => $applied['ok'] ? $target['percent'] : null,
            'message' => $applied['message'], 'targetReason' => $target['reason'], 'latched' => is_file($latch),
            'effectiveHysteresisC' => $config['effectiveHysteresisC'], 'sensorConfirmationPending' => $policy['pending'],
            'collectionSeconds' => $sampleFinished - $sampleStarted,
        ]));
    } finally { flock($guard, LOCK_UN); fclose($guard); }
    $wakeAt = hrtime(true) / 1e9 + 5;
    if (!is_file($latch) && isset($sensorGuard['pendingSince'])) {
        $wakeAt = min($wakeAt, ($sensorGuard['handbackAt'] ?? $sensorGuard['pendingSince']) + COOLING_IPMI_CONFIRMATION_LIMIT);
    }
    while ($running && hrtime(true) / 1e9 < $wakeAt) usleep(50000);
}
