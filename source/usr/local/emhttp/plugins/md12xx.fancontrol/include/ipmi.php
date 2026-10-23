<?php
declare(strict_types=1);
require_once __DIR__ . '/curves.php';

// Local chassis control only. No BMC passwords, network transports or arbitrary raw commands.
const COOLING_IPMI_CONFIG = '/boot/config/plugins/md12xx.fancontrol/ipmi.json';
const COOLING_IPMI_RUNTIME = '/var/run/md12xx.fancontrol';
const COOLING_IPMI_TOOL = '/usr/local/emhttp/plugins/md12xx.fancontrol/bin/ipmitool.bin';
const COOLING_IPMI_LEASE = '/boot/config/plugins/md12xx.fancontrol/ipmi-owned.json';
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
function cooling_ipmi_config(array $input): array {
    $mode = $input['mode'] ?? 'firmware';
    if (!in_array($mode, ['firmware', 'auto', 'manual'], true)) throw new InvalidArgumentException('Invalid IPMI control mode');
    $speed = filter_var($input['manualSpeed'] ?? 60, FILTER_VALIDATE_INT);
    if ($speed === false || $speed < 20 || $speed > 100 || $speed % 10 !== 0) throw new InvalidArgumentException('Manual speed must be 20–100%, in steps of 10');
    if (isset($input['enabled']) && !is_bool($input['enabled'])) throw new InvalidArgumentException('Invalid enable setting');
    $curveMode = $input['curveMode'] ?? 'global';
    if (!in_array($curveMode, ['global', 'individual'], true)) throw new InvalidArgumentException('Curve source must be Global or Individual');
    return ['enabled' => $input['enabled'] ?? false, 'mode' => $mode, 'manualSpeed' => $speed,
        'curveMode' => $curveMode, 'curve' => cooling_validate_curve($input['curve'] ?? cooling_default_curve(), 15)];
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
function cooling_ipmi_run(array $args): array {
    $allowed = $args === ['sdr', 'elist', 'all'] || $args === ['mc', 'info']
        || $args === ['raw', '0x30', '0x30', '0x01', '0x01'] || $args === ['raw', '0x30', '0x30', '0x01', '0x00'];
    if (count($args) === 6 && array_slice($args, 0, 5) === ['raw', '0x30', '0x30', '0x02', '0xff'] && preg_match('/^0x[0-9a-f]{2}$/', $args[5])) {
        $speed = hexdec(substr($args[5], 2));
        $allowed = $speed >= 15 && $speed <= 100;
    }
    if (!$allowed) throw new InvalidArgumentException('Unsupported local IPMI operation');
    $pipes = [];
    $process = proc_open(array_merge(['/usr/bin/timeout', '15', COOLING_IPMI_TOOL, '-I', 'open'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return ['code' => 1, 'output' => 'IPMI tool could not start'];
    fclose($pipes[0]);
    // SDR output is bounded by the local BMC's sensor inventory; both pipes are drained concurrently.
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = '';
    do {
        foreach ([1, 2] as $n) $out .= stream_get_contents($pipes[$n]);
        $status = proc_get_status($process);
        if ($status['running']) usleep(10000);
    } while ($status['running']);
    foreach ([1, 2] as $n) { $out .= stream_get_contents($pipes[$n]); fclose($pipes[$n]); }
    $closed = proc_close($process);
    return ['code' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closed, 'output' => substr($out, 0, 262144)];
}
function cooling_ipmi_sensors(string $output, int $expectedCpus): array {
    $cpus = []; $fans = []; $inlet = null; $bad = [];
    foreach (preg_split('/\R/', $output) as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 5) continue;
        [$name, , $status, $entity, $reading] = $parts;
        $cpu = preg_match('/^3\./', $entity) === 1 && preg_match('/temp/i', $name);
        $fan = preg_match('/^fan\s*\d+/i', $name) === 1;
        $isInlet = preg_match('/inlet.*temp/i', $name) === 1;
        if (!$cpu && !$fan && !$isInlet) continue;
        $numeric = preg_match('/^(-?\d+(?:\.\d+)?)\s+(RPM|degrees C)$/i', $reading, $match) === 1;
        if ($status !== 'ok' || !$numeric) { $bad[] = $name; continue; }
        $value = (float) $match[1];
        if ($cpu && strcasecmp($match[2], 'degrees C') === 0) {
            if (array_key_exists($entity, $cpus) || $value <= 0 || $value > 120) $bad[] = $name;
            $cpus[$entity] = $value;
        }
        if ($isInlet && strcasecmp($match[2], 'degrees C') === 0) $inlet = $value;
        if ($fan && strcasecmp($match[2], 'RPM') === 0) {
            if (array_key_exists($name, $fans) || $value <= 0) $bad[] = $name;
            $fans[$name] = $value;
        }
    }
    $healthy = $expectedCpus > 0 && count($cpus) === $expectedCpus && count($fans) >= 6 && $inlet !== null && $inlet > -10 && $inlet < 80 && !$bad;
    return ['healthy' => $healthy, 'cpuTemperaturesC' => array_values($cpus), 'cpuMaxC' => $cpus ? max($cpus) : null,
        'inletC' => $inlet, 'fanRpms' => array_values($fans), 'averageRpm' => $fans ? (int) round(array_sum($fans) / count($fans)) : null,
        'message' => $healthy ? '' : 'Complete healthy CPU, inlet and six-fan telemetry is required'];
}
function cooling_ipmi_target(array $config, array $sensors): array {
    if (!$config['enabled'] || $config['mode'] === 'firmware') return ['mode' => 'firmware', 'percent' => null, 'reason' => 'iDRAC automatic control'];
    if (!$sensors['healthy']) return ['mode' => 'firmware', 'percent' => null, 'reason' => 'Sensor fault: returning control to iDRAC'];
    if ($sensors['cpuMaxC'] >= 70 || $sensors['inletC'] >= 35) return ['mode' => 'firmware', 'percent' => null, 'reason' => 'Thermal limit: returning control to iDRAC'];
    $speed = $config['mode'] === 'manual' ? $config['manualSpeed'] : 20;
    if ($config['mode'] === 'auto') $speed = cooling_curve_speed($config['effectiveCurve'] ?? $config['curve'], (float) $sensors['cpuMaxC']);
    return ['mode' => $config['mode'], 'percent' => $speed, 'reason' => $config['mode'] === 'manual' ? 'Manual target with thermal guard' : 'CPU/inlet temperature curve'];
}
function cooling_ipmi_restore(callable $run, string $lease): bool {
    if (!is_file($lease)) return true;
    if (cooling_ipmi_host_id() === '' || (cooling_ipmi_read($lease)['host'] ?? '') !== cooling_ipmi_host_id()) return false;
    $result = $run(['raw', '0x30', '0x30', '0x01', '0x01']);
    if ($result['code'] !== 0) return false;
    return unlink($lease);
}
function cooling_ipmi_apply(array $target, callable $run, string $lease): array {
    if ($target['mode'] === 'firmware') {
        $ok = cooling_ipmi_restore($run, $lease);
        return ['ok' => $ok, 'mode' => $ok ? 'firmware' : 'unknown', 'message' => $ok ? $target['reason'] : 'iDRAC restoration failed; manual recovery required'];
    }
    // Record ownership before entering manual mode so a crash can always trigger recovery.
    if (cooling_ipmi_host_id() === '') return ['ok' => false, 'mode' => 'unknown', 'message' => 'A valid local chassis identity is required'];
    if (!is_file($lease)) cooling_ipmi_store($lease, ['owned' => true, 'host' => cooling_ipmi_host_id(), 'updatedAt' => time()]);
    if ((cooling_ipmi_read($lease)['host'] ?? '') !== cooling_ipmi_host_id()) return ['ok' => false, 'mode' => 'unknown', 'message' => 'Saved cooling ownership belongs to another chassis; review required'];
    $mode = $run(['raw', '0x30', '0x30', '0x01', '0x00']);
    $speed = $mode['code'] === 0 ? $run(['raw', '0x30', '0x30', '0x02', '0xff', sprintf('0x%02x', $target['percent'])]) : ['code' => 1];
    if ($speed['code'] !== 0) {
        $restored = cooling_ipmi_restore($run, $lease);
        return ['ok' => false, 'mode' => $restored ? 'firmware' : 'unknown', 'message' => $restored ? 'Fan command failed; returned to iDRAC' : 'Fan command and iDRAC restoration failed; manual recovery required'];
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
    return array_merge($state, $capability, ['message' => $state['message'] ?? $capability['message'], 'capabilityMessage' => $capability['message'], 'config' => cooling_ipmi_config(cooling_ipmi_read(COOLING_IPMI_CONFIG)), 'globalCurve' => $global,
        'stale' => !isset($state['generatedAt']) || time() - (int) $state['generatedAt'] > 40]);
}
