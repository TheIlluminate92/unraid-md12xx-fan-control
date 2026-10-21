<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Read-only liveness assessment. Never kill a hardware writer based on this.
$state = json_decode((string) @file_get_contents($argv[1] ?? ''), true);
$started = (int) ($argv[2] ?? time());
$now = (int) ($argv[3] ?? time());
$generated = (int) ($state['generatedAt'] ?? 0);
$budget = max(60, min(300, (int) ($state['controller']['pollSeconds'] ?? 5)) * 3 + 30);
if ($generated < $started || $generated > $now + 5 || $now - $generated > $budget) {
    echo $now - $started < $budget ? 'recovering' : 'fault';
} elseif (($state['controller']['state'] ?? '') === 'fault') {
    echo 'fault';
} else {
    echo 'normal';
}
