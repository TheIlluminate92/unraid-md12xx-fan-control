<?php

declare(strict_types=1);

const MD12XX_CONFIG_FILE = '/boot/config/plugins/md12xx.fancontrol/config.json';
const MD12XX_STATE_FILE = '/var/run/md12xx.fancontrol/status.json';
const MD12XX_PID_FILE = '/var/run/md12xx.fancontrol/controller.pid';
const MD12XX_DISCOVERY_FILE = '/var/run/md12xx.fancontrol/discovery.json';
const MD12XX_DISCOVERY_PID_FILE = '/var/run/md12xx.fancontrol/discovery.pid';
const MD12XX_RUNTIME_DIR = '/var/run/md12xx.fancontrol';
const MD12XX_COMMISSION_JOB_DIR = '/var/run/md12xx.fancontrol/commission-jobs';

function md12xx_defaults(): array
{
    return [
        'enabled' => false,
        'mode' => 'auto',
        'manualSpeed' => 20,
        'pollSeconds' => 5,
        'reassertSeconds' => 900,
        'sensorFailureSpeed' => 50,
        'hysteresisC' => 1.0,
        'discovery' => [
            'autoProbeKnownFtdi' => false,
            'intervalSeconds' => 300,
            'responseSeconds' => 3,
        ],
        'curve' => [
            ['temperatureC' => 0.0, 'speed' => 20],
            ['temperatureC' => 35.0, 'speed' => 25],
            ['temperatureC' => 45.0, 'speed' => 30],
            ['temperatureC' => 50.0, 'speed' => 50],
        ],
        'legacyContainerNames' => ['MD1200-Fan-Controller'],
        'shelves' => [],
    ];
}

function md12xx_read_json(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function md12xx_read_config(?string $path = null): array
{
    $configured = md12xx_read_json($path ?: MD12XX_CONFIG_FILE);
    return array_replace(md12xx_defaults(), $configured);
}

function md12xx_slug(string $value): string
{
    $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value), '-'));
    return $slug !== '' ? substr($slug, 0, 48) : 'shelf';
}

function md12xx_validate_config(array $input): array
{
    $defaults = md12xx_defaults();
    $config = $defaults;
    $config['enabled'] = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    $config['mode'] = strtolower((string) ($input['mode'] ?? 'auto')) === 'manual' ? 'manual' : 'auto';

    $manualSpeed = (int) ($input['manualSpeed'] ?? 20);
    if (!in_array($manualSpeed, [20, 30, 40, 50, 60, 70, 80, 90, 100], true)) {
        throw new InvalidArgumentException('Manual speed must be between 20 and 100 percent in 10 percent increments');
    }
    $config['manualSpeed'] = $manualSpeed;
    $config['pollSeconds'] = max(5, min(300, (int) ($input['pollSeconds'] ?? 5)));
    $config['reassertSeconds'] = max(60, min(3600, (int) ($input['reassertSeconds'] ?? 900)));
    $config['sensorFailureSpeed'] = max(20, min(100, (int) ($input['sensorFailureSpeed'] ?? 50)));
    $config['hysteresisC'] = max(0.0, min(10.0, (float) ($input['hysteresisC'] ?? 1.0)));
    $discovery = is_array($input['discovery'] ?? null) ? $input['discovery'] : [];
    $config['discovery'] = [
        'autoProbeKnownFtdi' => filter_var($discovery['autoProbeKnownFtdi'] ?? false, FILTER_VALIDATE_BOOL),
        'intervalSeconds' => max(60, min(3600, (int) ($discovery['intervalSeconds'] ?? 300))),
        'responseSeconds' => max(1, min(10, (int) ($discovery['responseSeconds'] ?? 3))),
    ];

    $curve = is_array($input['curve'] ?? null) ? array_values($input['curve']) : $defaults['curve'];
    if (count($curve) < 2 || count($curve) > 10) {
        throw new InvalidArgumentException('The Auto curve must contain between 2 and 10 steps');
    }
    $validatedCurve = [];
    foreach ($curve as $step) {
        if (!is_array($step) || !is_numeric($step['temperatureC'] ?? null) || !is_numeric($step['speed'] ?? null)) {
            throw new InvalidArgumentException('Every Auto curve step requires a temperature and speed');
        }
        $temperature = max(0.0, min(100.0, (float) $step['temperatureC']));
        $speed = max(20, min(100, (int) $step['speed']));
        $validatedCurve[] = ['temperatureC' => $temperature, 'speed' => $speed];
    }
    usort($validatedCurve, static fn(array $a, array $b): int => $a['temperatureC'] <=> $b['temperatureC']);
    $previousTemperature = null;
    $previousSpeed = null;
    foreach ($validatedCurve as $step) {
        if ($previousTemperature !== null && $step['temperatureC'] <= $previousTemperature) {
            throw new InvalidArgumentException('Auto curve temperatures must be unique and increasing');
        }
        if ($previousSpeed !== null && $step['speed'] < $previousSpeed) {
            throw new InvalidArgumentException('Auto curve fan speeds cannot decrease as temperature rises');
        }
        $previousTemperature = $step['temperatureC'];
        $previousSpeed = $step['speed'];
    }
    $config['curve'] = $validatedCurve;
    $config['sensorFailureSpeed'] = max($config['sensorFailureSpeed'], max(array_column($validatedCurve, 'speed')));

    $containers = is_array($input['legacyContainerNames'] ?? null) ? $input['legacyContainerNames'] : $defaults['legacyContainerNames'];
    $config['legacyContainerNames'] = array_values(array_unique(array_filter(array_map(
        static fn($name): string => substr(trim((string) $name), 0, 128),
        $containers
    ))));

    $shelves = is_array($input['shelves'] ?? null) ? array_values($input['shelves']) : [];
    if (count($shelves) > 16) throw new InvalidArgumentException('At most 16 shelves are supported');
    $ids = [];
    $validatedShelves = [];
    foreach ($shelves as $index => $shelf) {
        if (!is_array($shelf)) throw new InvalidArgumentException('Invalid shelf configuration');
        $id = md12xx_slug((string) ($shelf['id'] ?? ('shelf-' . ($index + 1))));
        if (isset($ids[$id])) throw new InvalidArgumentException('Shelf identifiers must be unique');
        $ids[$id] = true;

        $model = strtoupper(trim((string) ($shelf['model'] ?? 'MD1200')));
        if (!in_array($model, ['MD1200', 'MD1220'], true)) throw new InvalidArgumentException('Shelf model must be MD1200 or MD1220');
        $port = trim((string) ($shelf['serialPort'] ?? ''));
        if ($port !== '' && (!str_starts_with($port, '/dev/serial/by-id/') || str_contains($port, "\0"))) {
            throw new InvalidArgumentException('Serial adapters must use a persistent /dev/serial/by-id path');
        }
        $sesDevice = trim((string) ($shelf['sesDevice'] ?? ''));
        if ($sesDevice !== '' && !preg_match('#^/dev/sg[0-9]+$#', $sesDevice)) throw new InvalidArgumentException('Invalid SES device');
        $sesAddress = trim((string) ($shelf['sesAddress'] ?? ''));
        if ($sesAddress !== '' && !preg_match('/^[0-9]+:[0-9]+:[0-9]+:[0-9]+$/', $sesAddress)) throw new InvalidArgumentException('Invalid stable SCSI address');

        $diskAssignment = strtolower(trim((string) ($shelf['diskAssignment'] ?? '')));
        if ($diskAssignment === '') {
            // Preserve pre-0.1.4 configurations as manual mappings. Newly added
            // shelves explicitly opt into automatic enclosure mapping in the UI.
            $diskAssignment = !empty($shelf['disks']) ? 'manual' : 'automatic';
        }
        if (!in_array($diskAssignment, ['automatic', 'manual'], true)) {
            throw new InvalidArgumentException('Disk assignment must be automatic or manual');
        }

        $disks = is_array($shelf['disks'] ?? null) ? $shelf['disks'] : [];
        $disks = array_values(array_unique(array_filter(array_map(static function ($disk): string {
            $name = strtolower(trim((string) $disk));
            return preg_match('/^(parity2?|disk[0-9]+|cache|[a-z0-9][a-z0-9_-]{0,31})$/', $name) ? $name : '';
        }, $disks))));

        $validatedShelves[] = [
            'id' => $id,
            'name' => substr(trim((string) ($shelf['name'] ?? ($model . ' ' . ($index + 1)))), 0, 80),
            'model' => $model,
            'enabled' => filter_var($shelf['enabled'] ?? true, FILTER_VALIDATE_BOOL),
            'commissioned' => filter_var($shelf['commissioned'] ?? false, FILTER_VALIDATE_BOOL),
            'serialPort' => $port,
            'sesDevice' => $sesDevice,
            'sesAddress' => $sesAddress,
            'diskAssignment' => $diskAssignment,
            'disks' => $disks,
        ];
    }
    $config['shelves'] = $validatedShelves;
    return $config;
}

function md12xx_disable_active_discovery_after_setup(array $config): array
{
    $shelves = is_array($config['shelves'] ?? null) ? $config['shelves'] : [];
    if ($shelves === []) return $config;
    foreach ($shelves as $shelf) {
        if (!is_array($shelf) || !filter_var($shelf['commissioned'] ?? false, FILTER_VALIDATE_BOOL)) return $config;
    }
    if (!is_array($config['discovery'] ?? null)) $config['discovery'] = [];
    $config['discovery']['autoProbeKnownFtdi'] = false;
    return $config;
}

function md12xx_write_config(array $input, ?string $path = null): array
{
    $path = $path ?: MD12XX_CONFIG_FILE;
    $config = md12xx_validate_config($input);
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the configuration directory');
    }
    $lock = @fopen($path . '.lock', 'c+');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Unable to lock the configuration');
    }
    try {
        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $path . '.tmp.' . getmypid();
        if (@file_put_contents($temporary, $encoded, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to save the configuration');
        }
        @chmod($path, 0644);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $config;
}

function md12xx_read_state(?string $path = null): array
{
    return md12xx_read_json($path ?: MD12XX_STATE_FILE);
}

function md12xx_read_discovery(?string $path = null): array
{
    return md12xx_read_json($path ?: MD12XX_DISCOVERY_FILE);
}

function md12xx_competing_controllers(array $config): array
{
    $detected = false;
    $names = is_array($config['legacyContainerNames'] ?? null) ? $config['legacyContainerNames'] : [];
    $lines = [];
    $exitCode = 1;
    @exec("docker ps --format '{{.Names}}' 2>/dev/null", $lines, $exitCode);
    if ($exitCode === 0 && $names) {
        $wanted = array_map('strtolower', array_map('trim', $names));
        $detected = (bool) array_filter(array_map('trim', $lines), static fn(string $name): bool => in_array(strtolower($name), $wanted, true));
    }

    // Detect another local MD12xx writer by its process role without exposing
    // implementation-specific plugin or host names in the public interface.
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdline) {
        $raw = @file_get_contents($cmdline);
        if (is_string($raw) && str_contains(str_replace("\0", ' ', $raw), '/md1200-controller.php')) {
            $detected = true;
            break;
        }
    }
    return $detected ? ['Another fan controller'] : [];
}

function md12xx_serial_busy(string $port): bool
{
    $binary = trim((string) @shell_exec('command -v fuser 2>/dev/null'));
    if ($binary === '') return false;
    $target = @realpath($port);
    $exitCode = 1;
    @exec(escapeshellarg($binary) . ' ' . escapeshellarg($target !== false ? $target : $port) . ' >/dev/null 2>&1', $unused, $exitCode);
    return $exitCode === 0;
}

function md12xx_public_status(): array
{
    $config = md12xx_read_config();
    $state = md12xx_read_state();
    $generatedAt = is_numeric($state['generatedAt'] ?? null) ? (int) $state['generatedAt'] : null;
    $staleAfter = max(20, ((int) $config['pollSeconds'] * 3) + 5);
    return [
        'version' => '@@VERSION@@',
        'enabled' => (bool) $config['enabled'],
        'mode' => (string) $config['mode'],
        'manualSpeed' => (int) $config['manualSpeed'],
        'allowedManualSpeeds' => [20, 30, 40, 50, 60, 70, 80, 90, 100],
        'stale' => $generatedAt === null || (time() - $generatedAt) > $staleAfter,
        'generatedAt' => $generatedAt,
        'controller' => is_array($state['controller'] ?? null) ? $state['controller'] : [],
        'shelves' => is_array($state['shelves'] ?? null) ? array_values($state['shelves']) : [],
    ];
}

function md12xx_discover_serial_ports(): array
{
    $ports = glob('/dev/serial/by-id/*') ?: [];
    sort($ports, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_filter($ports, 'is_string'));
}

function md12xx_serial_port_details(): array
{
    $result = [];
    foreach (md12xx_discover_serial_ports() as $path) {
        $target = @realpath($path);
        $tty = $target !== false ? basename($target) : '';
        $cursor = $tty !== '' ? @realpath('/sys/class/tty/' . $tty . '/device') : false;
        $metadata = ['vendorId' => '', 'productId' => '', 'manufacturer' => '', 'product' => '', 'serial' => ''];
        for ($depth = 0; $cursor !== false && $depth < 8; $depth++, $cursor = @realpath(dirname($cursor))) {
            foreach ([
                'vendorId' => 'idVendor', 'productId' => 'idProduct', 'manufacturer' => 'manufacturer',
                'product' => 'product', 'serial' => 'serial',
            ] as $key => $filename) {
                if ($metadata[$key] === '' && is_file($cursor . '/' . $filename)) {
                    $metadata[$key] = trim((string) @file_get_contents($cursor . '/' . $filename));
                }
            }
            if ($metadata['vendorId'] !== '' && $metadata['productId'] !== '') break;
            if ($cursor === '/sys' || $cursor === '/') break;
        }
        $knownFtdi = strtolower($metadata['vendorId']) === '0403'
            || stripos($path, 'FTDI_USB_Serial_Converter') !== false
            || stripos($metadata['manufacturer'] . ' ' . $metadata['product'], 'FTDI') !== false;
        $result[] = array_merge([
            'path' => $path,
            'device' => $target !== false ? $target : null,
            'tty' => $tty !== '' ? $tty : null,
            'knownFtdiCandidate' => $knownFtdi,
        ], $metadata);
    }
    return $result;
}

function md12xx_ses_disk_mapping(
    string $address,
    string $disksPath = '/var/local/emhttp/disks.ini',
    string $sysfsRoot = '/sys'
): array
{
    $base = [
        'state' => 'unavailable',
        'source' => null,
        'blockDevices' => [],
        'disks' => [],
        'message' => 'Linux did not expose a safe SES-to-disk topology mapping',
    ];
    if ($address === '' || !preg_match('/^[0-9]+:[0-9]+:[0-9]+:[0-9]+$/', $address)) return $base;

    $enclosureRoot = rtrim($sysfsRoot, '/\\') . '/class/enclosure';
    $enclosure = $enclosureRoot . '/' . $address;
    $enclosureFound = is_dir($enclosure);
    if (!is_dir($enclosure)) {
        foreach (glob($enclosureRoot . '/*') ?: [] as $candidate) {
            $resolved = @realpath($candidate);
            if (basename($candidate) === $address || ($resolved !== false && basename($resolved) === $address)) {
                $enclosure = $candidate;
                $enclosureFound = true;
                break;
            }
        }
    }

    $blockDevices = [];
    $mappingSource = null;
    if ($enclosureFound) {
        foreach (glob($enclosure . '/*') ?: [] as $component) {
            if (!is_dir($component)) continue;
            $device = $component . '/device';
            $resolved = @realpath($device);
            foreach (array_unique(array_filter([$device, $resolved !== false ? $resolved : null])) as $devicePath) {
                foreach (glob($devicePath . '/block/*') ?: [] as $blockPath) {
                    $name = basename($blockPath);
                    if (preg_match('/^(sd[a-z]+|nvme[0-9]+n[0-9]+)$/', $name)) $blockDevices[] = $name;
                }
            }
        }
        if ($blockDevices) $mappingSource = 'enclosure-class';
    }

    // Some SAS HBAs expose no /sys/class/enclosure entries. In that case the
    // SES generic device and its disks still share an exact expander ancestor.
    // Matching that ancestor is safer than inferring shelves from target IDs.
    if (!$blockDevices) {
        $root = rtrim($sysfsRoot, '/\\');
        $sesPath = null;
        foreach (glob($root . '/class/scsi_generic/sg*') ?: [] as $genericPath) {
            $resolved = @realpath($genericPath . '/device');
            if ($resolved !== false && basename($resolved) === $address) {
                $sesPath = str_replace('\\', '/', $resolved);
                break;
            }
        }
        $expanderRoot = null;
        if ($sesPath !== null && preg_match('#^(.*/expander-[0-9]+:[0-9]+(?::[0-9]+)?)(?:/|$)#', $sesPath, $match)) {
            $expanderRoot = rtrim($match[1], '/');
        }
        if ($expanderRoot !== null) {
            foreach (glob($root . '/class/scsi_disk/*') ?: [] as $diskPath) {
                $resolved = @realpath($diskPath . '/device');
                if ($resolved === false) continue;
                $resolved = str_replace('\\', '/', $resolved);
                if (!str_starts_with($resolved, $expanderRoot . '/')) continue;
                foreach (glob($diskPath . '/device/block/*') ?: [] as $blockPath) {
                    $name = basename($blockPath);
                    if (preg_match('/^(sd[a-z]+|nvme[0-9]+n[0-9]+)$/', $name)) $blockDevices[] = $name;
                }
            }
            if ($blockDevices) $mappingSource = 'sas-expander';
        }
    }
    $blockDevices = array_values(array_unique($blockDevices));
    sort($blockDevices, SORT_NATURAL | SORT_FLAG_CASE);
    if (!$blockDevices) {
        return array_replace($base, [
            'state' => $enclosureFound ? 'empty' : 'unavailable',
            'message' => $enclosureFound
                ? 'The enclosure was found, but Linux did not link any block devices to its slots'
                : 'No enclosure-class links or shared SES SAS-expander topology were available',
        ]);
    }

    $byDevice = [];
    foreach (md12xx_discover_disks($disksPath) as $disk) {
        $device = basename(trim((string) ($disk['device'] ?? '')));
        if ($device !== '') $byDevice[strtolower($device)] = (string) $disk['name'];
    }
    $disks = [];
    foreach ($blockDevices as $device) {
        $name = $byDevice[strtolower($device)] ?? '';
        if ($name !== '') $disks[] = $name;
    }
    $disks = array_values(array_unique($disks));
    usort($disks, 'strnatcasecmp');
    return [
        'state' => $disks ? 'verified' : 'unassigned',
        'source' => $mappingSource,
        'blockDevices' => $blockDevices,
        'disks' => $disks,
        'message' => $disks
            ? ($mappingSource === 'sas-expander'
                ? 'Mapped through the SES device SAS expander and the current Unraid disk inventory'
                : 'Mapped through Linux enclosure slot links and the current Unraid disk inventory')
            : 'Physical enclosure disks were found, but none are currently assigned by Unraid',
    ];
}

function md12xx_discover_ses(
    string $disksPath = '/var/local/emhttp/disks.ini',
    string $sysfsRoot = '/sys'
): array
{
    $result = [];
    foreach (glob(rtrim($sysfsRoot, '/\\') . '/class/scsi_generic/sg*') ?: [] as $genericPath) {
        $devicePath = $genericPath . '/device';
        $resolved = @realpath($devicePath);
        if ($resolved === false) continue;
        $vendor = trim((string) @file_get_contents($devicePath . '/vendor'));
        $model = trim((string) @file_get_contents($devicePath . '/model'));
        $type = trim((string) @file_get_contents($devicePath . '/type'));
        if ($type !== '13' && stripos($model, 'MD12') === false) continue;
        $mapping = md12xx_ses_disk_mapping(basename($resolved), $disksPath, $sysfsRoot);
        $result[] = [
            'device' => '/dev/' . basename($genericPath),
            'address' => basename($resolved),
            'vendor' => $vendor,
            'model' => $model,
            'supportedCandidate' => stripos($vendor . ' ' . $model, 'DELL') !== false || stripos($model, 'MD12') !== false,
            'diskMappingState' => $mapping['state'],
            'blockDevices' => $mapping['blockDevices'],
            'disks' => $mapping['disks'],
            'diskMappingMessage' => $mapping['message'],
        ];
    }
    usort($result, static fn(array $a, array $b): int => strnatcasecmp($a['device'], $b['device']));
    return $result;
}

function md12xx_discover_disks(string $path = '/var/local/emhttp/disks.ini'): array
{
    $values = is_file($path) ? @parse_ini_file($path, true, INI_SCANNER_RAW) : [];
    if (!is_array($values)) return [];
    $result = [];
    foreach ($values as $section => $disk) {
        if (!is_array($disk)) continue;
        $name = strtolower(trim((string) ($disk['name'] ?? $section)));
        if ($name === '') continue;
        $result[] = [
            'name' => $name,
            'device' => trim((string) ($disk['device'] ?? '')),
            'temperatureC' => is_numeric($disk['temp'] ?? null) ? (float) $disk['temp'] : null,
        ];
    }
    usort($result, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return $result;
}
