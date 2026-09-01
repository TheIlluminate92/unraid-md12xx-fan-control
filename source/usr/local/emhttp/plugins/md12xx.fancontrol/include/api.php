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

function md12xx_discovery_payload(): array
{
    $background = md12xx_read_discovery();
    $config = md12xx_read_config();
    $generatedAt = is_numeric($background['generatedAt'] ?? null) ? (int) $background['generatedAt'] : null;
    $discoveryInterval = (int) ($config['discovery']['intervalSeconds'] ?? 300);
    return [
        'generatedAt' => $generatedAt,
        'generationId' => $background['generationId'] ?? null,
        'stale' => $generatedAt === null || (time() - $generatedAt) > max(120, ($discoveryInterval * 3) + 30),
        'error' => $background['error'] ?? null,
        'autoProbeKnownFtdi' => $background['autoProbeKnownFtdi'] ?? false,
        'activeProbeAllowed' => $background['activeProbeAllowed'] ?? false,
        'blockedBy' => $background['blockedBy'] ?? [],
        'serialPorts' => $background['serialPorts'] ?? md12xx_serial_port_details(),
        'sesDevices' => $background['sesDevices'] ?? md12xx_discover_ses(),
        'disks' => md12xx_discover_disks(),
        'pairingPolicy' => $background['pairingPolicy'] ?? 'Explicit operator pairing is required.',
    ];
}

function md12xx_commission_status(string $id): array
{
    $paths = md12xx_commission_paths($id);
    $pid = is_file($paths['pid']) ? (int) trim((string) @file_get_contents($paths['pid'])) : 0;
    $running = md12xx_pid_running($pid, 'commission-job.sh');
    $hasExit = is_file($paths['exit']);
    $activeId = is_file(MD12XX_RUNTIME_DIR . '/commissioning.active') ? trim((string) @file_get_contents(MD12XX_RUNTIME_DIR . '/commissioning.active')) : '';
    // Do not let a stale marker make a crashed job appear to run forever.
    // The shared lifecycle check verifies a live wrapper/child and expires an
    // orphaned marker after its short launch grace period.
    if (!$running && !$hasExit && $pid > 1 && $activeId === $id && md12xx_commission_active()) $running = true;
    $exitCode = $hasExit ? (int) trim((string) @file_get_contents($paths['exit'])) : null;
    $output = is_file($paths['log']) ? (string) @file_get_contents($paths['log']) : '';
    if (strlen($output) > 65536) $output = substr($output, -65536);
    $startedAt = is_file($paths['started']) ? (int) trim((string) @file_get_contents($paths['started'])) : null;
    $resultFile = null;
    if (preg_match('/^Results:\s+(.+\.(?:zip|tar\.gz))\s*$/mi', $output, $match)) $resultFile = basename(trim($match[1]));

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
            echo json_encode(md12xx_discovery_payload(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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

    if ($action === 'refresh-discovery') {
        if (md12xx_commission_active()) throw new RuntimeException('Wait for Identify & test to finish before refreshing discovery');
        $requested = json_decode((string) ($_POST['discovery'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($requested)) throw new InvalidArgumentException('Invalid discovery settings');
        $current = md12xx_read_config();
        $current['discovery'] = $requested;
        $saved = md12xx_write_config($current);
        $script = dirname(__DIR__) . '/include/discovery.php';
        if (!is_file($script)) throw new RuntimeException('Discovery service is unavailable');
        $previousGenerationId = md12xx_read_discovery()['generationId'] ?? null;
        $command = 'nohup /usr/bin/php ' . escapeshellarg($script) . ' --once >/dev/null 2>&1 & echo $!';
        $pid = (int) trim((string) @shell_exec($command));
        if ($pid <= 1) throw new RuntimeException('Unable to start discovery refresh');
        echo json_encode(['ok' => true, 'config' => $saved, 'previousGenerationId' => $previousGenerationId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
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
        if (md12xx_commission_active()) throw new RuntimeException('Another shelf test is already running');

        $paths = md12xx_commission_paths($id);
        if (!is_dir($paths['directory']) && !@mkdir($paths['directory'], 0755, true) && !is_dir($paths['directory'])) {
            throw new RuntimeException('Unable to create commissioning job state');
        }
        foreach (['pid', 'log', 'exit', 'started'] as $key) @unlink($paths[$key]);
        $runner = dirname(__DIR__) . '/scripts/commission-job.sh';
        if (!is_file($runner) || !is_executable($runner)) throw new RuntimeException('Commissioning service is unavailable');
        if (!is_dir(MD12XX_RUNTIME_DIR) && !@mkdir(MD12XX_RUNTIME_DIR, 0755, true) && !is_dir(MD12XX_RUNTIME_DIR)) {
            throw new RuntimeException('Unable to create commissioning state');
        }
        if (@file_put_contents(MD12XX_RUNTIME_DIR . '/commissioning.active', $id . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to lock commissioning state');
        }
        $command = 'nohup ' . escapeshellarg($runner) . ' ' . escapeshellarg($id) . ' ' . escapeshellarg($paths['directory']) . ' >/dev/null 2>&1 & echo $!';
        $pid = (int) trim((string) @shell_exec($command));
        if ($pid <= 1) {
            @unlink(MD12XX_RUNTIME_DIR . '/commissioning.active');
            throw new RuntimeException('Unable to start commissioning');
        }
        @file_put_contents($paths['pid'], $pid . "\n", LOCK_EX);
        echo json_encode(['ok' => true, 'job' => md12xx_commission_status($id)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'control') {
        if (md12xx_commission_active()) throw new RuntimeException('Control changes are blocked while Identify & test is running');
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
    if (md12xx_commission_active()) throw new RuntimeException('Configuration changes are blocked while Identify & test is running');
    $decoded = json_decode((string) ($_POST['config'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new InvalidArgumentException('Invalid configuration payload');

    $current = md12xx_read_config();
    $saved = md12xx_write_config(md12xx_merge_settings_config($current, $decoded));
    echo json_encode(['ok' => true, 'config' => $saved], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
}

