<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/ipmi.php';
$pending = COOLING_IPMI_RUNTIME . '/ipmi-confirmation-pending.json';
if (!is_file(COOLING_IPMI_LEASE) && !is_file($pending)) exit(0);
$lock = fopen(COOLING_IPMI_RUNTIME . '/ipmi-command.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
cooling_ipmi_latch(is_file($pending) ? 'Supervisor recovery during pending sensor confirmation' : 'Supervisor recovery with outstanding ownership; controller loss, stale status or shutdown',
    ['previousStatus' => cooling_ipmi_read(COOLING_IPMI_RUNTIME . '/ipmi-status.json')]);
if (is_file(COOLING_IPMI_RUNTIME . '/ipmi-fault-latched')) @unlink($pending);
exit(cooling_ipmi_restore('cooling_ipmi_run', COOLING_IPMI_LEASE) ? 0 : 1);
