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
    try {
        $status = cooling_ipmi_public();
        if ($config['enabled'] && $config['mode'] !== 'firmware' && (!$status['available'] || $status['stale'] || !($status['healthy'] ?? false))) throw new RuntimeException('Fresh healthy local CPU, inlet and fan telemetry is required before enabling control');
        cooling_ipmi_store(COOLING_IPMI_CONFIG, $config);
        if (is_file(COOLING_IPMI_RUNTIME . '/ipmi-fault-latched')) unlink(COOLING_IPMI_RUNTIME . '/ipmi-fault-latched');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    echo json_encode(['ok' => true, 'message' => 'Saved; the cooling service will apply this change on its next cycle'], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['error' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
