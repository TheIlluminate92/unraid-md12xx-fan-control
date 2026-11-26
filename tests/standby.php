<?php
declare(strict_types=1);

$project = dirname(__DIR__);
$controller = $project . '/source/usr/local/emhttp/plugins/md12xx.fancontrol/include/controller.php';
$root = sys_get_temp_dir() . '/shelf-standby-' . bin2hex(random_bytes(6));
mkdir($root);
function standby_check(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
}
function standby_clean(string $path): void {
    foreach (new FilesystemIterator($path) as $file) {
        if ($file->isDir() && !$file->isLink()) standby_clean($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($path);
}

$process = null;
try {
    $config = json_decode(file_get_contents($project . '/tests/fixtures/auto.json'), true);
    $baseShelf = $config['shelves'][0];
    $config['shelves'] = [];
    $inventory = [];
    foreach (['a', 'b'] as $index => $letter) {
        $number = $index + 1;
        $address = '5:0:20' . $number . ':0';
        $identity = '500c04f20000010' . $number;
        $shelf = array_replace($baseShelf, [
            'id' => 'shelf-' . $number, 'serialPort' => '/dev/serial/by-id/fixture-' . $letter,
            'sesAddress' => $address, 'sesDevice' => '/dev/sg20' . $number, 'sesIdentity' => $identity,
            'diskAssignment' => 'automatic', 'disks' => ['disk' . $number],
            'calibration' => ['rpmAt20' => 3500, 'rpmAt50' => 6300],
        ]);
        $config['shelves'][] = $shelf;
        $inventory[] = ['address'=>$address, 'device'=>$shelf['sesDevice'], 'vendor'=>'DELL', 'model'=>'MD1200', 'sesIdentity'=>$identity];
        mkdir($root . '/sys/class/enclosure/' . $address . '/Slot 00/device/block/sd' . $letter, 0755, true);
        copy($project . '/tests/fixtures/ses/shelf-1.txt', $root . '/' . $shelf['id'] . '.txt');
    }
    $config['shelves'][1]['curveMode'] = 'individual';
    $config['shelves'][1]['curve'] = [
        ['temperatureC'=>0,'speed'=>25], ['temperatureC'=>45,'speed'=>30], ['temperatureC'=>50,'speed'=>50],
    ];
    file_put_contents($root . '/ses-inventory.json', json_encode($inventory));
    file_put_contents($root . '/config.json', json_encode($config));
    $publish = static function (string $first, string $second) use ($root): void {
        file_put_contents($root . '/rows.next', "[disk1]\nname=disk1\ndevice=sda\n" . $first
            . "\n[disk2]\nname=disk2\ndevice=sdb\n" . $second . "\n");
        rename($root . '/rows.next', $root . '/rows.ini');
    };
    $read = static function (int $after) use ($root): array {
        $end = microtime(true) + 9;
        do {
            $state = json_decode((string) @file_get_contents($root . '/state.json'), true);
            if (($state['generatedAt'] ?? 0) > $after) return $state;
            usleep(100000);
        } while (microtime(true) < $end);
        throw new RuntimeException('No new fixture poll');
    };
    $standby = "spundown=1\ncolor=green-blink\ntemp=*";
    $publish($standby, "spundown=0\ncolor=green-on\ntemp=46");
    $arguments = [PHP_BINARY, $controller, '--dry-run', '--config=' . $root . '/config.json',
        '--disks=' . $root . '/rows.ini', '--fixture-dir=' . $root, '--state=' . $root . '/state.json'];
    $pipes = [];
    $process = proc_open($arguments, [0=>['pipe','r'],1=>['file',$root.'/controller.log','a'],2=>['file',$root.'/controller.log','a']], $pipes);
    standby_check(is_resource($process), 'Cannot start standby dry-run controller');
    fclose($pipes[0]);
    $first = $read(0);
    standby_check(array_column($first['shelves'], 'targetPercent') === [20,30],
        'Healthy all-standby shelf should select 20% independently of its active neighbor: '
            . json_encode(array_map(static fn(array $s): array => array_intersect_key($s,
                array_flip(['id','targetPercent','targetReason','diskMappingState','diskMappingMessage','telemetryState','verificationMode','writeState'])),
                $first['shelves'])));
    standby_check($first['shelves'][0]['reportedStandby'] && $first['shelves'][0]['diskInputFreshness'] === 'unverified'
        && str_contains($first['shelves'][0]['targetReason'], 'age unverified'), 'Standby target must disclose the report and provenance');

    $publish("spundown=0\ncolor=green-on\ntemp=55", $standby);
    $second = $read($first['generatedAt']);
    standby_check(array_column($second['shelves'], 'targetPercent') === [50,20],
        'A hot wake jumps to the active curve on the next poll while the other shelf can stay quiet');

    $publish($standby, "spundown=0\ncolor=green-on\ntemp=*");
    $third = $read($second['generatedAt']);
    standby_check(array_column($third['shelves'], 'targetPercent') === [20,50]
        && str_contains($third['shelves'][1]['targetReason'], 'active disk temperature unavailable'),
        'An active asterisk goes straight to fail-safe on the next poll');

    $fanFile = $root . '/shelf-1.txt';
    file_put_contents($fanFile, str_replace('speed=3450', 'speed=0', file_get_contents($fanFile)));
    $publish($standby, "spundown=0\ncolor=green-blink\ntemp=*");
    $fourth = $read($third['generatedAt']);
    standby_check(array_column($fourth['shelves'], 'targetPercent') === [50,50]
        && $fourth['shelves'][0]['writeState'] === 'fault'
        && $fourth['shelves'][1]['diskPowerStateUnverified'],
        'A failed fan and conflicting standby indicators independently deny 20%');
    copy($project . '/tests/fixtures/ses/shelf-1.txt', $fanFile);
    mkdir($root . '/sys/class/enclosure/5:0:201:0/Slot 01/device/block/sdc', 0755, true);
    rmdir($root . '/sys/class/enclosure/5:0:202:0/Slot 00/device/block/sdb');
    $publish($standby, $standby);
    $fifth = $read($fourth['generatedAt']);
    standby_check(array_column($fifth['shelves'], 'targetPercent') === [50,50]
        && $fifth['shelves'][0]['diskMappingState'] === 'partial' && $fifth['shelves'][1]['diskMappingState'] !== 'verified',
        'Partial or unavailable current topology keeps the fail-safe even with standby rows');

    echo "MD1200 standby quiet mode, independent shelves, wake transitions and safety gates passed.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    standby_clean($root);
}
