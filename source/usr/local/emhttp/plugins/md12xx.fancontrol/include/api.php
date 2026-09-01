<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/common.php';

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
        } else {
            echo json_encode(md12xx_public_status(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Method not allowed');
    }

    if ($action === 'control') {
        $current = md12xx_read_config();
        $mode = strtolower(trim((string) ($_POST['mode'] ?? '')));
        if (!in_array($mode, ['auto', 'manual'], true)) throw new InvalidArgumentException('Mode must be Auto or Manual');
        $current['mode'] = $mode;
        if ($mode === 'manual') {
            $speed = (int) ($_POST['speed'] ?? 0);
            if (!in_array($speed, [20, 30, 40, 50], true)) throw new InvalidArgumentException('Unsupported manual speed');
            $current['manualSpeed'] = $speed;
        }
        md12xx_write_config($current);
        echo json_encode(['ok' => true, 'status' => md12xx_public_status()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action !== 'save') throw new InvalidArgumentException('Unsupported action');
    $decoded = json_decode((string) ($_POST['config'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new InvalidArgumentException('Invalid configuration payload');

    // Commissioning cannot be granted by the browser. Preserve it only when the
    // hardware identity is unchanged; any remapping requires a new hardware test.
    $current = md12xx_read_config();
    $existing = [];
    foreach ($current['shelves'] as $shelf) $existing[(string) $shelf['id']] = $shelf;
    foreach ($decoded['shelves'] ?? [] as &$shelf) {
        if (!is_array($shelf)) continue;
        $old = $existing[md12xx_slug((string) ($shelf['id'] ?? ''))] ?? null;
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
