<?php
declare(strict_types=1);
require $argv[1];
$work = $argv[2];
$project = $argv[3];
function check(bool $passed, string $message): void {
    if (!$passed) throw new RuntimeException($message);
}
function cf(string $id): string {
    return "Configuration diagnostic page:\n  Subenclosure identifier: 0 [primary]\n    enclosure logical identifier (hex): " . $id . "\n  type descriptor header and text list:\n";
}
$a = '500c04f200000100';
$b = '500c04f200000200';
check(md12xx_parse_ses_identity(cf(strtoupper($a))) === $a, 'Normalize primary identity');
check(md12xx_parse_ses_identity(cf('0000000000000000')) === '', 'Reject zero identity');
check(md12xx_parse_ses_identity(cf('ffffffffffffffff')) === '', 'Reject placeholder identity');
check(md12xx_parse_ses_identity(cf($a . '0')) === '', 'Reject oversized identity');
check(md12xx_parse_ses_identity('enclosure logical identifier (hex): ' . $a) === '', 'Require primary descriptor');
check(md12xx_parse_ses_identity("Subenclosure identifier: 0 [primary]\n enclosure logical identifier (hex): $a\n enclosure logical identifier (hex): $b") === '', 'Reject conflicting primary identities');
check(md12xx_parse_ses_identity("Subenclosure identifier: 0 [primary]\n enclosure logical identifier (hex): $a\nSubenclosure identifier: 1 [secondary]\n enclosure logical identifier (hex): $b") === $a, 'Ignore secondary identity');

$config = md12xx_read_config($project . '/tests/fixtures/auto.json');
$config['shelves'][0]['sesIdentity'] = $a;
$config['shelves'][0]['diskAssignment'] = 'automatic';
$validated = md12xx_validate_config($config);
check($validated['shelves'][0]['sesIdentity'] === $a, 'Identity survives validation');
$draft = $validated;
$draft['shelves'][0]['sesIdentity'] = $b;
$merged = md12xx_validate_config(md12xx_merge_settings_config($validated, $draft));
check($merged['shelves'][0]['sesIdentity'] === $a, 'Browser cannot replace automatic identity');
$draft['shelves'][0]['serialPort'] = '/dev/serial/by-id/new-fixture';
$merged = md12xx_validate_config(md12xx_merge_settings_config($validated, $draft));
check($merged['shelves'][0]['sesIdentity'] === '' && !$merged['shelves'][0]['commissioned'], 'Changed adapter clears pairing');
$manual = $validated;
$manual['shelves'][0]['diskAssignment'] = 'manual';
$draft = $manual;
$draft['shelves'][0]['sesIdentity'] = $b;
check(md12xx_merge_settings_config($manual, $draft)['shelves'][0]['sesIdentity'] === $a, 'Manual identity stays server authoritative');
$new = $validated;
$new['shelves'][0]['id'] = 'new-shelf';
check(md12xx_merge_settings_config($validated, $new)['shelves'][0]['sesIdentity'] === '', 'New shelf cannot inject identity');
$duplicate = $validated;
$other = $duplicate['shelves'][0];
$other['id'] = 'shelf-other';
$other['serialPort'] = '/dev/serial/by-id/other-fixture';
$other['sesDevice'] = '/dev/sg201';
$other['sesAddress'] = '5:0:201:0';
$other['disks'] = [];
$duplicate['shelves'][] = $other;
try { md12xx_validate_config($duplicate); throw new RuntimeException('Duplicate identity accepted'); }
catch (InvalidArgumentException $expected) {}

// Historical path hints may collide after one shelf moves; physical identity remains unique.
$hints = $duplicate;
$hints['shelves'][1]['sesIdentity'] = $b;
$hints['shelves'][1]['sesDevice'] = $hints['shelves'][0]['sesDevice'];
$hints['shelves'][1]['sesAddress'] = $hints['shelves'][0]['sesAddress'];
check(count(md12xx_validate_config($hints)['shelves']) === 2, 'Historical hints cannot invalidate distinct identities');
$hints['shelves'][1]['sesIdentity'] = '';
try { md12xx_validate_config($hints); throw new RuntimeException('Legacy duplicate path accepted'); }
catch (InvalidArgumentException $expected) {}

$fixture = $work . '/identity-fixture';
$sys = $fixture . '/sys';
$expander = $sys . '/devices/host5/port-5:2/expander-5:2';
$enclosure = $expander . '/target5:0:200/5:0:200:0';
$disk = $expander . '/target0:0:99/0:0:99:0';
foreach ([$enclosure, $disk . '/block/sda', $sys . '/class/scsi_generic/sg200', $sys . '/class/scsi_generic/sg99', $sys . '/class/scsi_disk/0:0:99:0'] as $dir) mkdir($dir, 0777, true);
file_put_contents($enclosure . '/type', "13\n");
file_put_contents($enclosure . '/vendor', "DELL\n");
file_put_contents($enclosure . '/model', "MD1200\n");
file_put_contents($disk . '/type', "0\n");
symlink($enclosure, $sys . '/class/scsi_generic/sg200/device');
symlink($disk, $sys . '/class/scsi_generic/sg99/device');
symlink($disk, $sys . '/class/scsi_disk/0:0:99:0/device');
$inventory = md12xx_ses_identity_inventory($sys, static fn(string $device): string => md12xx_parse_ses_identity(cf($a)));
check(count($inventory) === 1 && $inventory[0]['device'] === '/dev/sg200', 'Inventory excludes disk at old address');
$shelf = $validated['shelves'][0];
$resolved = md12xx_resolve_ses_identity($shelf, $inventory);
check($resolved['state'] === 'normal' && $resolved['device'] === '/dev/sg200' && $resolved['address'] === '5:0:200:0', 'Resolve identity on another host');
check(md12xx_resolve_ses_identity($shelf, [])['state'] === 'fault', 'Missing identity blocks');
check(md12xx_resolve_ses_identity($shelf, array_merge($inventory, $inventory))['state'] === 'fault', 'Duplicate paths block');
$wrongModel = $inventory;
$wrongModel[0]['model'] = 'MD1220';
check(md12xx_resolve_ses_identity($shelf, $wrongModel)['state'] === 'fault', 'Model mismatch blocks');
$wrongIdentity = $inventory;
$wrongIdentity[0]['sesIdentity'] = $b;
check(md12xx_resolve_ses_identity($shelf, $wrongIdentity)['state'] === 'fault', 'Different enclosure cannot inherit old address');
$legacy = $shelf;
$legacy['sesIdentity'] = '';
check(md12xx_resolve_ses_identity($legacy, $inventory)['state'] === 'fault', 'Legacy configuration cannot silently adopt hardware');
$swapped = $inventory;
$swapped[] = ['device' => '/dev/sg99', 'address' => '0:0:99:0', 'vendor' => 'DELL', 'model' => 'MD1200', 'sesIdentity' => $b];
check(md12xx_resolve_ses_identity($shelf, array_reverse($swapped))['device'] === '/dev/sg200', 'Swapped enumeration order does not change pairing');
$unreadable = $swapped;
$unreadable[1]['sesIdentity'] = '';
check(md12xx_resolve_ses_identity($shelf, $unreadable)['state'] === 'fault', 'Unreadable peer cannot conceal duplicate');
$manualDraft = $manual;
$manualDraft['shelves'][0]['sesAddress'] = $resolved['address'];
$manualDraft['shelves'][0]['sesDevice'] = $resolved['device'];
$manualSaved = md12xx_merge_settings_config($manual, $manualDraft, $inventory);
check($manualSaved['shelves'][0]['commissioned'] && $manualSaved['shelves'][0]['sesIdentity'] === $a, 'Moved manual shelf stays commissioned on settings save');
$manualDraft['shelves'][0]['sesAddress'] = '0:0:99:0';
$manualDraft['shelves'][0]['sesDevice'] = '/dev/sg99';
// Deliberately choose a different address to ensure a new enclosure clears identity.
$manualDraft['shelves'][0]['sesAddress'] = '7:0:9:0';
$manualSaved = md12xx_merge_settings_config($manual, $manualDraft, $inventory);
check(!$manualSaved['shelves'][0]['commissioned'] && $manualSaved['shelves'][0]['sesIdentity'] === '', 'Manual hardware change requires new proof');
check(md12xx_commissioned_ses_identity('/dev/sg200', '5:0:200:0', 'MD1200', $inventory) === $a, 'Commissioning records proven enclosure identity');
foreach ([[], array_merge($inventory, $inventory)] as $bad) {
    $rejected = false;
    try { md12xx_commissioned_ses_identity('/dev/sg200', '5:0:200:0', 'MD1200', $bad); }
    catch (RuntimeException $expected) { $rejected = true; }
    check($rejected, 'Commissioning rejects missing or duplicate identity');
}
$mapping = md12xx_ses_disk_mapping($resolved['address'], $project . '/tests/fixtures/disks.ini', $sys);
check($mapping['state'] === 'verified' && $mapping['disks'] === ['disk1'], 'Resolve disk mapping on new host');
check(md12xx_ses_disk_mapping('0:0:99:0', $project . '/tests/fixtures/disks.ini', $sys)['state'] === 'invalid', 'Disk at old address is not enclosure');
file_put_contents($fixture . '/ses-inventory.json', json_encode($inventory));
copy($project . '/tests/fixtures/ses/shelf-1.txt', $fixture . '/shelf-1.txt');
file_put_contents($work . '/identity-config.json', json_encode($validated));
file_put_contents($work . '/identity-missing.json', json_encode($wrongIdentity));
file_put_contents($work . '/identity-duplicate.json', json_encode(array_merge($inventory, $inventory)));
echo "Persistent enclosure identity unit and topology tests passed.\n";
