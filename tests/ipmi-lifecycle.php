<?php
declare(strict_types=1);
// Run the actual controller and supervisor against a fake local IPMI executable.
// Only absolute path constants are relocated; no host device or fan command is used.
$source = dirname(__DIR__) . '/source/usr/local/emhttp/plugins/md12xx.fancontrol';
$root = sys_get_temp_dir() . '/ipmi-lifecycle-' . bin2hex(random_bytes(5));
foreach (['plugin/include','plugin/scripts','plugin/bin','state','config','cpu/cpu0/topology','cpu/cpu1/topology'] as $dir) mkdir($root . '/' . $dir, 0755, true);
function lifecycle_check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function lifecycle_wait(callable $predicate, string $why): void {
    $end = microtime(true) + 20;
    do { clearstatcache(); if ($predicate()) return; usleep(100000); } while (microtime(true) < $end);
    throw new RuntimeException($why);
}
function lifecycle_clean(string $dir): void { foreach (new FilesystemIterator($dir) as $p) { if ($p->isDir() && !$p->isLink()) lifecycle_clean($p->getPathname()); else unlink($p->getPathname()); } rmdir($dir); }
$map = ['/usr/local/emhttp/plugins/md12xx.fancontrol'=>$root.'/plugin','/var/run/md12xx.fancontrol'=>$root.'/state','/boot/config/plugins/md12xx.fancontrol'=>$root.'/config',
    '/sys/class/dmi/id/sys_vendor'=>$root.'/vendor','/sys/class/dmi/id/product_name'=>$root.'/model','/sys/class/dmi/id/product_uuid'=>$root.'/uuid',
    '/sys/devices/system/cpu'=>$root.'/cpu','/dev/ipmi0'=>$root.'/device','/dev/ipmi/0'=>$root.'/device'];
$process = null;
try {
    foreach (['ipmi.php','ipmi-controller.php','ipmi-recover.php','curves.php'] as $file) file_put_contents($root.'/plugin/include/'.$file, strtr(file_get_contents($source.'/include/'.$file), $map));
    file_put_contents($root.'/plugin/scripts/ipmi-supervisor.sh', strtr(file_get_contents($source.'/scripts/ipmi-supervisor.sh'), $map));
    file_put_contents($root.'/vendor','Dell Inc.'); file_put_contents($root.'/model','PowerEdge R730xd'); file_put_contents($root.'/uuid','12345678-1234-5678-1234-567812345678'); touch($root.'/device');
    file_put_contents($root.'/cpu/cpu0/topology/physical_package_id','0'); file_put_contents($root.'/cpu/cpu1/topology/physical_package_id','1');
    $sensors = "Inlet Temp | 04h | ok | 7.1 | 23 degrees C\nTemp | 0Eh | ok | 3.1 | 37 degrees C\nTemp | 0Fh | ok | 3.2 | 36 degrees C\n";
    for ($i=1; $i<=6; $i++) $sensors .= "Fan$i RPM | 30h | ok | 7.1 | 8640 RPM\n";
    file_put_contents($root.'/sensors', $sensors);
    $binary = "#!/bin/bash\nset -eu\nshift 2\nif [ \"\$1\" = sdr ]; then if [ -f " . escapeshellarg($root.'/query-failure') . " ]; then exit 124; fi; cat " . escapeshellarg($root.'/sensors') . "; else echo \"\$*\" >> " . escapeshellarg($root.'/commands') . "; if [ -f " . escapeshellarg($root.'/command-failure') . " ] && [ \"\$*\" = 'raw 0x30 0x30 0x01 0x00' ]; then exit 1; fi; fi\n";
    file_put_contents($root.'/plugin/bin/ipmitool.bin', $binary); chmod($root.'/plugin/bin/ipmitool.bin',0755);
    file_put_contents($root.'/config/ipmi.json', json_encode(['enabled'=>true,'mode'=>'manual','manualSpeed'=>80]));
    $pipes=[];
    $process=proc_open(['bash',$root.'/plugin/scripts/ipmi-supervisor.sh'], [0=>['pipe','r'],1=>['file',$root.'/output','a'],2=>['file',$root.'/output','a']],$pipes); fclose($pipes[0]);
    lifecycle_wait(fn()=>is_file($root.'/state/ipmi-status.json') && str_contains((string)@file_get_contents($root.'/commands'),'0x02 0xff 0x50'), 'Controller did not apply fake 80% target');
    lifecycle_check(is_file($root.'/config/ipmi-owned.json'), 'Persistent ownership was not recorded');
    $pid=(int)file_get_contents($root.'/state/ipmi-controller.pid'); lifecycle_check($pid>1,'Invalid test controller PID');
    exec('kill -KILL '.(int)$pid);
    lifecycle_wait(fn()=>str_contains((string)@file_get_contents($root.'/commands'),'0x01 0x01') && !is_file($root.'/config/ipmi-owned.json'), 'Supervisor did not restore firmware after controller death');
    lifecycle_check(is_file($root.'/state/ipmi-fault-latched'), 'Process loss did not latch control off');
    $supervisorFault=json_decode(file_get_contents($root.'/config/ipmi-last-fault.json'),true);
    lifecycle_check(str_contains($supervisorFault['reason'], 'Supervisor recovery'), 'Supervisor recovery cause was not preserved');
    $manualCount=substr_count(file_get_contents($root.'/commands'),'0x01 0x00');
    lifecycle_wait(fn()=>(int)@file_get_contents($root.'/state/ipmi-controller.pid') !== $pid, 'Controller did not restart after recovery');
    lifecycle_wait(fn()=>(json_decode((string)@file_get_contents($root.'/state/ipmi-status.json'),true)['mode']??'')==='firmware','Restart did not remain in firmware mode');
    lifecycle_check(substr_count(file_get_contents($root.'/commands'),'0x01 0x00')===$manualCount, 'Restart re-entered manual mode despite latch');
    file_put_contents($root.'/config/ipmi.json', json_encode(['enabled'=>true,'mode'=>'auto','curveMode'=>'individual','curve'=>[['temperatureC'=>0,'speed'=>15],['temperatureC'=>40,'speed'=>40]]]));
    unlink($root.'/state/ipmi-fault-latched');
    lifecycle_wait(fn()=>str_contains((string)@file_get_contents($root.'/commands'),'0x02 0xff 0x0f'), 'Controller did not apply IPMI individual 15% curve');
    foreach ([[40,40],[39,40],[38,15]] as [$temperature,$percent]) {
        file_put_contents($root.'/sensors', strtr($sensors, ['37 degrees C'=>$temperature.' degrees C','36 degrees C'=>($temperature-1).' degrees C']));
        lifecycle_wait(function () use ($root,$temperature,$percent): bool {
            $state=json_decode((string)@file_get_contents($root.'/state/ipmi-status.json'),true);
            return ($state['cpuMaxC']??null)===$temperature && ($state['targetPercent']??null)===$percent;
        }, 'Actual controller did not apply hysteresis transition');
    }
    file_put_contents($root.'/config/ipmi.json', json_encode(['enabled'=>true,'mode'=>'auto','curveMode'=>'individual','hysteresisC'=>0,'curve'=>[['temperatureC'=>0,'speed'=>15],['temperatureC'=>40,'speed'=>40]]]));
    file_put_contents($root.'/sensors', strtr($sensors, ['37 degrees C'=>'39 degrees C','36 degrees C'=>'38 degrees C']));
    lifecycle_wait(function () use ($root): bool {
        $state=json_decode((string)@file_get_contents($root.'/state/ipmi-status.json'),true);
        return ($state['cpuMaxC']??null)===39 && ($state['targetPercent']??null)===15 && ($state['effectiveHysteresisC']??null)===0;
    }, 'Changed hysteresis was not applied');
    file_put_contents($root.'/config/config.json', json_encode(['curve'=>[['temperatureC'=>0,'speed'=>20],['temperatureC'=>40,'speed'=>40]],'hysteresisC'=>3]));
    file_put_contents($root.'/config/ipmi.json', json_encode(['enabled'=>true,'mode'=>'auto','curveMode'=>'global','hysteresisC'=>0]));
    foreach ([[40,40],[38,40],[36,20]] as [$temperature,$percent]) {
        file_put_contents($root.'/sensors', strtr($sensors, ['37 degrees C'=>$temperature.' degrees C','36 degrees C'=>($temperature-1).' degrees C']));
        lifecycle_wait(function () use ($root,$temperature,$percent): bool {
            $state=json_decode((string)@file_get_contents($root.'/state/ipmi-status.json'),true);
            return ($state['cpuMaxC']??null)===$temperature && ($state['targetPercent']??null)===$percent && ($state['effectiveHysteresisC']??null)===3;
        }, 'Global hysteresis did not override the saved individual setting');
    }
    // A failed SDR command must hand back immediately and retain its first cause
    // after healthy polls return, rather than replacing it with generic pause text.
    touch($root.'/query-failure');
    lifecycle_wait(fn()=>is_file($root.'/state/ipmi-fault-latched'), 'Query failure did not latch');
    $first=file_get_contents($root.'/config/ipmi-last-fault.json');
    $fault=json_decode($first,true);
    lifecycle_check($fault['reason']==='Sensor query failed (exit 124)', 'SDR exit code was not preserved');
    lifecycle_check(($fault['evidence']['lastHealthy']['sensors']['healthy']??false)===true, 'Prior healthy sample missing');
    unlink($root.'/query-failure');
    lifecycle_wait(function()use($root):bool{$s=json_decode((string)@file_get_contents($root.'/state/ipmi-status.json'),true);return ($s['healthy']??false)&&($s['mode']??'')==='firmware'&&str_contains($s['targetReason']??'','Sensor query failed (exit 124)');}, 'Healthy polls lost the first fault reason');
    lifecycle_check(file_get_contents($root.'/config/ipmi-last-fault.json')===$first,'Healthy latched poll rewrote first fault');
    lifecycle_check((fileperms($root.'/config/ipmi-last-fault.json')&0777)===0600,'Persistent evidence must be private');
    // Deliberate fixture re-arm, then independent thermal and write failures.
    unlink($root.'/state/ipmi-fault-latched');
    file_put_contents($root.'/sensors',strtr($sensors,['37 degrees C'=>'70 degrees C']));
    lifecycle_wait(fn()=>is_file($root.'/state/ipmi-fault-latched'),'Thermal guard did not latch');
    $thermal=json_decode(file_get_contents($root.'/config/ipmi-last-fault.json'),true);
    lifecycle_check($thermal['reason']==='Thermal limit: returning control to iDRAC'&&$thermal['evidence']['sensors']['cpuMaxC']===70,'Thermal cause/readings lost');
    file_put_contents($root.'/sensors',$sensors);
    touch($root.'/command-failure'); unlink($root.'/state/ipmi-fault-latched');
    lifecycle_wait(fn()=>is_file($root.'/state/ipmi-fault-latched'),'Fan command failure did not latch');
    $write=json_decode(file_get_contents($root.'/config/ipmi-last-fault.json'),true);
    lifecycle_check($write['reason']==='Fan command failed; returned to iDRAC','Write failure cause lost');
    lifecycle_check(!is_file($root.'/config/ipmi-owned.json'),'Write failure did not restore firmware');
    echo "IPMI supervisor recovery, sensor/thermal/command fault evidence and latched restart passed.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); $end=microtime(true)+30; do { $status=proc_get_status($process); if (!$status['running']) break; usleep(100000); } while (microtime(true)<$end); proc_close($process); }
    lifecycle_clean($root);
}
