<?php
declare(strict_types=1);
// Exercise the real endpoint after a fixture emulating Unraid's validating,
// token-consuming auto-prepend contract. All file writes stay in this fixture.
$source = dirname(__DIR__) . '/source/usr/local/emhttp/plugins/md12xx.fancontrol';
$root = sys_get_temp_dir() . '/ipmi-api-' . bin2hex(random_bytes(6));
foreach (['', '/include', '/config', '/state'] as $suffix) mkdir($root . $suffix, 0755);
function api_check(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
function api_cleanup(string $path): void { foreach (new FilesystemIterator($path) as $file) { if ($file->isDir()) api_cleanup($file->getPathname()); else unlink($file->getPathname()); } rmdir($path); }
try {
    foreach (['ipmi-api.php', 'ipmi.php', 'curves.php'] as $file) {
        $code = strtr(file_get_contents($source . '/include/' . $file), [
            '/boot/config/plugins/md12xx.fancontrol' => $root . '/config',
            '/var/run/md12xx.fancontrol' => $root . '/state',
            '/usr/local/emhttp/plugins/md12xx.fancontrol/bin/ipmitool.bin' => $root . '/unavailable-tool',
        ]);
        file_put_contents($root . '/include/' . $file, $code);
    }
    $prepend = <<<'PHP'
<?php
$request = json_decode(file_get_contents(__DIR__ . '/request.json'), true);
$_SERVER['REQUEST_METHOD'] = $request['method'];
$_POST = $request['post'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals('fixture-session-token', $_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error'=>'Rejected by native CSRF validation']);
        exit;
    }
    unset($_POST['csrf_token']);
}
PHP;
    file_put_contents($root . '/prepend.php', $prepend);
    $request = function (string $method, array $post) use ($root): array {
        file_put_contents($root . '/request.json', json_encode(['method'=>$method,'post'=>$post]));
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/prepend.php', $root . '/include/ipmi-api.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start endpoint fixture');
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]); $exit = proc_close($process);
        api_check($exit === 0, $errors ?: $output);
        return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    };
    $config = ['enabled'=>false,'mode'=>'auto','manualSpeed'=>60,'curveMode'=>'individual','hysteresisC'=>2.5,
        'curve'=>[['temperatureC'=>0,'speed'=>15],['temperatureC'=>40,'speed'=>40]]];
    $post = ['csrf_token'=>'fixture-session-token','config'=>json_encode($config)];
    $response = $request('POST', $post);
    api_check(($response['ok'] ?? false) === true, 'Valid native POST must save after the token is consumed: ' . json_encode($response));
    $saved = file_get_contents($root . '/config/ipmi.json');
    $decoded = json_decode($saved, true);
    api_check($decoded['curveMode'] === 'individual' && $decoded['curve'][0]['speed'] === 15 && $decoded['hysteresisC'] === 2.5, 'Individual curve and hysteresis must persist');
    foreach ([null, 'wrong-token'] as $token) {
        $bad = $post; if ($token === null) unset($bad['csrf_token']); else $bad['csrf_token'] = $token;
        api_check(isset($request('POST', $bad)['error']), 'Native middleware must reject a missing/wrong token');
        api_check(file_get_contents($root . '/config/ipmi.json') === $saved, 'Rejected request must not change saved config');
    }
    foreach ([['curve'=>[['temperatureC'=>0,'speed'=>14],['temperatureC'=>40,'speed'=>40]]], ['hysteresisC'=>11], ['enabled'=>true]] as $change) {
        $bad = $post; $bad['config'] = json_encode(array_replace($config, $change));
        api_check(isset($request('POST', $bad)['error']), 'Invalid or unavailable-control request must fail');
        api_check(file_get_contents($root . '/config/ipmi.json') === $saved, 'Invalid settings must preserve saved config');
    }
    api_check(isset($request('PUT', [])['error']), 'Unsupported methods must fail');
    $read = $request('GET', []);
    api_check($read['config']['curveMode'] === 'individual' && $read['config']['hysteresisC'] === 2.5, 'GET must return persisted Individual settings');
    $js = file_get_contents($source . '/assets/js/ipmi.js');
    api_check(str_contains($js, "body.set('csrf_token', token)"), 'Browser must continue sending the native CSRF token');

    $html = preg_replace('/<\?(?:php|=).*?\?>/s', '', file_get_contents($source . '/MD12xxFanControl.page'));
    $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
    $message = $xpath->query('//*[@id="md12xx-message"]');
    api_check($message->length === 1, 'Exactly one shared save message must exist');
    api_check($xpath->query('ancestor::*[@id="cooling-md12xx"]', $message->item(0))->length === 0, 'Shared save feedback must remain outside the optional shelf wrapper');
    api_check($message->item(0)->getAttribute('role') === 'status', 'Shared save feedback must be announced');
    echo "IPMI native CSRF dispatch, save persistence, rejection and visible-feedback regressions passed.\n";
} finally { api_cleanup($root); }
