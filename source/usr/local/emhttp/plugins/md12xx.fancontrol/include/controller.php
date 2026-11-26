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
if ($fixtureDirectory !== '' && !$dryRun) {
    fwrite(STDERR, "Telemetry fixtures require --dry-run\n");
    exit(2);
}
$running = true;

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

// Use only native normal active/standby indications. Missing, unknown or
// conflicting indicators cannot justify excluding an assigned disk.
function md12xx_controller_disk_standby(array $disk): ?bool
{
    $spin = strtolower(trim((string) ($disk['spundown'] ?? '')));
    $color = strtolower(trim((string) ($disk['color'] ?? '')));
    $fromSpin = match ($spin) {
        '1', 'yes', 'true', 'on' => true,
        '0', 'no', 'false', 'off' => false,
        default => null,
    };
    $fromColor = match ($color) {
        'green-blink' => true,
        'green-on' => false,
        default => null,
    };
    if (($spin !== '' && $fromSpin === null) || ($color !== '' && $fromColor === null)
        || ($fromSpin !== null && $fromColor !== null && $fromSpin !== $fromColor)) return null;
    return $fromSpin ?? $fromColor;
}

function md12xx_controller_temperature(array $assigned, array $disks): array
{
    $assigned = array_values(array_unique(array_map(static fn($name): string => strtolower((string) $name), $assigned)));
    $temperatures = [];
    $seen = 0;
    $active = 0;
    $standbyCount = 0;
    $missing = [];
    $missingTemperatures = [];
    foreach ($assigned as $name) {
        $disk = $disks[$name] ?? null;
        if (!is_array($disk)) { $missing[] = $name; continue; }
        $seen++;
        $standby = md12xx_controller_disk_standby($disk);
        if ($standby === true) { $standbyCount++; continue; }
        if ($standby === null) continue;
        $active++;
        $raw = trim((string) ($disk['temp'] ?? ''));
        $celsius = is_numeric($raw) ? (float) $raw : null;
        if ($celsius !== null && is_finite($celsius) && $celsius > 0 && $celsius <= 100) $temperatures[$name] = $celsius;
        else $missingTemperatures[] = $name;
    }
    arsort($temperatures, SORT_NUMERIC);
    $source = array_key_first($temperatures);
    return [
        'temperatureC' => $source === null ? null : $temperatures[$source],
        'temperatureSource' => $source,
        'assignedCount' => count($assigned),
        'assignedSeen' => $seen,
        'missingDisks' => $missing,
        'missingTemperatures' => $missingTemperatures,
        'activeDisks' => $active,
        'diskInputFreshness' => $seen > 0 ? 'unverified' : 'unavailable',
        'diskPowerStateUnverified' => $seen > $active + $standbyCount,
        'reportedStandby' => count($assigned) > 0 && $standbyCount === count($assigned),
    ];
}

function md12xx_controller_auto_target(int $failureSpeed, array $thermal, array $curve, ?int $previous, float $hysteresis): array
{
    if (!empty($thermal['mappingUnverified'])) $reason = 'automatic disk mapping unverified';
    elseif (!empty($thermal['mappingChanged'])) $reason = 'automatic disk mapping changed';
    elseif ($thermal['assignedCount'] === 0) $reason = 'no assigned disks';
    elseif ($thermal['missingDisks']) $reason = 'assigned disk inventory incomplete';
    elseif ($thermal['diskPowerStateUnverified']) $reason = 'disk standby state unverified';
    elseif ($thermal['missingTemperatures']) $reason = 'active disk temperature unavailable';
    elseif ($thermal['temperatureC'] === null) $reason = 'no usable active disk temperature';
    else {
        $celsius = $thermal['temperatureC'];
        return [
            'speed' => cooling_curve_target($curve, $celsius, $previous, $hysteresis),
            'reason' => $thermal['temperatureSource'] . ' ' . round($celsius, 1) . '°C (Unraid-reported, age unverified)',
            'usingCurve' => true,
        ];
    }
    return ['speed' => $failureSpeed, 'reason' => $reason . '; fail-safe', 'usingCurve' => false];
}

function md12xx_controller_send(string $port, int $speed, bool $dryRun): array
{
    if ($dryRun) return ['state' => 'dry-run', 'message' => 'Dry run; no serial write'];
    if ($port === '' || !md12xx_serial_path_is_safe($port) || !file_exists($port)) {
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
    return md12xx_parse_fan_telemetry($output);
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
$previousAutoTargets = [];
$lastCommands = [];
$nextPollAt = 0;
$lastConfigSignature = '';
$lastControlSignature = '';
$pendingVerifications = [];
$lastVerifications = [];
$verificationFaults = [];
$driftCounts = [];
$startupRecoveryChecked = false;

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
    $disks = md12xx_read_disk_state($disksPath);
    $controllerState = 'normal';
    $messages = [];
    $shelfStates = [];

    if (!$enabled) $messages[] = 'Controller disabled';
    if ($enabled && !$config['shelves']) { $controllerState = 'attention'; $messages[] = 'No shelves configured'; }
    if ($conflicts) { $controllerState = 'fault'; $messages[] = 'Another fan controller is active'; }
    if ($enabled && $commissioningActive) { $controllerState = 'fault'; $messages[] = 'Identify & test is active; control writes are blocked'; }

    // Refresh read-only identities each cycle; cached host addresses never prove identity.
    $inventoryFixture = $fixtureDirectory . '/ses-inventory.json';
    $identityInventory = $fixtureDirectory === '' ? md12xx_ses_identity_inventory()
        : (is_file($inventoryFixture) ? (json_decode((string) file_get_contents($inventoryFixture), true) ?: []) : null);
    $mappingSysfs = $fixtureDirectory !== '' && is_dir($fixtureDirectory . '/sys') ? $fixtureDirectory . '/sys' : '/sys';

    // One bounded attempt per boot, never in fixtures or dry-run mode.
    if (!$startupRecoveryChecked && $enabled && !$commissioningActive && !$dryRun && !$runOnce) {
        $startupRecoveryChecked = true;
        $policy = md12xx_quiet_temperature_policy($config);
        if ($policy['safe'] && !$conflicts) {
            foreach ($config['shelves'] as $candidate) {
                if (md12xx_resolve_ses_identity($candidate, $identityInventory)['state'] === 'normal') continue;
                $launchLock = @fopen(MD12XX_RUNTIME_DIR . '/test-launch.lock', 'c+');
                if ($launchLock === false || !@flock($launchLock, LOCK_EX | LOCK_NB)) { if (is_resource($launchLock)) fclose($launchLock); break; }
                if (md12xx_commission_active()) { fclose($launchLock); break; }
                $attempt = @fopen(MD12XX_RUNTIME_DIR . '/quiet-startup.attempt', 'x');
                if ($attempt === false) { fclose($launchLock); break; }
                fwrite($attempt, (string) $candidate['id']); fclose($attempt);
                $job = MD12XX_COMMISSION_JOB_DIR . '/startup35-' . $candidate['id'];
                @mkdir($job, 0770, true);
                @file_put_contents(MD12XX_RUNTIME_DIR . '/commissioning.active', 'startup35:' . $candidate['id']);
                $command = 'nohup timeout --signal=TERM --kill-after=20 180 '
                    . escapeshellarg(dirname(__DIR__) . '/scripts/commission-job.sh') . ' '
                    . escapeshellarg((string) $candidate['id']) . ' ' . escapeshellarg($job)
                    . ' startup35 >/dev/null 2>&1 & echo $!';
                $pid = trim((string) @shell_exec($command));
                if (ctype_digit($pid) && (int) $pid > 0) {
                    @file_put_contents($job . '/pid', $pid . "\n");
                    $commissioningActive = true;
                } else {
                    @unlink(MD12XX_RUNTIME_DIR . '/commissioning.active');
                }
                fclose($launchLock);
                break;
            }
        }
    }
    $quietRecoveryActive = $commissioningActive && str_starts_with(trim((string) @file_get_contents(MD12XX_RUNTIME_DIR . '/commissioning.active')), 'startup35:');
    if ($quietRecoveryActive) {
        $controllerState = 'attention';
        $messages = ['Quiet 35% startup recovery is running; normal control writes are paused'];
    }

    foreach ($config['shelves'] as $shelf) {
        $id = (string) $shelf['id'];
        $identityMode = (string) ($shelf['verificationMode'] ?? 'rpm');
        $identityVerified = in_array($identityMode, ['operator', 'sas'], true);
        $identityLabel = $identityMode === 'sas' ? 'SAS identity-matched' : 'operator-confirmed';
        if ($identityVerified) {
            unset($pendingVerifications[$id], $lastVerifications[$id], $verificationFaults[$id], $driftCounts[$id]);
        }
        $connection = $identityInventory === null
            ? ['state' => 'normal', 'device' => $shelf['sesDevice'], 'address' => $shelf['sesAddress'], 'message' => 'Synthetic telemetry fixture']
            : md12xx_resolve_ses_identity($shelf, $identityInventory);
        $sesDevice = (string) $connection['device'];
        $sesAddress = (string) $connection['address'];
        $invalidSesMapping = $connection['state'] !== 'normal';
        $assignmentMode = (string) ($shelf['diskAssignment'] ?? 'manual');
        $savedAssignedDisks = is_array($shelf['disks'] ?? null) ? array_values($shelf['disks']) : [];
        $assignedDisks = $assignmentMode === 'automatic' ? [] : $savedAssignedDisks;
        $diskMapping = [
            'state' => $assignmentMode === 'automatic' ? 'unavailable' : 'manual',
            'message' => $assignmentMode === 'automatic' ? 'SES enclosure is not mapped' : 'Manual disk override',
        ];
        if ($assignmentMode === 'automatic' && !$invalidSesMapping && $sesAddress !== '') {
            $diskMapping = md12xx_ses_disk_mapping($sesAddress, $disksPath, $mappingSysfs);
            if ($diskMapping['state'] === 'invalid') {
                $invalidSesMapping = true;
                $connection['message'] = $diskMapping['message'];
            } elseif ($diskMapping['state'] === 'verified') {
                if (count($diskMapping['disks']) !== count($diskMapping['blockDevices'])) {
                    $diskMapping['state'] = 'partial';
                    $diskMapping['message'] = 'Current SES topology does not map every detected block device to an Unraid disk';
                } else {
                    $assignedDisks = $diskMapping['disks'];
                    $savedComparison = $savedAssignedDisks;
                    $currentComparison = $assignedDisks;
                    sort($savedComparison, SORT_NATURAL | SORT_FLAG_CASE);
                    sort($currentComparison, SORT_NATURAL | SORT_FLAG_CASE);
                    if ($savedComparison !== [] && $savedComparison !== $currentComparison) {
                        $diskMapping['state'] = 'changed';
                        $diskMapping['message'] = 'The current SES-to-disk mapping differs from the commissioned snapshot';
                    }
                }
            }
        }
        $mappingChanged = $diskMapping['state'] === 'changed';
        $mappingUnverified = $assignmentMode === 'automatic' && !$mappingChanged && $diskMapping['state'] !== 'verified';
        $thermal = md12xx_controller_temperature($assignedDisks, $disks);
        $thermal['mappingChanged'] = $mappingChanged;
        $thermal['mappingUnverified'] = $mappingUnverified;
        $curve = cooling_device_curve($shelf, $config['curve']);
        $failureSpeed = md12xx_sensor_failure_speed($config, $curve);
        $hysteresis = cooling_device_hysteresis($shelf, $config['hysteresisC']);
        $previousAuto = $controlChanged ? null : ($previousAutoTargets[$id] ?? null);
        $auto = md12xx_controller_auto_target($failureSpeed, $thermal, $curve, $previousAuto, $hysteresis);
        $telemetry = $invalidSesMapping
            ? ['state' => 'fault', 'averageRpm' => null, 'fanCount' => 0, 'fanRpms' => [], 'message' => $connection['message']]
            : md12xx_controller_rpm($id, $sesDevice, $fixtureDirectory);
        $target = $mode === 'manual' ? $manualSpeed : (int) $auto['speed'];
        $reason = $mode === 'manual' ? 'manual selection' : (string) $auto['reason'];
        if ($mode === 'auto' && $telemetry['state'] === 'fault') {
            $target = max($target, $failureSpeed);
            $reason = 'fan telemetry fault; fail-safe';
        }
        if (isset($pendingVerifications[$id]) && (int) ($pendingVerifications[$id]['target'] ?? -1) !== $target) {
            unset($pendingVerifications[$id]);
        }
        if ($invalidSesMapping) {
            unset($pendingVerifications[$id], $lastVerifications[$id], $verificationFaults[$id], $driftCounts[$id], $lastCommands[$id]);
            $diskMapping['state'] = 'invalid';
            $diskMapping['message'] = $connection['message'];
        }
        $operable = $enabled && (bool) $shelf['enabled'] && (bool) $shelf['commissioned'];
        $lastCommand = $lastCommands[$id] ?? null;
        $commandDue = $operable && !$invalidSesMapping && !$conflicts && !$commissioningActive && ($controlChanged || $lastCommand === null || ($previousTargets[$id] ?? null) !== $target || (time() - (int) $lastCommand) >= (int) $config['reassertSeconds']);
        if ($commissioningActive) {
            $write = ['state' => 'blocked', 'message' => $quietRecoveryActive ? 'Quiet 35% startup recovery is active' : 'Identify & test is active'];
        } elseif ($conflicts) {
            $write = ['state' => 'blocked', 'message' => 'Another fan controller is active'];
        } else {
            $write = ['state' => $operable ? 'idle' : 'disabled', 'message' => $operable ? 'No command due' : ((bool) $shelf['commissioned'] ? 'Shelf or controller disabled' : 'Shelf not commissioned')];
        }
        if ($commandDue) {
            $write = md12xx_controller_send((string) $shelf['serialPort'], $target, $dryRun);
            if (in_array($write['state'], ['sent', 'unconfirmed', 'dry-run'], true)) $lastCommands[$id] = time();
            if (in_array($write['state'], ['sent', 'unconfirmed'], true)) {
                $calibration = $identityVerified ? null : md12xx_controller_calibration($shelf);
                if ($identityVerified) {
                    if ($write['state'] !== 'sent') {
                        unset($lastCommands[$id]);
                        $write = ['state' => 'fault', 'message' => 'Serial command was not acknowledged; ' . $identityLabel . ' pairing has no live RPM proof'];
                    } else {
                        $write = ['state' => $identityMode . '-confirmed', 'message' => 'Serial command acknowledged; ' . $identityLabel . ' pairing, SES RPM response unverified'];
                    }
                } elseif ($calibration === null) {
                    $lastVerifications[$id] = ['target' => $target, 'state' => 'unverified', 'message' => 'Telemetry calibration is missing; run Identify & test again'];
                    $write = $lastVerifications[$id];
                } else {
                    $pendingVerifications[$id] = ['target' => $target, 'sentAt' => time(), 'calibration' => $calibration];
                    unset($verificationFaults[$id]);
                }
            }
        }

        if ($invalidSesMapping) {
            $telemetry = ['state' => 'fault', 'averageRpm' => null, 'fanCount' => 0, 'fanRpms' => [], 'message' => $diskMapping['message']];
            $write = $quietRecoveryActive ? ['state' => 'blocked', 'message' => 'Quiet 35% startup recovery is active'] : ['state' => 'fault', 'message' => $diskMapping['message']];
        }
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
        if ($operable && $telemetry['state'] === 'fault') {
            // A previous successful command cannot certify a newly failed fan.
            unset($lastVerifications[$id]);
            $write = ['state' => 'fault', 'message' => $telemetry['message']];
        }
        if ($operable && $write['state'] === 'fault') { $controllerState = 'fault'; $messages[] = $shelf['name'] . ': ' . $write['message']; }
        elseif ($operable && $write['state'] === 'unverified' && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': ' . $write['message']; }
        elseif ($operable && $write['state'] === 'pending' && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': ' . $write['message']; }
        elseif ($operable && $mappingChanged && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': automatic disk mapping changed'; }
        elseif ($operable && $mode === 'auto' && $mappingUnverified && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': automatic disk mapping unverified; using fail-safe'; }
        elseif ($operable && $identityVerified && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': ' . $identityLabel . ' pairing; SES fan response is not verified'; }
        elseif ($operable && $mode === 'auto' && $thermal['assignedCount'] === 0 && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': no assigned disks; using fail-safe'; }
        elseif ($operable && !empty($thermal['missingDisks']) && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': assigned disk inventory incomplete'; }
        elseif ($operable && !empty($thermal['missingTemperatures']) && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': active disk temperature unavailable'; }
        elseif ($operable && $mode === 'auto' && !$auto['usingCurve'] && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': ' . $auto['reason']; }
        elseif ($enabled && (bool) $shelf['enabled'] && !(bool) $shelf['commissioned'] && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': commissioning required'; }
        elseif ($enabled && (bool) $shelf['enabled'] && $telemetry['state'] !== 'normal' && $controllerState !== 'fault') { $controllerState = 'attention'; $messages[] = $shelf['name'] . ': ' . $telemetry['message']; }

        $shelfStates[] = [
            'id' => $id,
            'name' => $shelf['name'],
            'model' => $shelf['model'],
            'enabled' => (bool) $shelf['enabled'],
            'commissioned' => (bool) $shelf['commissioned'],
            'verificationMode' => $shelf['verificationMode'] ?? 'rpm',
            'serialPort' => $shelf['serialPort'],
            'sesDevice' => $sesDevice,
            'sesAddress' => $sesAddress,
            'sesIdentity' => $shelf['sesIdentity'],
            'diskAssignment' => $assignmentMode,
            'diskMappingState' => $diskMapping['state'],
            'diskMappingMessage' => $diskMapping['message'],
            'assignedDisks' => $assignedDisks,
            'assignedCount' => $thermal['assignedCount'],
            'assignedSeen' => $thermal['assignedSeen'],
            'missingDisks' => $thermal['missingDisks'],
            'missingTemperatures' => $thermal['missingTemperatures'],
            'activeDisks' => $thermal['activeDisks'],
            'temperatureC' => $thermal['temperatureC'] === null ? null : round($thermal['temperatureC'], 1),
            'temperatureSource' => $thermal['temperatureSource'],
            'diskInputFreshness' => $thermal['diskInputFreshness'],
            'diskPowerStateUnverified' => $thermal['diskPowerStateUnverified'],
            'reportedStandby' => $thermal['reportedStandby'],
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
        $previousAutoTargets[$id] = $mode === 'auto' && $operable && !$invalidSesMapping && !$conflicts && !$commissioningActive
            && $auto['usingCurve'] && $telemetry['state'] !== 'fault' && $write['state'] !== 'fault' ? $target : null;
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
            'startupRecovery35' => (bool) $config['startupRecovery35'],
            'quietRecoveryActive' => $quietRecoveryActive,
        ],
        'shelves' => $shelfStates,
    ];
    md12xx_controller_write_state($statePath, $state);
    if ($runOnce) break;
}
