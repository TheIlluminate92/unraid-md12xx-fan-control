<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ipmi.php';
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') { echo json_encode(cooling_ipmi_public(), JSON_THROW_ON_ERROR); exit; }
    if ($method !== 'POST') { http_response_code(405); throw new InvalidArgumentException('Method not allowed'); }
    // Unraid's PHP auto-prepend handler authenticates/validates the request and
    // consumes csrf_token before dispatch. The browser must still send it;
    // requiring the consumed field here rejects valid native WebGUI requests.
    $config = cooling_ipmi_config(json_decode((string) ($_POST['config'] ?? ''), true, 16, JSON_THROW_ON_ERROR));
    $lock = fopen(COOLING_IPMI_RUNTIME . '/ipmi-config.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another cooling setting is being saved; retry shortly');
    $commandLock = null;
    try {
        $commandLock = fopen(COOLING_IPMI_RUNTIME . '/ipmi-command.lock', 'c+');
        if (!$commandLock || !flock($commandLock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Cooling controller is busy; retry shortly');
        $status = cooling_ipmi_public();
        $enableControl = $config['enabled'] && $config['mode'] !== 'firmware';
        $rearm = array_key_exists('rearm', $_POST);
        if ($rearm && (!is_string($_POST['rearm']) || !is_string($status['rearmId'])
            || !hash_equals($status['rearmId'], $_POST['rearm'])))
            throw new RuntimeException('Refresh and review the current chassis fault before re-arming');
        if ($enableControl && (!$status['available'] || $status['stale'] || !($status['healthy'] ?? false)))
            throw new RuntimeException('Fresh healthy local CPU, inlet and fan telemetry is required before enabling control');
        if ($rearm && (!$enableControl || !$status['latched'] || ($status['mode'] ?? null) !== 'firmware' || ($status['state'] ?? null) !== 'fault'
            || is_file(COOLING_IPMI_LEASE) || ($status['cpuMaxC'] ?? INF) >= 70 || ($status['inletC'] ?? INF) >= 35))
            throw new RuntimeException('Re-arm requires confirmed firmware control, safe temperatures and an active fault');
        if ($enableControl && $status['latched'] && !$rearm)
            throw new RuntimeException('Review the fault and explicitly re-arm chassis control');
        cooling_ipmi_store(COOLING_IPMI_CONFIG, $config);
        if ($rearm) {
            foreach ([COOLING_IPMI_RECOVERY, COOLING_IPMI_RUNTIME . '/ipmi-confirmation-pending.json',
                COOLING_IPMI_RUNTIME . '/ipmi-fault.json', COOLING_IPMI_RUNTIME . '/ipmi-fault-latched', COOLING_IPMI_REARM] as $marker) {
                if (file_exists($marker) && !@unlink($marker))
                    throw new RuntimeException('Cannot clear the chassis re-arm requirement');
            }
        }
    } finally {
        if (is_resource($commandLock)) fclose($commandLock);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    echo json_encode(['ok' => true, 'message' => 'Saved; the cooling service will apply this change on its next cycle'], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['error' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
