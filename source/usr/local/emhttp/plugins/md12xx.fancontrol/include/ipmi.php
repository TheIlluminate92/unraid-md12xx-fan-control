<?php
declare(strict_types=1);
require_once __DIR__ . '/curves.php';

// Local chassis control only. No BMC passwords, network transports or arbitrary raw commands.
const COOLING_IPMI_CONFIG = '/boot/config/plugins/md12xx.fancontrol/ipmi.json';
const COOLING_IPMI_RUNTIME = '/var/run/md12xx.fancontrol';
const COOLING_IPMI_TOOL = '/usr/local/emhttp/plugins/md12xx.fancontrol/bin/ipmitool.bin';
const COOLING_IPMI_LEASE = '/boot/config/plugins/md12xx.fancontrol/ipmi-owned.json';
const COOLING_IPMI_RECOVERY = '/boot/config/plugins/md12xx.fancontrol/ipmi-recovery.json';
const COOLING_IPMI_REARM = '/boot/config/plugins/md12xx.fancontrol/ipmi-rearm-required.json';
const COOLING_IPMI_HOLD_SECONDS = 60;
const COOLING_IPMI_CONFIRMATION_LIMIT = 90;
function cooling_ipmi_host_id(): string {
    $uuid = strtolower(trim((string) @file_get_contents('/sys/class/dmi/id/product_uuid')));
    if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $uuid) || preg_match('/^(?:0+|f+)$/', str_replace('-', '', $uuid))) return '';
    return hash('sha256', $uuid);
}

function cooling_ipmi_read(string $path): array {
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function cooling_ipmi_store(string $path, array $data): void {
    $temp = $path . '.tmp.' . getmypid();
    if (file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false) throw new RuntimeException('Cannot save cooling state');
    chmod($temp, 0600);
    if (!rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Cannot activate cooling state'); }
}
function cooling_ipmi_rearm_required(): bool {
    clearstatcache();
    return file_exists(COOLING_IPMI_REARM) || is_file(COOLING_IPMI_RUNTIME . '/ipmi-fault-latched');
}

// Called with the command lock held, before a fault handback can release ownership.
function cooling_ipmi_require_rearm(): bool {
    @touch(COOLING_IPMI_RUNTIME . '/ipmi-fault-latched');
    clearstatcache();
    if (is_file(COOLING_IPMI_REARM)) return true;
    try {
        $marker = ['id' => bin2hex(random_bytes(16))];
        cooling_ipmi_store(COOLING_IPMI_REARM, $marker);
        if (cooling_ipmi_read(COOLING_IPMI_REARM) === $marker) return true;
    } catch (Throwable $error) {
        // A failed state write must not prevent the attempt to hand cooling back.
    }
    error_log('Cooling: could not persist the IPMI re-arm requirement; keep the ownership fence if present');
    return false;
}

function cooling_ipmi_active_fault(): array {
    if (!cooling_ipmi_rearm_required()) return [];
    $runtime = cooling_ipmi_read(COOLING_IPMI_RUNTIME . '/ipmi-fault.json');
    if ($runtime !== []) return $runtime;
    $id = cooling_ipmi_read(COOLING_IPMI_REARM)['id'] ?? null;
    $history = cooling_ipmi_read(dirname(COOLING_IPMI_CONFIG) . '/ipmi-last-fault.json');
    return is_string($id) && ($history['id'] ?? null) === $id ? $history : [];
}

// Record one first-cause snapshot per incident. Incident history never grants or blocks ownership.
function cooling_ipmi_latch(string $reason, array $evidence = []): void {
    $record = cooling_ipmi_active_fault();
    if (is_file(COOLING_IPMI_REARM) && $record !== []) return;
    $durable = cooling_ipmi_require_rearm();
    $record = ($record ?: ['occurredAt' => time(), 'reason' => $reason, 'evidence' => $evidence]);
    if ($durable) $record['id'] = cooling_ipmi_read(COOLING_IPMI_REARM)['id'] ?? null;
    foreach ([COOLING_IPMI_RUNTIME . '/ipmi-fault.json', dirname(COOLING_IPMI_CONFIG) . '/ipmi-last-fault.json'] as $path) {
        try { cooling_ipmi_store($path, $record); }
        catch (Throwable $error) { error_log('Cooling: could not preserve IPMI fault evidence'); }
    }
}

function cooling_ipmi_status_stale(array $state): bool {
    $now = time();
    $generatedAt = $state['generatedAt'] ?? null;
    $mtime = @filemtime(COOLING_IPMI_RUNTIME . '/ipmi-status.json');
    return !is_int($generatedAt) || $generatedAt <= 0 || $generatedAt > $now || $now - $generatedAt > 40
        || $mtime === false || $mtime <= 0 || $mtime > $now || $now - $mtime > 40;
}
function cooling_ipmi_config(array $input): array {
    $mode = $input['mode'] ?? 'firmware';
    if (!in_array($mode, ['firmware', 'auto', 'manual'], true)) throw new InvalidArgumentException('Invalid IPMI control mode');
    $speed = filter_var($input['manualSpeed'] ?? 60, FILTER_VALIDATE_INT);
    if ($speed === false || $speed < 20 || $speed > 100 || $speed % 10 !== 0) throw new InvalidArgumentException('Manual speed must be 20–100%, in steps of 10');
    if (isset($input['enabled']) && !is_bool($input['enabled'])) throw new InvalidArgumentException('Invalid enable setting');
    $curveMode = $input['curveMode'] ?? 'global';
    if (!in_array($curveMode, ['global', 'individual'], true)) throw new InvalidArgumentException('Curve source must be Global or Individual');
    return ['enabled' => $input['enabled'] ?? false, 'mode' => $mode, 'manualSpeed' => $speed,
        'curveMode' => $curveMode, 'curve' => cooling_validate_curve($input['curve'] ?? cooling_default_curve(), 15),
        'hysteresisC' => cooling_validate_hysteresis($input['hysteresisC'] ?? 1.0)];
}
function cooling_ipmi_profile(string $vendor, string $model): bool {
    return trim($vendor) === 'Dell Inc.' && in_array(trim($model), ['PowerEdge R730', 'PowerEdge R730xd'], true);
}
function cooling_ipmi_cpu_count(string $root = '/sys/devices/system/cpu'): int {
    $packages = [];
    foreach (glob($root . '/cpu[0-9]*/topology/physical_package_id') ?: [] as $path) {
        $id = trim((string) @file_get_contents($path));
        if (ctype_digit($id)) $packages[$id] = true;
    }
    return count($packages);
}
function cooling_ipmi_read_budget(float $now, ?float $deadline): float {
    if (!is_finite($now) || ($deadline !== null && !is_finite($deadline))) return 0.0;
    return $deadline === null ? 15.0 : max(0.0, min(15.0, $deadline - $now));
}
// Runtime incident history contains no trusted samples and cannot authorize control after restart.
function cooling_ipmi_incident_history(array $record, float $now): array {
    if (!is_finite($now) || $now < 0 || ($record['schema'] ?? null) !== 1 || !is_numeric($record['at'] ?? null) || !is_finite((float)$record['at'])
        || $record['at'] < 0 || $record['at'] > $now || !is_array($record['episodes'] ?? null))
        throw new RuntimeException('Invalid persisted sensor incident history');
    $last = -1.0; $episodes = [];
    foreach ($record['episodes'] as $t) {
        if (!is_numeric($t) || !is_finite((float)$t) || $t < $last || $t < 0 || $t > $now)
            throw new RuntimeException('Invalid persisted sensor episode time');
        $last = (float)$t;
        if ($now - $t <= 300) $episodes[] = (float)$t;
    }
    return ['episodes' => $episodes];
}
// A status report alone cannot assert cleanup. The private group wrapper must
// also report signal9 from the explicitly forked util-linux setsid --wait monitor.
function cooling_ipmi_runner_exit(int $monitorExit, string $report): ?int {
    if ($monitorExit !== 9 || !preg_match('/^cooling-ipmi-exit:([0-9]{1,3})\n?$/D', $report, $match)) return null;
    $code = (int)$match[1];
    return $code <= 255 ? $code : null;
}
function cooling_ipmi_run(array $args): array {
    $allowed = $args === ['sdr', 'elist', 'all'] || $args === ['mc', 'info']
        || $args === ['raw', '0x30', '0x30', '0x01', '0x01'] || $args === ['raw', '0x30', '0x30', '0x01', '0x00'];
    if (count($args) === 6 && array_slice($args, 0, 5) === ['raw', '0x30', '0x30', '0x02', '0xff'] && preg_match('/^0x[0-9a-f]{2}$/', $args[5])) {
        $speed = hexdec(substr($args[5], 2));
        $allowed = $speed >= 15 && $speed <= 100;
    }
    if (!$allowed) throw new InvalidArgumentException('Unsupported local IPMI operation');
    $budget = 15.0;
    if ($args === ['sdr', 'elist', 'all'] || $args === ['mc', 'info']) {
        $deadline = $GLOBALS['cooling_ipmi_read_deadline'] ?? null;
        $budget = cooling_ipmi_read_budget(hrtime(true) / 1e9, $deadline);
    }
    if ($budget <= 0.25) return ['code' => 124, 'output' => 'Sensor confirmation deadline exhausted'];
    // Soft TERM plus mandatory KILL fits inside the total budget, including TERM-resistant tools.
    $soft = sprintf('%.6fs', $budget - 0.25);
    $wrapper = dirname(__DIR__) . '/scripts/ipmi-runner.sh';
    if (!is_executable('/usr/bin/setsid') || !is_executable('/bin/kill') || !is_readable($wrapper))
        return ['code' => 1, 'output' => 'IPMI process-group runner prerequisites unavailable'];
    $pipes = [];
    $process = proc_open(array_merge(['/usr/bin/setsid', '--fork', '--wait', '/bin/sh', $wrapper, $soft, COOLING_IPMI_TOOL, '-I', 'open'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return ['code' => 1, 'output' => 'IPMI tool could not start'];
    fclose($pipes[0]);
    // SDR output is bounded by the local BMC's sensor inventory; both pipes are drained concurrently.
    foreach ([1, 2, 3] as $n) stream_set_blocking($pipes[$n], false);
    $out = ''; $report = '';
    do {
        foreach ([1, 2, 3] as $n) {
            $chunk = stream_get_contents($pipes[$n]);
            if ($n === 3) $report = substr($report . $chunk, 0, 256);
            else $out = substr($out . $chunk, 0, 262144);
        }
        $status = proc_get_status($process);
        if ($status['running']) usleep(10000);
    } while ($status['running']);
    foreach ([1, 2, 3] as $n) {
        $chunk = stream_get_contents($pipes[$n]);
        if ($n === 3) $report = substr($report . $chunk, 0, 256);
        else $out = substr($out . $chunk, 0, 262144);
        fclose($pipes[$n]);
    }
    $closed = proc_close($process);
    $monitorExit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
    $exit = cooling_ipmi_runner_exit($monitorExit, $report);
    return $exit === null ? ['code' => 1, 'output' => 'IPMI process-group cleanup was not confirmed']
        : ['code' => $exit, 'output' => $out];
}
function cooling_ipmi_sensors(string $output, int $expectedCpus): array {
    $cpus = []; $fans = []; $inlet = null; $bad = []; $rows = []; $hardFault = $expectedCpus <= 0; $thermal = false; $inletRows = 0; $cpuEntitiesSeen = []; $cpuIdsSeen = [];
    foreach (preg_split('/\R/', $output) as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 5) continue;
        [$name, , $status, $entity, $reading] = $parts;
        $cpu = preg_match('/^3\./', $entity) === 1 && preg_match('/temp/i', $name);
        $fan = preg_match('/^fan\s*\d+/i', $name) === 1;
        $isInlet = preg_match('/inlet.*temp/i', $name) === 1;
        if (!$cpu && !$fan && !$isInlet) continue;
        if ($isInlet && ++$inletRows > 1) $hardFault = true;
        if ($cpu) {
            $sensorId = strtolower($parts[1]);
            if (isset($cpuEntitiesSeen[$entity]) || isset($cpuIdsSeen[$sensorId])) $hardFault = true;
            $cpuEntitiesSeen[$entity] = true; $cpuIdsSeen[$sensorId] = true;
        }
        $rows[] = ['name' => substr($name, 0, 64), 'id' => substr($parts[1], 0, 16), 'entity' => substr($entity, 0, 32), 'status' => substr($status, 0, 32), 'reading' => substr($reading, 0, 80)];
        $numeric = preg_match('/^(-?\d+(?:\.\d+)?)\s+(RPM|degrees C)$/i', $reading, $match) === 1;
        if ($numeric && strcasecmp($match[2], 'degrees C') === 0 && (($cpu && (float) $match[1] >= 70) || ($isInlet && (float) $match[1] >= 35))) $thermal = true;
        if ($status !== 'ok' || !$numeric) {
            $bad[] = $name;
            if (!in_array($status, ['ok', 'ns', 'na'], true) ||
                !in_array(strtolower($reading), ['', 'no reading', 'not available', 'na', 'ns'], true)) $hardFault = true;
            continue;
        }
        $value = (float) $match[1];
        if ($cpu && strcasecmp($match[2], 'degrees C') === 0) {
            if (array_key_exists($entity, $cpus) || $value <= 0 || $value > 120) { $bad[] = $name; $hardFault = true; }
            $cpus[$entity] = $value;
        }
        if ($isInlet && strcasecmp($match[2], 'degrees C') === 0) $inlet = $value;
        if ($fan && strcasecmp($match[2], 'RPM') === 0) {
            if (array_key_exists($name, $fans) || $value <= 0) { $bad[] = $name; $hardFault = true; }
            $fans[$name] = $value;
        }
    }
    $healthy = !$hardFault && count($cpus) === $expectedCpus && count($fans) >= 6 && $inlet !== null && $inlet > -10 && $inlet < 80 && !$bad;
    $identities = array_map(static fn(array $row): array => [$row['id'], $row['entity'], $row['name']], $rows);
    sort($identities);
    return ['healthy' => $healthy, 'cpuTemperaturesC' => array_values($cpus), 'cpuMaxC' => $cpus ? max($cpus) : null,
        'inletC' => $inlet, 'fanRpms' => array_values($fans), 'averageRpm' => $fans ? (int) round(array_sum($fans) / count($fans)) : null,
        'message' => $healthy ? '' : 'Complete healthy CPU, inlet and six-fan telemetry is required',
        'observedThermal' => $thermal, 'hardFault' => $hardFault, 'sensorIdentities' => $identities,
        'diagnostics' => ['expectedCpus' => $expectedCpus, 'acceptedCpuEntities' => array_keys($cpus), 'rejectedNames' => $bad, 'rows' => array_slice($rows, 0, 32)]];
}
/** Delay manual intervention only: every untrusted/pending sample selects firmware. */
function cooling_ipmi_sensor_guard(array $state, array $sensors, bool $fresh, float $now, bool $allowRecovery = true): array {
    $decision = static function (array $s, bool $pending, bool $latch, string $reason): array {
        return ['state' => $s, 'pending' => $pending, 'latch' => $latch, 'reason' => $reason];
    };
    if (!is_finite($now) || $now < 0 || (isset($state['at']) && $now < $state['at']))
        return $decision([], false, true, 'Invalid or reversed monotonic sample time');
    $state['at'] = $now;
    if (($sensors['observedThermal'] ?? false) || (is_numeric($sensors['cpuMaxC'] ?? null) && $sensors['cpuMaxC'] >= 70)
        || (is_numeric($sensors['inletC'] ?? null) && $sensors['inletC'] >= 35))
        return $decision($state, false, true, 'Thermal limit: returning control to iDRAC');
    if ($sensors['hardFault'] ?? false)
        return $decision($state, false, true, 'Invalid or ambiguous sensor identity/value');
    // One automatic recovery per rolling five minutes, including after a process restart.
    $state['episodes'] = array_values(array_filter($state['episodes'] ?? [], static fn($t) => $now - $t <= 300));
    $complete = $fresh && ($sensors['healthy'] ?? false);
    $pending = isset($state['pendingSince']);
    if ($pending && $now - ($state['handbackAt'] ?? $state['pendingSince']) >= COOLING_IPMI_CONFIRMATION_LIMIT)
        return $decision($state, false, true, 'iDRAC recovery exceeded 90 seconds');
    if ($complete && isset($state['trustedIdentities']) && $state['trustedIdentities'] !== $sensors['sensorIdentities'])
        return $decision($state, false, true, 'Trusted sensor identities changed');
    if (!$complete) {
        if (!$pending) {
            if (!$allowRecovery || !isset($state['trustedAt']) || $now - $state['trustedAt'] > 15)
                return $decision($state, false, true, 'No recent complete sample for bounded confirmation');
            $state['episodes'][] = $now;
            if (count($state['episodes']) >= 2)
                return $decision($state, false, true, 'Sensor fault recurred within five minutes of recovery');
            $state['pendingSince'] = $now;
            $state['badSamples'] = 0;
        }
        $state['healthyStreak'] = 0;
        unset($state['lastRecoveryAt']);
        $state['badSamples'] = ($state['badSamples'] ?? 0) + 1;
        if ($state['badSamples'] >= 3)
            return $decision($state, false, true, 'Three incomplete or stale samples in one incident');
        return $decision($state, true, false, 'Sensor confirmation pending: iDRAC automatic cooling');
    }
    if ($pending) {
        if (!isset($state['handbackAt']) || $now - $state['handbackAt'] < COOLING_IPMI_HOLD_SECONDS)
            return $decision($state, true, false, 'iDRAC automatic cooling: 60-second hold');
        if (!isset($state['lastRecoveryAt']) || $now - $state['lastRecoveryAt'] >= 5) {
            $state['healthyStreak'] = ($state['healthyStreak'] ?? 0) + 1;
            $state['lastRecoveryAt'] = $now;
        }
        if ($state['healthyStreak'] < 2)
            return $decision($state, true, false, 'Waiting for two fresh complete samples: iDRAC automatic cooling');
        $state['episodes'] = [$now];
        unset($state['pendingSince'], $state['handbackAt'], $state['badSamples'], $state['healthyStreak'], $state['lastRecoveryAt']);
    }
    $state['trustedIdentities'] = $sensors['sensorIdentities'];
    $state['trustedAt'] = $now;
    return $decision($state, false, false, 'Complete fresh sensor sample');
}
function cooling_ipmi_target(array $config, array $sensors, ?int $previous = null): array {
    if (!$config['enabled'] || $config['mode'] === 'firmware') return ['mode' => 'firmware', 'percent' => null, 'reason' => 'iDRAC automatic control'];
    if (!$sensors['healthy']) return ['mode' => 'firmware', 'percent' => null, 'reason' => 'Sensor fault: returning control to iDRAC'];
    if ($sensors['cpuMaxC'] >= 70 || $sensors['inletC'] >= 35) return ['mode' => 'firmware', 'percent' => null, 'reason' => 'Thermal limit: returning control to iDRAC'];
    $speed = $config['mode'] === 'manual' ? $config['manualSpeed'] : 20;
    if ($config['mode'] === 'auto') $speed = cooling_curve_target($config['effectiveCurve'] ?? $config['curve'], (float) $sensors['cpuMaxC'], $previous, (float) ($config['effectiveHysteresisC'] ?? $config['hysteresisC']));
    return ['mode' => $config['mode'], 'percent' => $speed, 'reason' => $config['mode'] === 'manual' ? 'Manual target with thermal guard' : 'CPU/inlet temperature curve'];
}
function cooling_ipmi_restore(callable $run, string $lease, bool $releaseLease = true): bool {
    if (!is_file($lease)) return true;
    if (cooling_ipmi_host_id() === '' || (cooling_ipmi_read($lease)['host'] ?? '') !== cooling_ipmi_host_id()) return false;
    $result = $run(['raw', '0x30', '0x30', '0x01', '0x01']);
    if ($result['code'] !== 0) return false;
    return !$releaseLease || unlink($lease);
}
function cooling_ipmi_apply(array $target, callable $run, string $lease, ?callable $beforeFailedHandback = null): array {
    if ($target['mode'] === 'firmware') {
        $ok = cooling_ipmi_restore($run, $lease, !cooling_ipmi_rearm_required() || is_file(COOLING_IPMI_REARM));
        return ['ok' => $ok, 'mode' => $ok ? 'firmware' : 'unknown', 'message' => $ok ? $target['reason'] : 'iDRAC restoration failed; manual recovery required'];
    }
    // Record ownership before entering manual mode so a crash can always trigger recovery.
    if (cooling_ipmi_host_id() === '') return ['ok' => false, 'mode' => 'unknown', 'message' => 'A valid local chassis identity is required'];
    if (!is_file($lease)) cooling_ipmi_store($lease, ['owned' => true, 'host' => cooling_ipmi_host_id(), 'updatedAt' => time()]);
    if ((cooling_ipmi_read($lease)['host'] ?? '') !== cooling_ipmi_host_id()) return ['ok' => false, 'mode' => 'unknown', 'message' => 'Saved cooling ownership belongs to another chassis; review required'];
    $mode = $run(['raw', '0x30', '0x30', '0x01', '0x00']);
    $speed = $mode['code'] === 0 ? $run(['raw', '0x30', '0x30', '0x02', '0xff', sprintf('0x%02x', $target['percent'])]) : ['code' => 1];
    if ($speed['code'] !== 0) {
        $releaseLease = $beforeFailedHandback === null || $beforeFailedHandback();
        $restored = cooling_ipmi_restore($run, $lease, $releaseLease);
        return ['ok' => false, 'mode' => $restored ? 'firmware' : 'unknown', 'modeExitCode' => $mode['code'],
            'speedExitCode' => $mode['code'] === 0 ? $speed['code'] : null,
            'message' => $restored ? 'Fan command failed; returned to iDRAC' : 'Fan command and iDRAC restoration failed; manual recovery required'];
    }
    return ['ok' => true, 'mode' => $target['mode'], 'message' => 'Command acknowledged; RPM telemetry is shown separately'];
}
function cooling_ipmi_capability(): array {
    $vendor = trim((string) @file_get_contents('/sys/class/dmi/id/sys_vendor'));
    $model = trim((string) @file_get_contents('/sys/class/dmi/id/product_name'));
    $supported = cooling_ipmi_profile($vendor, $model);
    return ['supported' => $supported, 'available' => $supported && cooling_ipmi_host_id() !== '' && is_executable(COOLING_IPMI_TOOL) && (file_exists('/dev/ipmi0') || file_exists('/dev/ipmi/0')),
        'model' => $model, 'cpuCount' => cooling_ipmi_cpu_count(), 'message' => $supported ? 'Local IPMI; no network credentials required' : 'IPMI control currently supports Dell PowerEdge R730/R730xd only'];
}
function cooling_ipmi_public(): array {
    $state = cooling_ipmi_read(COOLING_IPMI_RUNTIME . '/ipmi-status.json');
    $capability = cooling_ipmi_capability();
    $global = cooling_validate_curve(cooling_ipmi_read('/boot/config/plugins/md12xx.fancontrol/config.json')['curve'] ?? cooling_default_curve());
    return array_merge($state, $capability, ['latched' => cooling_ipmi_rearm_required(), 'rearmId' => cooling_ipmi_read(COOLING_IPMI_REARM)['id'] ?? null, 'activeFault' => cooling_ipmi_active_fault(), 'lastFault' => cooling_ipmi_read(dirname(COOLING_IPMI_CONFIG) . '/ipmi-last-fault.json'), 'message' => $state['message'] ?? $capability['message'], 'capabilityMessage' => $capability['message'], 'config' => cooling_ipmi_config(cooling_ipmi_read(COOLING_IPMI_CONFIG)), 'globalCurve' => $global,
        'stale' => cooling_ipmi_status_stale($state)]);
}
