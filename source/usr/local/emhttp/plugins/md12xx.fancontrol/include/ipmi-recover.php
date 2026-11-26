<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/ipmi.php';
$pending = COOLING_IPMI_RUNTIME . '/ipmi-confirmation-pending.json';
$lock = fopen(COOLING_IPMI_RUNTIME . '/ipmi-command.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if (!is_file(COOLING_IPMI_LEASE) && !is_file($pending)) exit(0);
$status = cooling_ipmi_read(COOLING_IPMI_RUNTIME . '/ipmi-status.json');
if (($argv[1] ?? '') === '--if-stale' && !cooling_ipmi_status_stale($status)) exit(0);
cooling_ipmi_latch(is_file($pending) ? 'Supervisor recovery during pending sensor confirmation' : 'Supervisor recovery with outstanding ownership; controller loss, stale status or shutdown',
    ['previousStatus' => $status]);
if (is_file(COOLING_IPMI_REARM)) @unlink($pending);
exit(cooling_ipmi_restore('cooling_ipmi_run', COOLING_IPMI_LEASE, is_file(COOLING_IPMI_REARM)) ? 0 : 1);
