<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['once', 'dry-run', 'config:', 'disks:', 'state:', 'fixture-dir:']);
$runOnce = array_key_exists('once', $options);
$dryRun = array_key_exists('dry-run', $options);
$configPath = isset($options['config']) ? (string) $options['config'] : MD12XX_CONFIG_FILE;
$disksPath = isset($options['disks']) ? (string) $options['disks'] : '/var/local/emhttp/disks.ini';
$statePath = isset($options['state']) ? (string) $options['state'] : MD12XX_STATE_FILE;
$fixtureDirectory = isset($options['fixture-dir']) ? (string) $options['fixture-dir'] : '';
$running = true;

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

function md12xx_controller_disks(string $path): array
{
    $values = is_file($path) ? @parse_ini_file($path, true, INI_SCANNER_RAW) : [];
    if (!is_array($values)) return [];
    $result = [];
    foreach ($values as $section => $disk) {
        if (!is_array($disk)) continue;
        $name = strtolower(trim((string) ($disk['name'] ?? $section)));
        if ($name !== '') $result[$name] = $disk;
    }
    return $result;
}

function md12xx_controller_spun_down(array $disk): bool
{
    $explicit = strtolower(trim((string) ($disk['spundown'] ?? '')));
    if (in_array($explicit, ['1', 'yes', 'true', 'on'], true)) return true;
    return str_contains(strtolower((string) ($disk['color'] ?? '')), 'blink');
}

function md12xx_controller_temperature(array $assigned, array $disks): array
{
    $assigned = array_values(array_unique(array_map(static fn($name): string => strtolower((string) $name), $assigned)));
    $temperatures = [];
    $seen = 0;
    $active = 0;
    $missing = [];
    foreach ($assigned as $name) {
        $disk = $disks[strtolower((string) $name)] ?? null;
        if (!is_array($disk)) { $missing[] = (string) $name; continue; }
        $seen++;
        if (!md12xx_controller_spun_down($disk)) $active++;
        $raw = trim((string) ($disk['temp'] ?? ''));
        if (is_numeric($raw) && (float) $raw > 0.0) $temperatures[(string) $name] = (float) $raw;
    }
    arsort($temperatures, SORT_NUMERIC);
    $source = $temperatures ? (string) array_key_first($temperatures) : null;
    return [
        'temperatureC' => $source === null ? null : round((float) $temperatures[$source], 1),
        'temperatureSource' => $source,
        'assignedCount' => count($assigned),
        'assignedSeen' => $seen,
        'missingDisks' => $missing,
        'activeDisks' => $active,
        'allSpunDown' => count($assigned) > 0 && $seen === count($assigned) && $active === 0,
    ];
}

function md12xx_controller_auto_target(array $config, array $thermal, ?int $previous): array
{
    $curve = $config['curve'];
    $cool = (int) $curve[0]['speed'];
    if (($thermal['assignedCount'] ?? 0) === 0) {
        return ['speed' => (int) $config['sensorFailureSpeed'], 'reason' => 'no assigned disks; fail-safe'];
    }
    if (!empty($thermal['mappingChanged'])) {
        return ['speed' => (int) $config['sensorFailureSpeed'], 'reason' => 'automatic disk mapping changed; fail-safe'];
    }
    if (!empty($thermal['missingDisks'])) {
        return ['speed' => (int) $config['sensorFailureSpeed'], 'reason' => 'assigned disk inventory incomplete; fail-safe'];
    }
    if ($thermal['allSpunDown']) return ['speed' => $cool, 'reason' => 'assigned disks spun down'];
    if ($thermal['temperatureC'] === null) {
        return ['speed' => (int) $config['sensorFailureSpeed'], 'reason' => 'temperature unavailable; fail-safe'];
    }

    $temperature = (float) $thermal['temperatureC'];
    $candidate = $cool;
    foreach ($curve as $step) {
        if ($temperature >= (float) $step['temperatureC']) $candidate = (int) $step['speed'];
    }
    if ($previous !== null && $candidate < $previous) {
        $threshold = null;
        foreach ($curve as $step) {
            if ((int) $step['speed'] >= $previous) { $threshold = (float) $step['temperatureC']; break; }
        }
        if ($threshold !== null && $temperature >= ($threshold - (float) $config['hysteresisC'])) $candidate = $previous;
    }
    return ['speed' => $candidate, 'reason' => ($thermal['temperatureSource'] ?? 'disk') . ' ' . $temperature . '°C'];
}

function md12xx_controller_resolve_ses(string $configuredDevice, string $scsiAddress): string
{
    if ($scsiAddress !== '') {
        foreach (glob('/sys/class/scsi_generic/sg*') ?: [] as $genericPath) {
            $resolved = @realpath($genericPath . '/device');
            if ($resolved !== false && basename($resolved) === $scsiAddress) return '/dev/' . basename($genericPath);
        }
    }
    return $configuredDevice;
}

function md12xx_controller_send(string $port, int $speed, bool $dryRun): array
{
    if ($dryRun) return ['state' => 'dry-run', 'message' => 'Dry run; no serial write'];
    if ($port === '' || !str_starts_with($port, '/dev/serial/by-id/') || !file_exists($port)) {
        return ['state' => 'fault', 'message' => 'Persistent serial adapter is missing'];
    }
    if (md12xx_fuser_binary() === null) return ['state' => 'fault', 'message' => 'Serial ownership check is unavailable'];
    if (md12xx_serial_busy($port)) return ['state' => 'fault', 'message' => 'Serial adapter is open in another process'];
    if (!is_dir(MD12XX_RUNTIME_DIR)) @mkdir(MD12XX_RUNTIME_DIR, 0755, true);
    $lockPath = MD12XX_RUNTIME_DIR . '/serial-' . substr(sha1($port), 0, 12) . '.lock';
    $lock = @fopen($lockPath, 'c+');
    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return ['state' => 'fault', 'message' => 'Serial adapter is already in use'];
    }
    try {
        $exitCode = 1;
        @exec('stty -F ' . escapeshellarg($port) . ' 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb min 0 time 1 2>/dev/null', $unused, $exitCode);
        if ($exitCode !== 0) return ['state' => 'fault', 'message' => 'Unable to configure serial adapter'];
        // Real MD1200 testing proved the console reader must already be open
        // before the writer sends set_speed. A single read/write descriptor
        // could acknowledge 50% and then silently miss the following 20%.
        $reader = @fopen($port, 'r');
        if ($reader === false) return ['state' => 'fault', 'message' => 'Unable to open serial response reader'];
        stream_set_blocking($reader, false);
        $readerReadyAt = microtime(true) + 0.3;
        while (microtime(true) < $readerReadyAt) { @fread($reader, 4096); usleep(25000); }
        $writer = @fopen($port, 'w');
        if ($writer === false) { fclose($reader); return ['state' => 'fault', 'message' => 'Unable to open serial command writer']; }
        stream_set_write_buffer($writer, 0);
        $payload = 'set_speed ' . $speed . "\r";
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $written = @fwrite($writer, $payload);
            @fflush($writer);
            if ($written !== strlen($payload)) { fclose($writer); fclose($reader); return ['state' => 'fault', 'message' => 'Serial command write failed']; }
            usleep(100000);
        }
        fclose($writer);
        $reply = '';
        $replyUntil = microtime(true) + 1.0;
        while (microtime(true) < $replyUntil && strlen($reply) < 8192) {
            $chunk = @fread($reader, 1024);
            if (is_string($chunk) && $chunk !== '') $reply .= $chunk;
            usleep(25000);
        }
        fclose($reader);
        $acknowledged = preg_match('/set_speed\s+' . preg_quote((string) $speed, '/') . '/i', $reply) === 1;
        return [
            'state' => $acknowledged ? 'sent' : 'unconfirmed',
            'message' => $acknowledged
                ? 'Command acknowledged; awaiting independent fan telemetry'
                : 'Command written without a console acknowledgement; independent telemetry required',
        ];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function md12xx_controller_rpm(string $id, string $device, string $fixtureDirectory): array
{
    if ($fixtureDirectory !== '') {
        $fixture = rtrim($fixtureDirectory, '/\\') . DIRECTORY_SEPARATOR . $id . '.txt';
        $output = is_file($fixture) ? (string) @file_get_contents($fixture) : '';
    } elseif ($device === '' || !file_exists($device)) {
        return ['state' => 'unconfigured', 'averageRpm' => null, 'fanCount' => 0, 'fanRpms' => [], 'message' => 'SES device is not mapped'];
    } else {
        $binary = trim((string) @shell_exec('command -v sg_ses 2>/dev/null'));
        if ($binary === '') return ['state' => 'unavailable', 'averageRpm' => null, 'fanCount' => 0, 'fanRpms' => [], 'message' => 'sg_ses is unavailable'];
        $lines = [];
        $exitCode = 1;
        @exec('timeout 10 ' . escapeshellarg($binary) . ' -p es ' . escapeshellarg($device) . ' 2>/dev/null', $lines, $exitCode);
        if ($exitCode !== 0) return ['state' => 'fault', 'averageRpm' => null, 'fanCount' => 0, 'fanRpms' => [], 'message' => 'SES telemetry read failed'];
        $output = implode("\n", $lines);
    }
    preg_match_all('/Actual\s+speed\s*=\s*([0-9]+)\s*rpm/i', $output, $matches);
    $values = array_values(array_filter(array_map('intval', $matches[1] ?? []), static fn(int $rpm): bool => $rpm > 0));
    if (!$values) return ['state' => 'unavailable', 'averageRpm' => null, 'fanCount' => 0, 'fanRpms' => [], 'message' => 'No fan RPM values were reported'];
    return ['state' => 'normal', 'averageRpm' => (int) round(array_sum($values) / count($values)), 'fanCount' => count($values), 'fanRpms' => $values, 'message' => ''];
}

function md12xx_controller_write_state(string $path, array $state): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) @mkdir($directory, 0755, true);
    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) return;
    $temporary = $path . '.tmp.' . getmypid();
    if (@file_put_contents($temporary, $encoded . "\n", LOCK_EX) !== false) {
        @chmod($temporary, 0644);
        @rename($temporary, $path);
    }
}

$previousTargets = [];
$lastCommands = [];
$previousMode = null;
$nextPollAt = 0;
$lastConfigSignature = '';
$lastControlSignature = '';
$pendingVerifications = [];
$lastVerifications = [];
$verificationFaults = [];
$driftCounts = [];

while ($running) {
    clearstatcache(true, $configPath);
    $signature = is_file($configPath) ? (string) @sha1_file($configPath) : '';
    try {
        $config = md12xx_validate_config(md12xx_read_config($configPath));
    } catch (Throwable $error) {
        md12xx_controller_write_state($statePath, ['generatedAt' => time(), 'controller' => ['state' => 'fault', 'message' => 'Invalid configuration: ' . $error->getMessage()], 'shelves' => []]);
        if ($runOnce) break;
        sleep(10);
        continue;
    }
    $pollSeconds = (int) $config['pollSeconds'];
    $configChanged = $signature !== $lastConfigSignature;
    if (!$runOnce && !$configChanged && time() < $nextPollAt) { sleep(1); continue; }
    $lastConfigSignature = $signature;
    $nextPollAt = time() + $pollSeconds;
    $controlConfig = $config;
    unset($controlConfig['discovery'], $controlConfig['pollSeconds']);
    foreach ($controlConfig['shelves'] as &$controlShelf) unset($controlShelf['name']);
    unset($controlShelf);
    $controlSignature = sha1((string) json_encode($controlConfig, JSON_UNESCAPED_SLASHES));
    $controlChanged = $controlSignature !== $lastControlSignature;
    $lastControlSignature = $controlSignature;

    $enabled = (bool) $config['enabled'];
    $mode = (string) $config['mode'];
    $manualSpeed = (int) $config['manualSpeed'];
    $commissioningActive = md12xx_commission_active();
    $conflicts = $enabled ? md12xx_competing_controllers($config) : [];
    $disks = md12xx_controller_disks($disksPath);
    $controllerState = 'normal';
    $messages = [];
    $shelfStates = [];

    if (!$enabled) $messages[] = 'Controller disabled';
    if ($enabled && !$config['shelves']) { $controllerState = 'attention'; $messages[] = 'No shelves configured'; }
    if ($conflicts) { $controllerState = 'fault'; $messages[] = 'Another fan controller is active'; }
    if ($enabled && $commissioningActive) { $controllerState = 'fault'; $messages[] = 'Identify & test is active; control writes are blocked'; }

    foreach ($config['shelves'] as $shelf) {
        $id = (string) $shelf['id'];
        $assignmentMode = (string) ($shelf['diskAssignment'] ?? 'manual');
        $assignedDisks = is_array($shelf['disks'] ?? null) ? array_values($shelf['disks']) : [];
        $savedAssignedDisks = $assignedDisks;
        $mappingChanged = false;
        $diskMapping = [
            'state' => $assignmentMode === 'automatic' ? 'unavailable' : 'manual',
            'disks' => $assignedDisks,
            'message' => $assignmentMode === 'automatic' ? 'SES enclosure is not mapped' : 'Manual disk override',
        ];
        if ($assignmentMode === 'automatic' && (string) $shelf['sesAddress'] !== '') {
            $diskMapping = md12xx_ses_disk_mapping((string) $shelf['sesAddress'], $disksPath);
            // Keep the last hardware-confirmed snapshot if sysfs is temporarily
            // unavailable. A successful current mapping always wins.
            if (!empty($diskMapping['disks'])) {
                $currentDisks = array_values(array_unique($diskMapping['disks']));
                $savedComparison = $savedAssignedDisks;
                $currentComparison = $currentDisks;
                sort($savedComparison, SORT_NATURAL | SORT_FLAG_CASE);
                sort($currentComparison, SORT_NATURAL | SORT_FLAG_CASE);
                $mappingChanged = $savedComparison !== [] && $savedComparison !== $currentComparison;
                $assignedDisks = $mappingChanged
                    ? array_values(array_unique(array_merge($savedAssignedDisks, $currentDisks)))
                    : $currentDisks;
                if ($mappingChanged) {
                    $diskMapping['state'] = 'changed';
                    $diskMapping['message'] = 'The current SES-to-disk mapping differs from the commissioned snapshot';
                }
            }
        }
        $thermal = md12xx_controller_temperature($assignedDisks, $disks);
        $thermal['mappingChanged'] = $mappingChanged;
        $auto = md12xx_controller_auto_target($config, $thermal, $previousMode === 'auto' ? ($previousTargets[$id] ?? null) : null);
        $target = $mode === 'manual' ? $manualSpeed : (int) $auto['speed'];
        $reason = $mode === 'manual' ? 'manual selection' : (string) $auto['reason'];
        if (isset($pendingVerifications[$id]) && (int) ($pendingVerifications[$id]['target'] ?? -1) !== $target) {
            unset($pendingVerifications[$id]);
        }
        $operable = $enabled && (bool) $shelf['enabled'] && (bool) $shelf['commissioned'];
        $lastCommand = $lastCommands[$id] ?? null;
        $commandDue = $operable && !$conflicts && !$commissioningActive && ($controlChanged || $lastCommand === null || ($previousTargets[$id] ?? null) !== $target || (time() - (int) $lastCommand) >= (int) $config['reassertSeconds']);
        if ($commissioningActive) {
            $write = ['state' => 'blocked', 'message' => 'Identify & test is active'];
        } elseif ($conflicts) {
            $write = ['state' => 'blocked', 'message' => 'Another fan controller is active'];
        } else {
            $write = ['state' => $operable ? 'idle' : 'disabled', 'message' => $operable ? 'No command due' : ((bool) $shelf['commissioned'] ? 'Shelf or controller disabled' : 'Shelf not commissioned')];
        }
        if ($commandDue) {
            $write = md12xx_controller_send((string) $shelf['serialPort'], $target, $dryRun);
            if (in_array($write['state'], ['sent', 'unconfirmed', 'dry-run'], true)) $lastCommands[$id] = time();
            if (in_array($write['state'], ['sent', 'unconfirmed'], true)) {
                $calibration = md12xx_controller_calibration($shelf);
                if ($calibration === null) {
                    $lastVerifications[$id] = ['target' => $target, 'state' => 'unverified', 'message' => 'Telemetry calibration is missing; run Identify & test again'];
                    $write = $lastVerifications[$id];
                } else {
                    $pendingVerifications[$id] = ['target' => $target, 'sentAt' => time(), 'calibration' => $calibration];
                    unset($verificationFaults[$id]);
                }
            }
        }

        $sesDevice = md12xx_controller_resolve_ses((string) $shelf['sesDevice'], (string) $shelf['sesAddress']);
        $telemetry = md12xx_controller_rpm($id, $sesDevice, $fixtureDirectory);
        $pending = $pendingVerifications[$id] ?? null;
        if (is_array($pending) && (int) $pending['target'] === $target) {
            $elapsed = time() - (int) $pending['sentAt'];
            $verification = $telemetry['state'] === 'normal'
                ? md12xx_controller_verify_target($target, (int) $telemetry['averageRpm'], $pending['calibration'])
                : ['passed' => false, 'expected' => 'normal SES fan telemetry'];
            if ((bool) $verification['passed']) {
                $minimumOnly = !empty($verification['minimumOnly']);
                $lastVerifications[$id] = [
                    'target' => $target,
                    'state' => $minimumOnly ? 'minimum-verified' : 'verified',
                    'message' => $minimumOnly
                        ? 'Independent SES telemetry verified at least the commissioned 50% response at ' . $telemetry['averageRpm'] . ' RPM; the exact ' . $target . '% RPM is not calibrated'
                        : 'Independent SES telemetry verified the response to ' . $target . '% at ' . $telemetry['averageRpm'] . ' RPM',
                ];
                $driftCounts[$id] = 0;
                unset($pendingVerifications[$id], $verificationFaults[$id]);
            } elseif ($elapsed >= 45) {
                $verificationFaults[$id] = ['target' => $target, 'state' => 'fault', 'message' => 'Fan response to ' . $target . '% was not verified after 45 seconds; expected ' . $verification['expected']];
                unset($pendingVerifications[$id], $lastCommands[$id], $lastVerifications[$id]);
            } else {
                $write = ['state' => 'pending', 'message' => 'Waiting for independent SES telemetry to verify ' . $target . '% (' . $elapsed . 's)'];
            }
        }
        $lastVerified = $lastVerifications[$id] ?? null;
        if (!isset($pendingVerifications[$id]) && is_array($lastVerified) && (int) ($lastVerified['target'] ?? -1) === $target
            && in_array((string) ($lastVerified['state'] ?? ''), ['verified', 'minimum-verified'], true) && $telemetry['state'] === 'normal') {
            $calibration = md12xx_controller_calibration($shelf);
            $drift = $calibration === null ? ['passed' => true] : md12xx_controller_verify_target($target, (int) $telemetry['averageRpm'], $calibration);
            $driftCounts[$id] = !empty($drift['passed']) ? 0 : (($driftCounts[$id] ?? 0) + 1);
            if ($driftCounts[$id] >= 3) {
                $verificationFaults[$id] = ['target' => $target, 'state' => 'fault', 'message' => 'Fan telemetry no longer matches the ' . $target . '% target; expected ' . ($drift['expected'] ?? 'the commissioned response')];
                unset($lastVerifications[$id], $lastCommands[$id]);
            }
        }
        if (($verificationFaults[$id]['target'] ?? null) === $target) {
            $write = $verificationFaults[$id];
        } elseif (($lastVerifications[$id]['target'] ?? null) === $target && !isset($pendingVerifications[$id]) && in_array($write['state'], ['idle', 'sent', 'unconfirmed'], true)) {
            $write = $lastVerifications[$id];
        }
        if ($operable && $write['state'] === 'fault') { $controllerState = 'fault'; $messages[] = $shelf['name'] . ': ' . $write['message']; }
        elseif ($operable && $write['state'] === 'unverified' && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': ' . $write['message']; }
        elseif ($operable && $write['state'] === 'pending' && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': ' . $write['message']; }
        elseif ($operable && $mappingChanged && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': automatic disk mapping changed'; }
        elseif ($operable && $mode === 'auto' && $thermal['assignedCount'] === 0 && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': no assigned disks; using fail-safe'; }
        elseif ($operable && !empty($thermal['missingDisks']) && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': assigned disk inventory incomplete'; }
        elseif ($enabled && (bool) $shelf['enabled'] && !(bool) $shelf['commissioned'] && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': commissioning required'; }
        elseif ($enabled && (bool) $shelf['enabled'] && $telemetry['state'] !== 'normal' && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': ' . $telemetry['message']; }

        $shelfStates[] = [
            'id' => $id,
            'name' => $shelf['name'],
            'model' => $shelf['model'],
            'enabled' => (bool) $shelf['enabled'],
            'commissioned' => (bool) $shelf['commissioned'],
            'serialPort' => $shelf['serialPort'],
            'sesDevice' => $sesDevice,
            'sesAddress' => $shelf['sesAddress'],
            'diskAssignment' => $assignmentMode,
            'diskMappingState' => $diskMapping['state'],
            'diskMappingMessage' => $diskMapping['message'],
            'assignedDisks' => $assignedDisks,
            'assignedCount' => $thermal['assignedCount'],
            'assignedSeen' => $thermal['assignedSeen'],
            'missingDisks' => $thermal['missingDisks'],
            'activeDisks' => $thermal['activeDisks'],
            'temperatureC' => $thermal['temperatureC'],
            'temperatureSource' => $thermal['temperatureSource'],
            'averageRpm' => $telemetry['averageRpm'],
            'fanCount' => $telemetry['fanCount'],
            'fanRpms' => $telemetry['fanRpms'],
            'telemetryState' => $telemetry['state'],
            'telemetryMessage' => $telemetry['message'],
            'targetPercent' => $target,
            'targetReason' => $reason,
            'writeState' => $write['state'],
            'writeMessage' => $write['message'],
        ];
        $previousTargets[$id] = $target;
    }

    $state = [
        'generatedAt' => time(),
        'controller' => [
            'enabled' => $enabled,
            'mode' => $mode,
            'manualSpeed' => $manualSpeed,
            'state' => $controllerState,
            'message' => implode('; ', array_values(array_unique($messages))),
            'conflicts' => $conflicts,
            'dryRun' => $dryRun,
            'pollSeconds' => $pollSeconds,
        ],
        'shelves' => $shelfStates,
    ];
    md12xx_controller_write_state($statePath, $state);
    $previousMode = $mode;
    if ($runOnce) break;
}
