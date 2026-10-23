<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/source/usr/local/emhttp/plugins/md12xx.fancontrol/include/ipmi.php';
function ipmi_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$fixture = "Inlet Temp | 04h | ok | 7.1 | 23 degrees C\nTemp | 0Eh | ok | 3.1 | 37 degrees C\nTemp | 0Fh | ok | 3.2 | 36 degrees C\n";
for ($i = 1; $i <= 6; $i++) $fixture .= "Fan$i RPM | 30h | ok | 7.1 | 8640 RPM\n";
$sensors = cooling_ipmi_sensors($fixture, 2);
ipmi_check($sensors['healthy'] && $sensors['cpuMaxC'] === 37.0 && count($sensors['fanRpms']) === 6, 'R730 sensor format');
ipmi_check(!cooling_ipmi_sensors(str_replace('Fan6 RPM', 'Unknown', $fixture), 2)['healthy'], 'Missing fan fails closed');
ipmi_check(!cooling_ipmi_sensors(str_replace('8640 RPM', '0 RPM', $fixture), 2)['healthy'], 'Stopped fan fails closed');
ipmi_check(!cooling_ipmi_sensors(str_replace('37 degrees C', 'No Reading', $fixture), 2)['healthy'], 'Missing CPU temperature fails closed');
ipmi_check(!cooling_ipmi_sensors(str_replace('3.2', '3.1', $fixture), 2)['healthy'], 'Duplicate CPU sensors do not satisfy CPU count');
ipmi_check(cooling_ipmi_profile('Dell Inc.', 'PowerEdge R730xd') && !cooling_ipmi_profile('Dell Inc.', 'PowerEdge R360'), 'Hardware allowlist');
foreach ([['manualSpeed' => 19], ['manualSpeed' => 101], ['manualSpeed' => 25], ['mode' => 'raw'], ['curveMode' => 'invalid'], ['enabled' => 'true']] as $bad) {
    try { cooling_ipmi_config($bad); throw new RuntimeException('Invalid config accepted'); } catch (InvalidArgumentException $expected) {}
}
$global = [['temperatureC' => 0, 'speed' => 20], ['temperatureC' => 35, 'speed' => 30], ['temperatureC' => 50, 'speed' => 60]];
$individual = [['temperatureC' => 0, 'speed' => 40], ['temperatureC' => 50, 'speed' => 80]];
$config = cooling_ipmi_config(['enabled' => true, 'mode' => 'auto', 'curveMode' => 'individual', 'curve' => $individual]);
$quiet = cooling_ipmi_config(['enabled'=>true, 'mode'=>'auto', 'curveMode'=>'individual', 'curve'=>[
    ['temperatureC'=>0, 'speed'=>15], ['temperatureC'=>32, 'speed'=>20],
    ['temperatureC'=>35, 'speed'=>25], ['temperatureC'=>37, 'speed'=>30],
    ['temperatureC'=>39, 'speed'=>35], ['temperatureC'=>40, 'speed'=>40]
]]);
$quiet['effectiveCurve'] = cooling_device_curve($quiet, $global, 15);
foreach ([30=>15, 32=>20, 35=>25, 37=>30, 39=>35, 40=>40, 69=>40] as $temp=>$speed) {
    ipmi_check(cooling_ipmi_target($quiet, array_replace($sensors, ['cpuMaxC'=>$temp]))['percent'] === $speed, 'Individual IPMI 15–40% curve');
}
ipmi_check(cooling_ipmi_target($quiet, array_replace($sensors, ['cpuMaxC'=>70]))['mode'] === 'firmware', 'Configured maximum never disables emergency firmware handback');
try { cooling_validate_curve($quiet['curve']); throw new RuntimeException('Shelf accepted IPMI-only minimum'); } catch (InvalidArgumentException $expected) {}
$tooLow = $quiet; $tooLow['curve'][0]['speed'] = 14;
try { cooling_ipmi_config($tooLow); throw new RuntimeException('IPMI accepted speed below 15%'); } catch (InvalidArgumentException $expected) {}
try { cooling_ipmi_run(['raw','0x30','0x30','0x02','0xff','0x0e']); throw new RuntimeException('Raw speed below 15% accepted'); } catch (InvalidArgumentException $expected) {}
$config['effectiveCurve'] = cooling_device_curve($config, $global);
ipmi_check(cooling_ipmi_target($config, $sensors)['percent'] === 40, 'Individual chassis curve');
$config['curveMode'] = 'global'; $config['effectiveCurve'] = cooling_device_curve($config, $global);
ipmi_check(cooling_ipmi_target($config, $sensors)['percent'] === 30, 'Global curve with chassis temperature');
ipmi_check(cooling_curve_speed($global, 28) === 20 && cooling_curve_speed($global, 37) === 30, 'Global curve keeps per-device temperatures independent');
foreach ([array_replace($sensors, ['cpuMaxC' => 70]), array_replace($sensors, ['inletC' => 35]), array_replace($sensors, ['healthy' => false])] as $fault) ipmi_check(cooling_ipmi_target($config, $fault)['mode'] === 'firmware', 'Thermal/fan/sensor guard returns to firmware');
$temp = sys_get_temp_dir() . '/ipmi-tests-' . bin2hex(random_bytes(5)); mkdir($temp);
$lease = $temp . '/owned.json'; $calls = [];
$run = function (array $args) use (&$calls): array { $calls[] = $args; return ['code' => 0, 'output' => '']; };
try {
    ipmi_check(cooling_ipmi_restore($run, $lease) && !$calls, 'Unowned firmware is never changed');
    $target = ['mode' => 'manual', 'percent' => 60, 'reason' => 'test'];
    ipmi_check(cooling_ipmi_apply($target, $run, $lease)['ok'] && count($calls) === 2, 'Manual entry precedes exact percentage');
    ipmi_check($calls[1] === ['raw','0x30','0x30','0x02','0xff','0x3c'], 'Correct 60% encoding');
    ipmi_check(is_file($lease), 'Ownership survives process loss');
    ipmi_check(cooling_ipmi_restore($run, $lease) && !is_file($lease), 'Firmware restore clears ownership only after acknowledgement');
    $calls = [];
    ipmi_check(cooling_ipmi_apply(['mode'=>'auto','percent'=>15,'reason'=>'test'], $run, $lease)['ok'], '15% target reaches command layer');
    ipmi_check($calls[1] === ['raw','0x30','0x30','0x02','0xff','0x0f'], 'Correct 15% encoding');
    ipmi_check(cooling_ipmi_restore($run, $lease), 'Restore after 15% simulated command');
    $sequence = 0;
    $failed = function (array $args) use (&$sequence): array { return ['code' => ++$sequence === 2 ? 1 : 0, 'output' => '']; };
    $result = cooling_ipmi_apply($target, $failed, $lease);
    ipmi_check(!$result['ok'] && $result['mode'] === 'firmware' && !is_file($lease) && $sequence === 3, 'Failed speed command immediately restores firmware');
    $result = cooling_ipmi_apply($target, static fn(array $args): array => ['code'=>1,'output'=>''], $lease);
    ipmi_check(!$result['ok'] && $result['mode'] === 'unknown' && is_file($lease), 'Failed recovery retains ownership for retry');
    cooling_ipmi_store($lease, ['host'=>'different chassis']); $calls = [];
    ipmi_check(!cooling_ipmi_restore($run, $lease) && !$calls, 'Copied ownership cannot write to another chassis');
    try { cooling_ipmi_run(['chassis', 'power', 'off']); throw new RuntimeException('Arbitrary command accepted'); } catch (InvalidArgumentException $expected) {}
} finally { foreach (glob($temp . '/*') as $path) unlink($path); rmdir($temp); }
echo "IPMI telemetry, mixed curves, command sequencing and recovery tests passed.\n";
