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
        if ($profile !== $previousProfile) $previousAutoPercent = null;
        $previousProfile = $profile;
        $output = $capability['available'] ? cooling_ipmi_run(['sdr', 'elist', 'all']) : ['code' => 1, 'output' => ''];
        $sensors = cooling_ipmi_sensors($output['code'] === 0 ? $output['output'] : '', $capability['cpuCount']);
        $target = cooling_ipmi_target($config, $sensors, $previousAutoPercent);
        if (!$capability['available'] || is_file($latch)) {
            $firstFault = cooling_ipmi_read(COOLING_IPMI_RUNTIME . '/ipmi-fault.json');
            $target = ['mode' => 'firmware', 'percent' => null, 'reason' => is_file($latch)
                ? 'Control paused after a fault: ' . ($firstFault['reason'] ?? 'original cause unavailable for this older latch') . '; review and save settings to re-arm'
                : $capability['message']];
        }
        $applied = cooling_ipmi_apply($target, 'cooling_ipmi_run', $lease);
        if (!$applied['ok'] || ($config['enabled'] && $config['mode'] !== 'firmware' && (!$sensors['healthy'] || $target['mode'] === 'firmware'))) {
            $reason = !$applied['ok'] ? $applied['message'] : (!$capability['available'] ? $capability['message']
                : ($output['code'] !== 0 ? 'Sensor query failed (exit ' . $output['code'] . ')' : $target['reason']));
            cooling_ipmi_latch($reason, ['sensorExitCode' => $output['code'], 'sensors' => $sensors,
                'lastHealthy' => $lastHealthy, 'requestedTarget' => $target, 'applied' => $applied]);
        }
        if ($sensors['healthy']) $lastHealthy = ['generatedAt' => time(), 'sensors' => $sensors];
        $fault = !$applied['ok'] || is_file($latch);
        $previousAutoPercent = !$fault && $applied['mode'] === 'auto' ? $target['percent'] : null;
        cooling_ipmi_store(COOLING_IPMI_RUNTIME . '/ipmi-status.json', array_merge($sensors, [
            'generatedAt' => time(), 'state' => $fault ? 'fault' : 'normal', 'enabled' => $config['enabled'],
            'mode' => $applied['mode'], 'targetPercent' => $applied['ok'] ? $target['percent'] : null,
            'message' => $applied['message'], 'targetReason' => $target['reason'], 'latched' => is_file($latch),
            'effectiveHysteresisC' => $config['effectiveHysteresisC'],
        ]));
    } finally { flock($guard, LOCK_UN); fclose($guard); }
    for ($i = 0; $i < 5 && $running; $i++) sleep(1);
}
