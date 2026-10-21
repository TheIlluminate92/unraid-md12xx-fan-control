<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/common.php';
try {
    $config = md12xx_validate_config(md12xx_read_config());
    $allDisks = md12xx_read_disk_state('/var/local/emhttp/disks.ini');
    $candidates = array_values(array_filter(md12xx_discover_ses(), static fn(array $candidate): bool => !empty($candidate['supportedCandidate'])));
    $disks = [];
    $mappingKnown = count($candidates) > 0;
    foreach ($candidates as $candidate) {
        if (($candidate['diskMappingState'] ?? '') !== 'verified' || empty($candidate['disks'])) $mappingKnown = false;
        foreach ($candidate['disks'] ?? [] as $name) $disks[$name] = $allDisks[$name] ?? [];
    }
    $policy = md12xx_commission_temperature_policy($config, $disks);
    if (!$mappingKnown) $policy = ['safe'=>false, 'restoreSpeed'=>100];
    $action = $argv[1] ?? '';
    if ($action === 'restore-speed') { echo $policy['restoreSpeed']; exit; }
    if (!$policy['safe'] || md12xx_competing_controllers($config)) throw new RuntimeException('Commissioning requires known active disk temperatures below 35 C, a 20% cooling target, and no competing writer');
    if ($action === 'temperatures') exit;
    if ($action !== 'baseline') throw new RuntimeException('Unknown commissioning safety check');
    $found = false;
    foreach ($candidates as $candidate) {
        if (empty($candidate['supportedCandidate'])) continue;
        $found = true;
        $lines = []; $exitCode = 1;
        exec('timeout 3 sg_ses -p es ' . escapeshellarg($candidate['device']) . ' 2>/dev/null', $lines, $exitCode);
        if ($exitCode !== 0 || md12xx_parse_fan_telemetry(implode("\n", $lines))['state'] !== 'normal') throw new RuntimeException('Commissioning requires four healthy fan readings on every candidate shelf');
    }
    if (!$found) throw new RuntimeException('No supported enclosure telemetry is available');
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
