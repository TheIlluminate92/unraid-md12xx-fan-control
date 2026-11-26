<?php
declare(strict_types=1);
// Isolated Linux process-tree test only. Never invoke a real IPMI tool/device.
require_once dirname(__DIR__).'/source/usr/local/emhttp/plugins/md12xx.fancontrol/include/ipmi.php';
$wrapper=dirname(__DIR__).'/source/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/ipmi-runner.sh';
$root=sys_get_temp_dir().'/ipmi-tree-'.bin2hex(random_bytes(6));mkdir($root,0700);
function tree_active(int $pid): bool {
    $s=(string)@file_get_contents('/proc/'.$pid.'/stat');
    if ($s==='') return false;
    $end=strrpos($s,')');return $end!==false && !in_array(substr($s,$end+2,1),['Z','X'],true);
}
function tree_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try {
 foreach(['term-parent-exits','normal-parent-exits','term-parent-resists'] as $case) {
  $pidfile=$root.'/'.$case.'.pid';$fake=$root.'/'.$case.'.sh';
  $parent=$case==='term-parent-resists' ? "trap '' TERM\n" : "trap 'exit 0' TERM\n";
  $body="#!/bin/sh\n".$parent.'/bin/sh -c '.escapeshellarg('trap "" TERM; while :; do sleep 60; done')." &\necho \$! > ".escapeshellarg($pidfile)."\n";
  $body.=$case==='normal-parent-exits' ? "exit 0\n" : "while :; do sleep 60; done\n";
  file_put_contents($fake,$body);
  $pipes=[];$started=microtime(true);
  $proc=proc_open(['/usr/bin/setsid','--fork','--wait','/bin/sh',$wrapper,'0.75s','/bin/sh',$fake],
      [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],3=>['pipe','w']],$pipes);
  tree_check(is_resource($proc),'Could not start isolated wrapper');fclose($pipes[0]);
  foreach([1,2,3] as $n)stream_set_blocking($pipes[$n],false);
  $report='';$out='';
  do {
   foreach([1,2,3] as $n){$chunk=stream_get_contents($pipes[$n]);if($n===3)$report.=$chunk;else$out.=$chunk;}
   $status=proc_get_status($proc);
   if(microtime(true)-$started>3){proc_terminate($proc,9);throw new RuntimeException('Wrapper exceeded bounded test deadline');}
   if($status['running'])usleep(10000);
  }while($status['running']);
  foreach([1,2,3] as $n){$chunk=stream_get_contents($pipes[$n]);if($n===3)$report.=$chunk;fclose($pipes[$n]);}
  $closed=proc_close($proc);$exit=$status['exitcode']>=0?$status['exitcode']:$closed;
  tree_check(!$status['signaled'] && $exit===9,'Explicit setsid monitor did not report signal9 normally');
  $tool=cooling_ipmi_runner_exit($exit,$report);
  tree_check($tool!==null,'Group cleanup/status protocol not confirmed: '.$case.' '.$out);
  tree_check(microtime(true)-$started<2,'Group cleanup exceeded test budget: '.$case);
  $pid=(int)@file_get_contents($pidfile);tree_check($pid>1,'Synthetic child PID missing');
  $until=microtime(true)+0.5;while(tree_active($pid)&&microtime(true)<$until)usleep(10000);
  if(tree_active($pid)){posix_kill($pid,9);throw new RuntimeException('TERM-resistant child survived group cleanup: '.$case);}
  echo $case.': group cleanup passed, exit='.$tool."\n";
 }
}finally{foreach(glob($root.'/*')?:[] as $f)unlink($f);rmdir($root);}
