<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $config = md12xx_validate_config(md12xx_read_config());
    $disks = md12xx_read_disk_state('/var/local/emhttp/disks.ini');
    $policy = md12xx_quiet_temperature_policy($config, $disks);
    $action = $argv[1] ?? '';
    if ($action === 'restore-speed') { echo $policy['restoreSpeed']; exit; }
    if (!$policy['safe'] || md12xx_competing_controllers($config)) throw new RuntimeException('Quiet recovery is unsafe or another writer is active');
    if ($action === 'temperatures') exit;
    if ($action !== 'baseline') throw new RuntimeException('Unknown recovery check');
    $candidates = [];
    foreach (md12xx_discover_ses() as $candidate) {
        if (empty($candidate['supportedCandidate'])) continue;
        $lines = []; $exitCode = 1;
        exec('timeout 3 sg_ses -p es ' . escapeshellarg($candidate['device']) . ' 2>/dev/null', $lines, $exitCode);
        if ($exitCode !== 0) throw new RuntimeException('Baseline telemetry unavailable');
        if (preg_match('/\b(?:Fail|CRIT|UNRECOV)=1\b/', implode("\n", $lines))) throw new RuntimeException('Enclosure reports a hardware fault; quiet recovery is blocked');
        $telemetry = md12xx_parse_fan_telemetry(implode("\n", $lines));
        if ($telemetry['state'] !== 'normal') throw new RuntimeException($telemetry['message']);
        $rpms = $telemetry['fanRpms'];
        $candidate['fanCount'] = count($rpms);
        $candidate['averageRpm'] = $rpms ? (int) round(array_sum($rpms) / count($rpms)) : 0;
        $candidates[] = $candidate;
    }
    if (!md12xx_quiet_baseline_valid($config, $candidates)) throw new RuntimeException('Quiet recovery requires every shelf at its proven 20% baseline with exact disk mapping');
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
