<?php
declare(strict_types=1);
require $argv[1] . '/source/usr/local/emhttp/plugins/md12xx.fancontrol/include/common.php';
function check(bool $value, string $why): void { if (!$value) throw new RuntimeException($why); }
$root = $argv[1];
$tmp = sys_get_temp_dir() . '/md12xx-safety-' . bin2hex(random_bytes(8));
mkdir($tmp);
$fixture = file_get_contents($root . '/tests/fixtures/ses/shelf-1.txt');
check(md12xx_parse_fan_telemetry($fixture)['state'] === 'normal', 'Healthy four-fan sample');
$overall = "    Overall descriptor:\n      status: OK\n      Actual speed=0 rpm\n";
$aggregate = preg_replace('/(Element type: Cooling[^\n]*\n)/', '$1' . $overall, $fixture);
check(md12xx_parse_fan_telemetry($aggregate)['state'] === 'normal', 'Aggregate zero is not an individual failed fan');
foreach ([str_replace('speed=3450', 'speed=0', $fixture), str_replace('Fail=0', 'Fail=1', $fixture), str_replace('status: OK', 'status: Critical', $fixture), preg_replace('/    element 3 descriptor:.*$/s', '', $fixture)] as $bad) {
    check(md12xx_parse_fan_telemetry($bad)['state'] === 'fault', 'Stopped, flagged or missing fan must fault');
}
$config = md12xx_defaults();
$cold = ['disk1'=>['temp'=>'30', 'spundown'=>'0'], 'disk2'=>['temp'=>'31', 'spundown'=>'0']];
check(!md12xx_commission_temperature_policy($config, $cold)['safe'], 'Numeric cached temperatures cannot authorize commissioning');
$unknown = $cold; $unknown['disk2']['temp'] = '*';
check(!md12xx_commission_temperature_policy($config, $unknown)['safe'], 'Partial unknown blocks commissioning');
check(md12xx_commission_temperature_policy($config, $unknown)['restoreSpeed'] >= 50, 'Unknown restores fail-safe');
$hot = $cold; $hot['disk2']['temp'] = '51';
check(md12xx_commission_temperature_policy($config, $hot)['restoreSpeed'] >= 50, 'Heating during test preserves demand');
check(!md12xx_commission_temperature_policy($config, [])['safe'], 'Empty disk inventory blocks commissioning');
$standby = ['disk1'=>['temp'=>'*', 'spundown'=>'1']];
$standbyPolicy = md12xx_commission_temperature_policy($config, $standby);
check(!$standbyPolicy['safe'] && $standbyPolicy['restoreSpeed'] === 50 && $standbyPolicy['activeDisks'] === 0,
    'Reported standby remains informational and cannot authorize commissioning');
$bounded = md12xx_validate_config(array_replace($config, ['sensorFailureSpeed'=>120]));
check(md12xx_commission_temperature_policy($bounded, $cold)['restoreSpeed'] === 100, 'Restore uses the validated failure-speed limit');
$manual = $config; $manual['mode'] = 'manual'; $manual['manualSpeed'] = 80;
check(md12xx_commission_temperature_policy($manual, $cold)['restoreSpeed'] === 80, 'Saved manual cooling preserved on abort');

$fixtureConfig = json_decode(file_get_contents($root . '/tests/fixtures/auto.json'), true);
$fixtureConfig['shelves'][0]['disks'] = ['disk1','disk2'];
file_put_contents($tmp . '/config.json', json_encode($fixtureConfig));
file_put_contents($tmp . '/shelf-1.txt', $fixture);
function cycle(string $root, string $tmp, string $disk2, ?int $mtime = null, bool $allStandby = false): array {
    $disk1 = $allStandby ? "temp=*\nspundown=1\n" : "temp=30\nspundown=0\n";
    file_put_contents($tmp . '/disks.ini', "[disk1]\nname=disk1\n" . $disk1 . "[ disk2 ]\nname=disk2\n" . $disk2);
    if ($mtime !== null) touch($tmp . '/disks.ini', $mtime);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/source/usr/local/emhttp/plugins/md12xx.fancontrol/include/controller.php') . ' --once --dry-run';
    foreach (['config'=>'config.json','disks'=>'disks.ini','state'=>'state.json','fixture-dir'=>''] as $key=>$file) $command .= ' --' . $key . '=' . escapeshellarg($file === '' ? $tmp : $tmp . '/' . $file);
    exec($command . ' 2>&1', $out, $code);
    check($code === 0, implode("\n", $out));
    return json_decode(file_get_contents($tmp . '/state.json'), true);
}
try {
    $s = cycle($root,$tmp,"temp=*\nspundown=0\n");
    check($s['shelves'][0]['targetPercent'] === 50 && $s['shelves'][0]['missingTemperatures'] === ['disk2'], 'Partial active temperature failure uses fail-safe');
    $s = cycle($root,$tmp,"temp=*\nspundown=1\n");
    check($s['shelves'][0]['targetPercent'] === 20 && $s['shelves'][0]['activeDisks'] === 1
        && $s['shelves'][0]['diskInputFreshness'] === 'unverified', 'Explicit native standby is excluded without a wake');
    $s = cycle($root, $tmp, "temp=54\ncolor=green-blink\n");
    check($s['shelves'][0]['targetPercent'] === 20 && $s['shelves'][0]['temperatureC'] === 30,
        'A numeric value on a native standby row must not feed the curve');
    $s = cycle($root, $tmp, "temp=46\ncolor=green-on\n");
    check($s['shelves'][0]['targetPercent'] === 30 && $s['shelves'][0]['temperatureSource'] === 'disk2',
        'Native active color supports a usable numeric value without a separate spin flag');
    foreach (["temp=*\n", "temp=26\n", "temp=26\nspundown=0\ncolor=green-blink\n",
        "temp=*\ncolor=red-blink\n", "temp=26\nspundown=maybe\ncolor=green-on\n"] as $ambiguous) {
        $s = cycle($root, $tmp, $ambiguous);
        check($s['shelves'][0]['targetPercent'] === 50 && str_contains($s['shelves'][0]['targetReason'], 'standby state unverified'),
            'Missing, conflicting or unhealthy state cannot authorize a curve target');
    }
    foreach (['current' => time(), 'aged' => time() - 86400] as $case => $mtime) {
        $s = cycle($root, $tmp, "temp=26\nspundown=0\nsampleTime=$mtime\n", $mtime);
        check($s['controller']['state'] === 'normal' && $s['shelves'][0]['temperatureC'] === 30
            && $s['shelves'][0]['diskInputFreshness'] === 'unverified' && $s['shelves'][0]['targetPercent'] === 20,
            "The $case file timestamp does not change the accepted best-available numeric policy");
        $s = cycle($root, $tmp, "temp=*\nspundown=1\nsampleTime=$mtime\n", $mtime, true);
        check($s['shelves'][0]['targetPercent'] === 50 && $s['shelves'][0]['reportedStandby'] === true
            && $s['shelves'][0]['diskInputFreshness'] === 'unverified', "Reported standby in the $case cache must fail safe");
    }
    $s = cycle($root, $tmp, "temp=1e1000\nspundown=0\n");
    check($s['shelves'][0]['temperatureC'] === 30 && $s['shelves'][0]['targetPercent'] === 50,
        'Invalid numeric report cannot prevent publishing the fail-safe state');
    $manualFixture = $fixtureConfig;
    $manualFixture['mode'] = 'manual';
    $manualFixture['manualSpeed'] = 30;
    file_put_contents($tmp . '/config.json', json_encode($manualFixture));
    $s = cycle($root, $tmp, "temp=*\nspundown=1\n");
    check($s['shelves'][0]['targetPercent'] === 30 && $s['shelves'][0]['targetReason'] === 'manual selection', 'Explicit Manual target is preserved');
    file_put_contents($tmp . '/config.json', json_encode($fixtureConfig));
    file_put_contents($tmp . '/shelf-1.txt', str_replace('speed=3450', 'speed=0', $fixture));
    $s = cycle($root,$tmp,"temp=31\nspundown=0\n");
    check($s['controller']['state'] === 'fault' && $s['shelves'][0]['writeState'] === 'fault' && $s['shelves'][0]['targetPercent'] >= 50, 'Fan failure is visible and uses Auto fail-safe cooling');
    $health = $root . '/source/usr/local/emhttp/plugins/md12xx.fancontrol/include/watchdog-health.php';
    foreach ([[['generatedAt'=>100,'controller'=>['state'=>'normal']],100,110,'normal'],[['generatedAt'=>100,'controller'=>['state'=>'fault']],100,110,'fault'],[['generatedAt'=>100,'controller'=>['state'=>'normal']],100,200,'fault'],[['generatedAt'=>99],100,110,'recovering']] as [$state,$started,$now,$expected]) {
        file_put_contents($tmp . '/health.json', json_encode($state));
        $value = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($health) . ' ' . escapeshellarg($tmp . '/health.json') . " $started $now");
        check($value === $expected, 'Watchdog progress/fault assessment');
    }
    echo "Thermal, fan failure and watchdog regressions passed.\n";
} finally { foreach (glob($tmp . '/*') as $file) unlink($file); rmdir($tmp); }
