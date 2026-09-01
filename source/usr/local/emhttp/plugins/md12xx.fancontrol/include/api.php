<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/common.php';

function md12xx_commission_paths(string $id): array
{
    $safe = md12xx_slug($id);
    $directory = MD12XX_COMMISSION_JOB_DIR . '/' . $safe;
    return [
        'directory' => $directory,
        'pid' => $directory . '/pid',
        'log' => $directory . '/output.log',
        'exit' => $directory . '/exit-code',
        'started' => $directory . '/started-at',
    ];
}

function md12xx_pid_running(int $pid, string $marker): bool
{
    if ($pid <= 1 || !is_readable('/proc/' . $pid . '/cmdline')) return false;
    $raw = @file_get_contents('/proc/' . $pid . '/cmdline');
    return is_string($raw) && str_contains(str_replace("\0", ' ', $raw), $marker);
}

function md12xx_commission_status(string $id): array
{
    $paths = md12xx_commission_paths($id);
    $pid = is_file($paths['pid']) ? (int) trim((string) @file_get_contents($paths['pid'])) : 0;
    $running = md12xx_pid_running($pid, 'commission-job.sh');
    $hasExit = is_file($paths['exit']);
    $exitCode = $hasExit ? (int) trim((string) @file_get_contents($paths['exit'])) : null;
    $output = is_file($paths['log']) ? (string) @file_get_contents($paths['log']) : '';
    if (strlen($output) > 65536) $output = substr($output, -65536);
    $startedAt = is_file($paths['started']) ? (int) trim((string) @file_get_contents($paths['started'])) : null;
    $resultFile = null;
    if (preg_match('/^Results:\s+(.+\.zip)\s*$/mi', $output, $match)) $resultFile = basename(trim($match[1]));

    $phase = 'not-started';
    if ($hasExit) $phase = $exitCode === 0 ? 'passed' : 'failed';
    elseif ($running && str_contains($output, 'Waiting 30s to prove')) $phase = 'verifying-restore';
    elseif ($running && str_contains($output, 'Returning the selected MD12xx console to 20%')) $phase = 'restoring';
    elseif ($running && str_contains($output, 'Commanding 50%')) $phase = 'testing-50';
    elseif ($running && str_contains($output, 'Commanding 20%')) $phase = 'testing-20';
    elseif ($running && str_contains($output, 'Verifying the selected serial console')) $phase = 'verifying-console';
    elseif ($running) $phase = 'starting';
    elseif ($startedAt !== null) $phase = 'failed';

    $config = md12xx_read_config();
    $shelf = null;
    foreach ($config['shelves'] as $candidate) {
        if ((string) ($candidate['id'] ?? '') === $id) { $shelf = $candidate; break; }
    }
    return [
        'id' => $id,
        'phase' => $phase,
        'running' => $running,
        'exitCode' => $exitCode,
        'startedAt' => $startedAt,
        'output' => $output,
        'resultFile' => $resultFile,
        'shelf' => $shelf,
    ];
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = strtolower(trim((string) ($_REQUEST['action'] ?? 'status')));

    if ($method === 'GET') {
        if ($action === 'discover') {
            $background = md12xx_read_discovery();
            echo json_encode([
                'generatedAt' => $background['generatedAt'] ?? null,
                'autoProbeKnownFtdi' => $background['autoProbeKnownFtdi'] ?? false,
                'activeProbeAllowed' => $background['activeProbeAllowed'] ?? false,
                'blockedBy' => $background['blockedBy'] ?? [],
                'serialPorts' => $background['serialPorts'] ?? md12xx_serial_port_details(),
                'sesDevices' => $background['sesDevices'] ?? md12xx_discover_ses(),
                'disks' => md12xx_discover_disks(),
                'pairingPolicy' => $background['pairingPolicy'] ?? 'Explicit operator pairing is required.',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } elseif ($action === 'config') {
            echo json_encode(['config' => md12xx_read_config()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } elseif ($action === 'commission') {
            $rawId = trim((string) ($_GET['id'] ?? ''));
            if ($rawId === '') throw new InvalidArgumentException('Shelf id is required');
            $id = md12xx_slug($rawId);
            echo json_encode(md12xx_commission_status($id), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } else {
            echo json_encode(md12xx_public_status(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Method not allowed');
    }

    if ($action === 'commission') {
        $rawId = trim((string) ($_POST['id'] ?? ''));
        if ($rawId === '') throw new InvalidArgumentException('Shelf id is required');
        $id = md12xx_slug($rawId);
        $config = md12xx_read_config();
        $shelf = null;
        foreach ($config['shelves'] as $candidate) {
            if ((string) ($candidate['id'] ?? '') === $id) { $shelf = $candidate; break; }
        }
        if (!is_array($shelf)) throw new InvalidArgumentException('Unknown shelf');
        if ((bool) $config['enabled']) throw new RuntimeException('Disable this controller before running Identify & test');
        if (trim((string) ($shelf['serialPort'] ?? '')) === '') throw new InvalidArgumentException('Select and save a persistent serial adapter first');
        if (md12xx_competing_controllers($config)) throw new RuntimeException('Another fan controller is active. Disable it, then retry');
        if (is_file(MD12XX_RUNTIME_DIR . '/commissioning.active')) throw new RuntimeException('Another shelf test is already running');

        foreach (glob(MD12XX_COMMISSION_JOB_DIR . '/*/pid') ?: [] as $pidFile) {
            $pid = (int) trim((string) @file_get_contents($pidFile));
            if (md12xx_pid_running($pid, 'commission-job.sh')) throw new RuntimeException('Another shelf test is already running');
        }

        $paths = md12xx_commission_paths($id);
        if (!is_dir($paths['directory']) && !@mkdir($paths['directory'], 0755, true) && !is_dir($paths['directory'])) {
            throw new RuntimeException('Unable to create commissioning job state');
        }
        foreach (['pid', 'log', 'exit', 'started'] as $key) @unlink($paths[$key]);
        $runner = dirname(__DIR__) . '/scripts/commission-job.sh';
        if (!is_file($runner) || !is_executable($runner)) throw new RuntimeException('Commissioning service is unavailable');
        $command = 'nohup ' . escapeshellarg($runner) . ' ' . escapeshellarg($id) . ' ' . escapeshellarg($paths['directory']) . ' >/dev/null 2>&1 & echo $!';
        $pid = (int) trim((string) @shell_exec($command));
        if ($pid <= 1) throw new RuntimeException('Unable to start commissioning');
        @file_put_contents($paths['pid'], $pid . "\n", LOCK_EX);
        echo json_encode(['ok' => true, 'job' => md12xx_commission_status($id)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'control') {
        $current = md12xx_read_config();
        $mode = strtolower(trim((string) ($_POST['mode'] ?? '')));
        if (!in_array($mode, ['auto', 'manual'], true)) throw new InvalidArgumentException('Mode must be Auto or Manual');
        $current['mode'] = $mode;
        if ($mode === 'manual') {
            $speed = (int) ($_POST['speed'] ?? 0);
            if (!in_array($speed, [20, 30, 40, 50, 60, 70, 80, 90, 100], true)) throw new InvalidArgumentException('Unsupported manual speed');
            $current['manualSpeed'] = $speed;
        }
        md12xx_write_config($current);
        echo json_encode(['ok' => true, 'status' => md12xx_public_status()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'diagnostics') {
        $script = dirname(__DIR__) . '/scripts/diagnose.sh';
        if (!is_file($script) || !is_executable($script)) throw new RuntimeException('Diagnostics script is unavailable');
        $lines = [];
        $exitCode = 1;
        @exec(escapeshellarg($script) . ' 2>&1', $lines, $exitCode);
        if ($exitCode !== 0) throw new RuntimeException('Diagnostics failed: ' . implode(' ', $lines));
        $last = trim((string) end($lines));
        $prefix = 'Read-only diagnostics: ';
        if (!str_starts_with($last, $prefix)) throw new RuntimeException('Diagnostics completed without an archive path');
        $path = substr($last, strlen($prefix));
        echo json_encode(['ok' => true, 'path' => $path, 'file' => basename($path), 'uploaded' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action !== 'save') throw new InvalidArgumentException('Unsupported action');
    $decoded = json_decode((string) ($_POST['config'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new InvalidArgumentException('Invalid configuration payload');

    // Commissioning cannot be granted by configuration input. Preserve it only when the
    // hardware identity is unchanged; any remapping requires a new hardware test.
    $current = md12xx_read_config();
    $existing = [];
    foreach ($current['shelves'] as $shelf) $existing[(string) $shelf['id']] = $shelf;
    foreach ($decoded['shelves'] ?? [] as &$shelf) {
        if (!is_array($shelf)) continue;
        $old = $existing[md12xx_slug((string) ($shelf['id'] ?? ''))] ?? null;
        // An identification test can update the SES mapping while an
        // older Settings page is still open. Do not let that stale page erase
        // a proven automatic pairing when it later saves unrelated settings.
        $automatic = strtolower((string) ($shelf['diskAssignment'] ?? 'automatic')) === 'automatic';
        $sameSerial = is_array($old) && (string) ($old['serialPort'] ?? '') === (string) ($shelf['serialPort'] ?? '');
        if ($automatic && $sameSerial && (bool) ($old['commissioned'] ?? false)
            && trim((string) ($shelf['sesAddress'] ?? '')) === '' && trim((string) ($shelf['sesDevice'] ?? '')) === '') {
            $shelf['sesAddress'] = (string) ($old['sesAddress'] ?? '');
            $shelf['sesDevice'] = (string) ($old['sesDevice'] ?? '');
            $shelf['disks'] = is_array($old['disks'] ?? null) ? $old['disks'] : [];
        }
        $sameHardware = is_array($old)
            && strtoupper((string) ($old['model'] ?? '')) === strtoupper((string) ($shelf['model'] ?? ''))
            && (string) ($old['serialPort'] ?? '') === (string) ($shelf['serialPort'] ?? '')
            && (string) ($old['sesAddress'] ?? '') === (string) ($shelf['sesAddress'] ?? '');
        $shelf['commissioned'] = $sameHardware && (bool) ($old['commissioned'] ?? false);
    }
    unset($shelf);

    $saved = md12xx_write_config($decoded);
    echo json_encode(['ok' => true, 'config' => $saved], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
}
