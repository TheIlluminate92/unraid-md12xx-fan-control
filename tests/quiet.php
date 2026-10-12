<?php
declare(strict_types=1);
require $argv[1];
function ok(bool $pass, string $message): void { if (!$pass) throw new RuntimeException($message); }
$config=md12xx_validate_config(['enabled'=>true,'mode'=>'auto','startupRecovery35'=>true,'shelves'=>[
 ['id'=>'one','serialPort'=>'/dev/serial/by-id/quiet-one','enabled'=>true,'commissioned'=>true,'model'=>'MD1200','sesIdentity'=>'500c04f200000100','disks'=>['disk1'],'calibration'=>['rpmAt20'=>3500,'rpmAt50'=>6300]],
 ['id'=>'two','serialPort'=>'/dev/serial/by-id/quiet-two','enabled'=>true,'commissioned'=>true,'model'=>'MD1200','sesIdentity'=>'500c04f200000200','disks'=>['disk2'],'calibration'=>['rpmAt20'=>3500,'rpmAt50'=>6300]],
]]);
$cold=['disk1'=>['temp'=>'30','spundown'=>'0'],'disk2'=>['temp'=>'31','spundown'=>'0']];
ok(md12xx_quiet_temperature_policy($config,$cold)['safe'], 'Cold commissioned shelves permit recovery');
$hot=$cold; $hot['disk2']['temp']='46';
$policy=md12xx_quiet_temperature_policy($config,$hot);
ok(!$policy['safe'] && $policy['restoreSpeed']>=30, 'Hot peer blocks test and preserves required cooling');
$missing=$cold; $missing['disk1']['temp']='*';
$policy=md12xx_quiet_temperature_policy($config,$missing);
ok(!$policy['safe'] && $policy['restoreSpeed']>=50, 'Missing active temperature cannot be masked by peer');
$missing=$cold;unset($missing['disk2']);
ok(!md12xx_quiet_temperature_policy($config,$missing)['safe'], 'Missing assigned disk blocks test');
$opt=$config; $opt['startupRecovery35']=false;
ok(!md12xx_quiet_temperature_policy($opt,$cold)['safe'], 'Opt-out blocks test');
$manual=$config;$manual['mode']='manual';$manual['manualSpeed']=50;
$policy=md12xx_quiet_temperature_policy($manual,$cold);
ok(!$policy['safe'] && $policy['restoreSpeed']>=50, 'Manual high target is never lowered');
$legacy=$config;$legacy['shelves'][0]['sesIdentity']='';
ok(!md12xx_quiet_temperature_policy($legacy,$cold)['safe'], 'Legacy identity cannot be adopted by quiet recovery');
$unproven=$config;$unproven['shelves'][0]['commissioned']=false;
ok(!md12xx_quiet_temperature_policy($unproven,$cold)['safe'], 'Uncommissioned shelf cannot auto-ramp');
$asleep=$cold;$asleep['disk1']=['temp'=>'*','spundown'=>'1'];
ok(md12xx_quiet_temperature_policy($config,$asleep)['safe'], 'Known sleeping disk does not require a temperature');
$empty=$config;$empty['shelves']=[];
ok(md12xx_quiet_temperature_policy($empty,[])['restoreSpeed']>=50, 'Missing configuration restores conservative cooling');
$baseline=[['model'=>'MD1200','disks'=>['disk1'],'fanCount'=>4,'averageRpm'=>3500],['model'=>'MD1200','disks'=>['disk2'],'fanCount'=>4,'averageRpm'=>3480]];
ok(md12xx_quiet_baseline_valid($config,$baseline), 'Proven 20% baseline and exact disk mapping');
$fanFault=$baseline;$fanFault[0]['fanCount']=3;
ok(!md12xx_quiet_baseline_valid($config,$fanFault), 'Missing fan telemetry blocks quiet recovery');
$fast=$baseline;$fast[1]['averageRpm']=6300;
ok(!md12xx_quiet_baseline_valid($config,$fast), 'A shelf already running faster is never lowered');
ok(!md12xx_quiet_baseline_valid($config,[$baseline[0],$baseline[0]]), 'Duplicate mapping is ambiguous');
$unknown=$baseline;$unknown[]=['model'=>'MD1200','disks'=>['disk3'],'fanCount'=>4,'averageRpm'=>3500];
ok(!md12xx_quiet_baseline_valid($config,$unknown), 'Unconfigured enclosure blocks automatic probe');
echo "Quiet recovery eligibility and baseline tests passed.\n";
