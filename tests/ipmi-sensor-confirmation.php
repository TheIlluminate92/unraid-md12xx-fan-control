<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/source/usr/local/emhttp/plugins/md12xx.fancontrol/include/ipmi.php';
$count = 0;
function check(bool $ok, string $reason): void { global $count; $count++; if (!$ok) throw new RuntimeException($reason); }
$fixture = "Inlet Temp | 04h | ok | 7.1 | 23 degrees C\nTemp | 0Eh | ok | 3.1 | 43 degrees C\nTemp | 0Fh | ok | 3.2 | 42 degrees C\n";
for ($i=1; $i<=6; $i++) $fixture .= "Fan$i RPM | 3{$i}h | ok | 7.1 | 6360 RPM\n";
$good = cooling_ipmi_sensors($fixture, 2);
$missing = cooling_ipmi_sensors(str_replace("Temp | 0Fh | ok | 3.2 | 42 degrees C\n", '', $fixture), 2);
check($good['healthy'] && $good['cpuTemperaturesC'] === [43.0,42.0], 'Same CPU name with distinct entities is valid');
check(!$missing['healthy'] && $missing['cpuTemperaturesC'] === [43.0], 'Missing CPU does not inherit prior value');
check($missing['diagnostics']['acceptedCpuEntities'] === ['3.1'], 'Fault diagnostics preserve accepted identity');
check(count($missing['diagnostics']['rows']) === 8, 'Only relevant sensor rows retained');
$first = cooling_ipmi_sensor_guard([], $missing, true, 0);
check($first['latch'] && !$first['pending'], 'Incomplete startup has no trusted basis for grace');
$start = cooling_ipmi_sensor_guard([], $good, true, 0);
check(!$start['latch'] && !$start['pending'], 'Fresh complete startup remains allowed');
$one = cooling_ipmi_sensor_guard($start['state'], $missing, true, 7);
check($one['pending'] && !$one['latch'], 'Single missing response yields confirmation, not permanent latch');
$beforeMinute = cooling_ipmi_sensor_guard($one['state'] + ['handbackAt' => 9.0], $good, true, 68.0);
check($beforeMinute['pending'] && !$beforeMinute['latch'] && empty($beforeMinute['state']['healthyStreak']),
    'The 60-second hold is measured from acknowledged iDRAC handback');
$config = cooling_ipmi_config(['enabled'=>true,'mode'=>'auto']);
check(cooling_ipmi_target($config, $missing)['mode'] === 'firmware', 'Incomplete telemetry immediately selects firmware');
$held = $one['state'] + ['handbackAt' => 9.0];
$recovery1 = cooling_ipmi_sensor_guard($held, $good, true, 69);
check($recovery1['pending'] && !$recovery1['latch'], 'First recovered sample stays on firmware');
$tooSoon = cooling_ipmi_sensor_guard($recovery1['state'], $good, true, 70);
check($tooSoon['pending'], 'Fast duplicate samples cannot complete recovery');
$recovery2 = cooling_ipmi_sensor_guard($tooSoon['state'], $good, true, 74);
check(!$recovery2['pending'] && !$recovery2['latch'], 'Two fresh samples separated by five seconds allow recovery');
check(!isset($recovery2['state']['pendingSince']), 'Incident closes after confirmation');
$second = cooling_ipmi_sensor_guard($one['state'], $missing, true, 14);
check($second['pending'], 'Second incomplete sample remains on firmware');
$third = cooling_ipmi_sensor_guard($second['state'], $missing, true, 21);
check($third['latch'], 'Third incomplete sample requires manual intervention');
$deadline = cooling_ipmi_sensor_guard($recovery1['state'], $good, true, 99);
check($deadline['latch'], 'Complete sample at the 90-second deadline cannot forgive expired incident');
$staleHistory = cooling_ipmi_sensor_guard($start['state'], $missing, true, 16);
check($staleHistory['latch'], 'Old trusted history cannot open grace');
$stale = cooling_ipmi_sensor_guard($start['state'], $good, false, 7);
check($stale['pending'] && !$stale['latch'], 'Slow or failed collection is untrusted even if values look complete');
$stillStale = cooling_ipmi_sensor_guard($stale['state'], $good, false, 14);
check($stillStale['pending'], 'Stale complete-looking data never counts as recovery');
$stale3 = cooling_ipmi_sensor_guard($stillStale['state'], $good, false, 21);
check($stale3['latch'], 'Repeated stale data latches');
foreach ([['cpuMaxC'=>70],['inletC'=>35],['observedThermal'=>true]] as $hot) {
 $d=cooling_ipmi_sensor_guard($one['state'],array_replace($missing,$hot),true,8);
 check($d['latch'] && !$d['pending'],'Thermal observation interrupts confirmation immediately');
}
$hotPartial=cooling_ipmi_sensors(str_replace('43 degrees C','70 degrees C',str_replace("Temp | 0Fh | ok | 3.2 | 42 degrees C\n",'',$fixture)),2);
check(!$hotPartial['healthy'] && $hotPartial['observedThermal'],'Observed heat survives partial response');
$nonOkHot=cooling_ipmi_sensors(str_replace('0Eh | ok','0Eh | cr',str_replace('43 degrees C','70 degrees C',$fixture)),2);
check(!$nonOkHot['healthy'] && $nonOkHot['observedThermal'],'Hot non-ok row is never discarded by thermal guard');
foreach ([str_replace('3.2','3.1',$fixture),str_replace('6360 RPM','0 RPM',$fixture),str_replace('43 degrees C','121 degrees C',$fixture)] as $bad) {
 $s=cooling_ipmi_sensors($bad,2);$d=cooling_ipmi_sensor_guard($start['state'],$s,true,7);
 check($d['latch'],'Ambiguous identity, zero fan or invalid high CPU cannot use grace');
}
// A fresh fault during recovery resets the consecutive-good requirement without moving the deadline.
$again=cooling_ipmi_sensor_guard($recovery1['state'],$missing,true,73);
check($again['pending'] && $again['state']['healthyStreak']===0 && $again['state']['pendingSince']===7.0,'Recovery interruption never extends original deadline');
$secondEpisode=cooling_ipmi_sensor_guard($recovery2['state'],$missing,true,79);
check($secondEpisode['latch'],'A repeat incident within five minutes cannot auto-recover');
check(cooling_ipmi_sensor_guard($start['state'],$missing,true,7,false)['latch'], 'Manual or unowned cooling cannot automatically re-arm');
$changed=cooling_ipmi_sensors(str_replace('0Fh','10h',$fixture),2);
check(cooling_ipmi_sensor_guard($recovery1['state'],$changed,true,74)['latch'], 'A changed CPU sensor identity cannot confirm recovery');
$refreshed=cooling_ipmi_sensor_guard($recovery2['state'],$good,true,376);
$later=cooling_ipmi_sensor_guard($refreshed['state'],$missing,true,383);
check($later['pending'] && count($later['state']['episodes'])===1,'Expired incidents leave bounded rolling window');
check(cooling_ipmi_sensor_guard([],$missing,true,8)['latch'],'Restart cannot reuse volatile grace or stale trusted sample');
check(cooling_ipmi_sensor_guard($one['state'],$good,true,6)['latch'],'Backwards clock fails closed');
check(cooling_ipmi_sensor_guard([],$good,true,INF)['latch'],'Nonfinite clock fails closed');
check(cooling_ipmi_sensor_guard([],$good,true,-1)['latch'],'Negative clock fails closed');
check(!$good['observedThermal'] && !$good['hardFault'],'Normal fixture remains healthy');
// Revision-two regressions: ambiguity, total collection budget and persistent incident history.
foreach (["Inlet Temp | 05h | ok | 7.2 | 22 degrees C\n", "Inlet Temp | 04h | ok | 7.1 | 23 degrees C\n", "Inlet Temp | 05h | ns | 7.2 | No Reading\n"] as $inlet) {
 $ambiguous=cooling_ipmi_sensors($fixture.$inlet,2);
 check($ambiguous['hardFault'] && !cooling_ipmi_sensor_guard($start['state'],$ambiguous,true,7)['pending'], 'Multiple inlet rows fail closed regardless of reading/status/identity');
}
$duplicateCpuId=cooling_ipmi_sensors(str_replace('0Fh','0Eh',$fixture),2);
check($duplicateCpuId['hardFault'],'Repeated CPU sensor ID with different entity is ambiguous');
check(cooling_ipmi_read_budget(17.0,20.0)===3.0,'Read-only call receives only remaining deadline');
check(cooling_ipmi_read_budget(20.0,20.0)===0.0,'No collection starts at deadline');
check(cooling_ipmi_read_budget(21.0,20.0)===0.0,'Expired deadline cannot restart fifteen-second timeout');
check(cooling_ipmi_read_budget(0.0,null)===15.0,'Non-pending calls retain total fifteen-second bound');
check(cooling_ipmi_read_budget(0.0,100.0)===15.0,'Far deadline cannot exceed per-command maximum');
check(cooling_ipmi_read_budget(NAN,20.0)===0.0,'Invalid clock yields zero command budget');
check(cooling_ipmi_read_budget(1.0,INF)===0.0,'Invalid deadline yields zero command budget');
$history=cooling_ipmi_incident_history(['schema'=>1,'at'=>42,'episodes'=>[7,28],'trustedAt'=>42,'pendingSince'=>28],45.0);
check($history===['episodes'=>[7.0,28.0]],'Restart restores episodes only, never trusted samples or pending authority');
$restartGood=cooling_ipmi_sensor_guard($history,$good,true,45);
$restartThird=cooling_ipmi_sensor_guard($restartGood['state'],$missing,true,49);
check($restartThird['latch'],'Incident budget survives restart history reload');
foreach ([[],['schema'=>1,'at'=>100,'episodes'=>[]],['schema'=>1,'at'=>40,'episodes'=>[7,6]],['schema'=>1,'at'=>40,'episodes'=>[50]],['schema'=>1,'at'=>40,'episodes'=>[NAN]]] as $record) {
 $threw=false;try{cooling_ipmi_incident_history($record,45.0);}catch(RuntimeException $e){$threw=true;}
 check($threw,'Malformed/future/reversed persisted history fails closed');
}
$expiredHistory=cooling_ipmi_incident_history(['schema'=>1,'at'=>42,'episodes'=>[7,28]],400.0);
check($expiredHistory['episodes']===[],'Expired persistent episodes cannot expand or revive the rolling window');
// A normal exit report is accepted only with process-group cleanup acknowledgement.
check(cooling_ipmi_runner_exit(9,"cooling-ipmi-exit:0\n")===0,'Successful group cleanup preserves tool success');
check(cooling_ipmi_runner_exit(9,"cooling-ipmi-exit:124\n")===124,'Timeout status preserved after group cleanup');
check(cooling_ipmi_runner_exit(125,"cooling-ipmi-exit:0\n")===null,'Cleanup failure cannot report tool success');
check(cooling_ipmi_runner_exit(0,"cooling-ipmi-exit:0\n")===null,'Normal wrapper exit without group kill is rejected');
check(cooling_ipmi_runner_exit(9,'')===null,'Missing private status report fails closed');
check(cooling_ipmi_runner_exit(9,"cooling-ipmi-exit:999\n")===null,'Invalid child status rejected');
check(cooling_ipmi_runner_exit(9,"cooling-ipmi-exit:0\nextra")===null,'Trailing/forged protocol bytes rejected');
check(cooling_ipmi_runner_exit(9,"cooling-ipmi-exit:0\ncooling-ipmi-exit:0\n")===null,'Multiple private reports rejected');
check(cooling_ipmi_runner_exit(9,"cooling-ipmi-exit:255\n")===255,'Maximum valid failure status remains failure');
check(cooling_ipmi_runner_exit(137,"cooling-ipmi-exit:0\n")===null,'Shell-style137 is not the explicit util-linux monitor contract');
// No filesystem, subprocess, fan command or latch clearing in these policy tests.
echo "Sensor confirmation tests passed: $count assertions.\n";
