<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/source/usr/local/emhttp/plugins/md12xx.fancontrol/include/common.php';
$root = dirname(__DIR__); $dir = sys_get_temp_dir() . '/mixed-curves-' . bin2hex(random_bytes(5)); mkdir($dir);
function mixed_check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
try {
    $config = json_decode(file_get_contents($root . '/tests/fixtures/auto.json'), true);
    $second = $config['shelves'][0]; $second['id'] = 'shelf-2'; $second['serialPort'] .= '-2';
    $second['sesDevice'] = '/dev/sg100'; $second['sesAddress'] = '0:0:100:0'; $second['disks'] = ['disk2'];
    $second['curveMode'] = 'individual'; $second['curve'] = [['temperatureC'=>0,'speed'=>60],['temperatureC'=>50,'speed'=>80]];
    $second['hysteresisC'] = 3;
    $config['shelves'][] = $second;
    $normalized = md12xx_validate_config($config);
    mixed_check($normalized['shelves'][0]['curveMode'] === 'global' && $normalized['shelves'][1]['curveMode'] === 'individual', 'Existing shelves default Global; individual choice retained');
    mixed_check(cooling_device_hysteresis($normalized['shelves'][0], 2.0) === 2.0 && cooling_device_hysteresis($normalized['shelves'][1], 2.0) === 3.0, 'Mixed devices choose Global or Individual hysteresis independently');
    mixed_check($normalized['shelves'][0]['hysteresisC'] === (float)$normalized['hysteresisC'], 'Legacy shelf downshift setting is preserved');
    mixed_check(cooling_curve_target($normalized['shelves'][1]['curve'], 48, 80, 3.0) === 80 && cooling_curve_target($normalized['shelves'][1]['curve'], 46, 80, 3.0) === 60, 'Shelf individual downshift hysteresis');
    file_put_contents($dir . '/config.json', json_encode($config));
    file_put_contents($dir . '/disks.ini', "[disk1]\nname=disk1\ndevice=sda\ntemp=46\nspundown=0\n[disk2]\nname=disk2\ndevice=sdb\ntemp=28\nspundown=0\n");
    foreach (['shelf-1','shelf-2'] as $id) copy($root . '/tests/fixtures/ses/shelf-1.txt', $dir . '/' . $id . '.txt');
    $command = 'php ' . escapeshellarg($root . '/source/usr/local/emhttp/plugins/md12xx.fancontrol/include/controller.php') . ' --once --dry-run --config=' . escapeshellarg($dir . '/config.json') . ' --disks=' . escapeshellarg($dir . '/disks.ini') . ' --fixture-dir=' . escapeshellarg($dir) . ' --state=' . escapeshellarg($dir . '/state.json');
    exec($command . ' 2>&1', $output, $code);
    mixed_check($code === 0, implode("\n", $output));
    $state = json_decode(file_get_contents($dir . '/state.json'), true);
    mixed_check($state['shelves'][0]['targetPercent'] === 30 && $state['shelves'][1]['targetPercent'] === 60, 'Mixed Global/Individual curves applied by the actual controller');
    $config['shelves'][1]['curveMode'] = 'global'; file_put_contents($dir . '/config.json', json_encode($config));
    exec($command . ' 2>&1', $output2, $code2);
    $state = json_decode(file_get_contents($dir . '/state.json'), true);
    mixed_check($code2 === 0 && $state['shelves'][1]['targetPercent'] === 20, 'Global curve uses the second shelf own cool disks');
    $normalized = md12xx_validate_config($config);
    mixed_check($normalized['shelves'][1]['curve'][0]['speed'] === 60, 'Individual curve survives a switch back to Global');
    mixed_check($normalized['shelves'][1]['hysteresisC'] === 3.0 && cooling_device_hysteresis($normalized['shelves'][1], 2.0) === 2.0, 'Individual hysteresis is retained while Global is active');
    $config['shelves'][1]['curveMode'] = 'individual';
    mixed_check(md12xx_commission_temperature_policy($config, ['disk1'=>['temp'=>'28','spundown'=>'0']])['restoreSpeed'] >= 60, 'Commissioning respects individual cooling demand');
} finally { foreach (glob($dir . '/*') as $path) unlink($path); rmdir($dir); }
echo "Mixed per-shelf curve controller regression tests passed.\n";
