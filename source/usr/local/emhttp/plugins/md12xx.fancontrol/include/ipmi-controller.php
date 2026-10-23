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
            if (!cooling_ipmi_restore('cooling_ipmi_run', $lease)) touch($latch);
            $recoverStartup = false;
        }
        $globalCurve = cooling_validate_curve(cooling_ipmi_read('/boot/config/plugins/md12xx.fancontrol/config.json')['curve'] ?? cooling_default_curve());
        $config['effectiveCurve'] = cooling_device_curve($config, $globalCurve);
        $output = $capability['available'] ? cooling_ipmi_run(['sdr', 'elist', 'all']) : ['code' => 1, 'output' => ''];
        $sensors = cooling_ipmi_sensors($output['code'] === 0 ? $output['output'] : '', $capability['cpuCount']);
        $target = cooling_ipmi_target($config, $sensors);
        if (!$capability['available'] || is_file($latch)) $target = ['mode' => 'firmware', 'percent' => null, 'reason' => is_file($latch) ? 'Control paused after a fault; review and save settings to re-arm' : $capability['message']];
        $applied = cooling_ipmi_apply($target, 'cooling_ipmi_run', $lease);
        if (!$applied['ok'] || ($config['enabled'] && $config['mode'] !== 'firmware' && (!$sensors['healthy'] || $target['mode'] === 'firmware'))) touch($latch);
        $fault = !$applied['ok'] || is_file($latch);
        cooling_ipmi_store(COOLING_IPMI_RUNTIME . '/ipmi-status.json', array_merge($sensors, [
            'generatedAt' => time(), 'state' => $fault ? 'fault' : 'normal', 'enabled' => $config['enabled'],
            'mode' => $applied['mode'], 'targetPercent' => $applied['ok'] ? $target['percent'] : null,
            'message' => $applied['message'], 'targetReason' => $target['reason'], 'latched' => is_file($latch),
        ]));
    } finally { flock($guard, LOCK_UN); fclose($guard); }
    for ($i = 0; $i < 5 && $running; $i++) sleep(1);
}
