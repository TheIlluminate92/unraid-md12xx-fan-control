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
    $temperatures = [];
    $seen = 0;
    $active = 0;
    foreach ($assigned as $name) {
        $disk = $disks[strtolower((string) $name)] ?? null;
        if (!is_array($disk)) continue;
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
        'assignedSeen' => $seen,
        'activeDisks' => $active,
        'allSpunDown' => $seen > 0 && $active === 0,
    ];
}

function md12xx_controller_auto_target(array $config, array $thermal, ?int $previous): array
{
    $curve = $config['curve'];
    $cool = (int) $curve[0]['speed'];
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
        @exec('stty -F ' . escapeshellarg($port) . ' 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb 2>/dev/null', $unused, $exitCode);
        if ($exitCode !== 0) return ['state' => 'fault', 'message' => 'Unable to configure serial adapter'];
        $handle = @fopen($port, 'w');
        if ($handle === false) return ['state' => 'fault', 'message' => 'Unable to open serial adapter'];
        $payload = 'set_speed ' . $speed . "\r";
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $written = @fwrite($handle, $payload);
            @fflush($handle);
            if ($written !== strlen($payload)) { fclose($handle); return ['state' => 'fault', 'message' => 'Serial command write failed']; }
            usleep(100000);
        }
        fclose($handle);
        return ['state' => 'sent', 'message' => 'Command sent; awaiting independent fan telemetry'];
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
        @exec(escapeshellarg($binary) . ' -p es ' . escapeshellarg($device) . ' 2>/dev/null', $lines, $exitCode);
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

    $enabled = (bool) $config['enabled'];
    $mode = (string) $config['mode'];
    $manualSpeed = (int) $config['manualSpeed'];
    $conflicts = $enabled ? md12xx_competing_controllers($config) : [];
    $disks = md12xx_controller_disks($disksPath);
    $controllerState = 'normal';
    $messages = [];
    $shelfStates = [];

    if (!$enabled) $messages[] = 'Controller disabled';
    if ($enabled && !$config['shelves']) { $controllerState = 'attention'; $messages[] = 'No shelves configured'; }
    if ($conflicts) { $controllerState = 'fault'; $messages[] = 'Competing controller running: ' . implode(', ', $conflicts); }

    foreach ($config['shelves'] as $shelf) {
        $id = (string) $shelf['id'];
        $thermal = md12xx_controller_temperature($shelf['disks'], $disks);
        $auto = md12xx_controller_auto_target($config, $thermal, $previousMode === 'auto' ? ($previousTargets[$id] ?? null) : null);
        $target = $mode === 'manual' ? $manualSpeed : (int) $auto['speed'];
        $reason = $mode === 'manual' ? 'manual selection' : (string) $auto['reason'];
        $operable = $enabled && (bool) $shelf['enabled'] && (bool) $shelf['commissioned'];
        $lastCommand = $lastCommands[$id] ?? null;
        $commandDue = $operable && !$conflicts && ($configChanged || $lastCommand === null || ($previousTargets[$id] ?? null) !== $target || (time() - (int) $lastCommand) >= (int) $config['reassertSeconds']);
        $write = ['state' => $operable ? 'idle' : 'disabled', 'message' => $operable ? 'No command due' : ((bool) $shelf['commissioned'] ? 'Shelf or controller disabled' : 'Shelf not commissioned')];
        if ($commandDue) {
            $write = md12xx_controller_send((string) $shelf['serialPort'], $target, $dryRun);
            if (in_array($write['state'], ['sent', 'dry-run'], true)) $lastCommands[$id] = time();
        }

        $sesDevice = md12xx_controller_resolve_ses((string) $shelf['sesDevice'], (string) $shelf['sesAddress']);
        $telemetry = md12xx_controller_rpm($id, $sesDevice, $fixtureDirectory);
        if ($operable && $write['state'] === 'fault') { $controllerState = 'fault'; $messages[] = $shelf['name'] . ': ' . $write['message']; }
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
            'assignedDisks' => $shelf['disks'],
            'assignedSeen' => $thermal['assignedSeen'],
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
