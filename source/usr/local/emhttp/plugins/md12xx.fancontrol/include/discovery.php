<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['once', 'config:', 'state:']);
$runOnce = array_key_exists('once', $options);
$configPath = isset($options['config']) ? (string) $options['config'] : MD12XX_CONFIG_FILE;
$statePath = isset($options['state']) ? (string) $options['state'] : MD12XX_DISCOVERY_FILE;
$running = true;

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

function md12xx_discovery_write_state(string $path, array $state): void
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

function md12xx_discovery_preview(string $response): string
{
    $clean = (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $response);
    $lines = preg_split('/\r?\n|\r/', $clean) ?: [];
    $selected = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/BlueDress|Host Links|Expansion Links|Drive\(s\)|EMM \(|Power Supplies|\[[0-9]+\]\s*=\s*[0-9]+c/i', $line)) {
            $selected[] = substr($line, 0, 240);
        }
        if (count($selected) >= 24) break;
    }
    return implode("\n", $selected);
}

function md12xx_discovery_probe(string $port, int $responseSeconds): array
{
    $base = [
        'probeState' => 'not-run',
        'blueDressPrompt' => false,
        'md12xxResponse' => false,
        'primaryActive' => false,
        'consoleVerified' => false,
        'responsePreview' => '',
        'message' => '',
    ];
    if ($port === '' || !str_starts_with($port, '/dev/serial/by-id/') || !file_exists($port)) {
        return array_replace($base, ['probeState' => 'missing', 'message' => 'Persistent serial device is missing']);
    }
    if (md12xx_serial_busy($port)) {
        return array_replace($base, ['probeState' => 'busy', 'message' => 'Serial adapter is open in another process; probe skipped']);
    }

    if (!is_dir(MD12XX_RUNTIME_DIR)) @mkdir(MD12XX_RUNTIME_DIR, 0755, true);
    $lockPath = MD12XX_RUNTIME_DIR . '/serial-' . substr(sha1($port), 0, 12) . '.lock';
    $lock = @fopen($lockPath, 'c+');
    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return array_replace($base, ['probeState' => 'busy', 'message' => 'Serial adapter is in use; probe skipped']);
    }

    try {
        $exitCode = 1;
        @exec('stty -F ' . escapeshellarg($port) . ' 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb min 0 time 1 2>/dev/null', $unused, $exitCode);
        if ($exitCode !== 0) return array_replace($base, ['probeState' => 'fault', 'message' => 'Unable to configure serial adapter']);
        $handle = @fopen($port, 'r+');
        if ($handle === false) return array_replace($base, ['probeState' => 'fault', 'message' => 'Unable to open serial adapter']);
        stream_set_blocking($handle, false);
        stream_set_write_buffer($handle, 0);

        // Drain a stale prompt so classification is based on this _who response.
        $drainUntil = microtime(true) + 0.25;
        while (microtime(true) < $drainUntil) { @fread($handle, 4096); usleep(25000); }

        // The service console uses carriage-return command framing. CRLF was
        // observed to echo without reliably applying commands on real MD1200s.
        $payload = "_who\r";
        $written = @fwrite($handle, $payload);
        @fflush($handle);
        if ($written !== strlen($payload)) {
            fclose($handle);
            return array_replace($base, ['probeState' => 'fault', 'message' => 'Read-only identity query could not be written']);
        }

        $response = '';
        $deadline = microtime(true) + $responseSeconds;
        while (microtime(true) < $deadline && strlen($response) < 32768) {
            $chunk = @fread($handle, 4096);
            if (is_string($chunk) && $chunk !== '') $response .= $chunk;
            usleep(50000);
        }
        fclose($handle);

        // BlueDress is common on the hardware tested so far, but prompt text is
        // firmware-owned and is not used as the only identity check. The _who
        // response structure is the stronger model-family fingerprint.
        $blueDress = preg_match('/BlueDress\.[0-9]+\.[0-9]+\s*>/i', $response) === 1;
        $fingerprints = [
            preg_match('/Host\s+Links\s+UP\s*:/i', $response) === 1,
            preg_match('/Expansion\s+Links\s+UP\s*:/i', $response) === 1,
            preg_match('/Drive\(s\)\s*:/i', $response) === 1,
            preg_match('/EMM\s*\(/i', $response) === 1,
            preg_match('/Power\s+Supplies\s*:/i', $response) === 1,
        ];
        $md12xxResponse = count(array_filter($fingerprints)) >= 4;
        $primaryActive = preg_match('/I.?m\s+primary\s+and\s+active/i', $response) === 1;
        $verified = $md12xxResponse && $primaryActive;
        return array_replace($base, [
            'probeState' => $response === '' ? 'no-response' : ($verified ? 'verified' : 'unrecognized'),
            'blueDressPrompt' => $blueDress,
            'md12xxResponse' => $md12xxResponse,
            'primaryActive' => $primaryActive,
            'consoleVerified' => $verified,
            'responsePreview' => md12xx_discovery_preview($response),
            'message' => $verified
                ? 'MD12xx EMM console verified as primary and active; SES pairing still required'
                : ($response === '' ? 'No response to the read-only identity query' : 'A response was received but did not prove a primary active MD12xx EMM'),
        ]);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

while ($running) {
    try {
        $config = md12xx_validate_config(md12xx_read_config($configPath));
        $probeConfig = $config['discovery'];
        $conflicts = md12xx_competing_controllers($config);
        $activeAllowed = !(bool) $config['enabled'] && !$conflicts;
        $ports = [];
        foreach (md12xx_serial_port_details() as $port) {
            $result = [
                'probeState' => 'passive-only',
                'blueDressPrompt' => false,
                'md12xxResponse' => false,
                'primaryActive' => false,
                'consoleVerified' => false,
                'responsePreview' => '',
                'message' => 'Passively inventoried; active query not enabled for this device',
            ];
            if ((bool) $probeConfig['autoProbeKnownFtdi'] && (bool) $port['knownFtdiCandidate']) {
                $result = $activeAllowed
                    ? md12xx_discovery_probe((string) $port['path'], (int) $probeConfig['responseSeconds'])
                    : array_replace($result, ['probeState' => 'blocked', 'message' => (bool) $config['enabled'] ? 'Fan control is enabled; identity probe skipped' : 'Competing controller detected; identity probe skipped']);
            }
            $ports[] = array_merge($port, $result);
        }
        md12xx_discovery_write_state($statePath, [
            'generatedAt' => time(),
            'autoProbeKnownFtdi' => (bool) $probeConfig['autoProbeKnownFtdi'],
            'activeProbeAllowed' => $activeAllowed,
            'blockedBy' => $conflicts,
            'serialPorts' => $ports,
            'sesDevices' => md12xx_discover_ses(),
            'pairingPolicy' => 'Serial console identity and SES identity are verified independently; explicit operator pairing is required.',
        ]);
        $interval = (int) $probeConfig['intervalSeconds'];
    } catch (Throwable $error) {
        md12xx_discovery_write_state($statePath, ['generatedAt' => time(), 'error' => $error->getMessage(), 'serialPorts' => [], 'sesDevices' => []]);
        $interval = 60;
    }
    if ($runOnce) break;
    for ($second = 0; $running && $second < $interval; $second++) sleep(1);
}
