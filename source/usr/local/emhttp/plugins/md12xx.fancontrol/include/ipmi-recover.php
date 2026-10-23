<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/ipmi.php';
if (!is_file(COOLING_IPMI_LEASE)) exit(0);
$lock = fopen(COOLING_IPMI_RUNTIME . '/ipmi-command.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
touch(COOLING_IPMI_RUNTIME . '/ipmi-fault-latched');
exit(cooling_ipmi_restore('cooling_ipmi_run', COOLING_IPMI_LEASE) ? 0 : 1);
