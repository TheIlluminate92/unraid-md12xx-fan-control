$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$manifest = Join-Path $projectRoot 'dist/md12xx.fancontrol.plg'

& (Join-Path $projectRoot 'scripts/build.ps1') -OutputPath $manifest

$settings = [System.Xml.XmlReaderSettings]::new()
$settings.DtdProcessing = [System.Xml.DtdProcessing]::Parse
$reader = [System.Xml.XmlReader]::Create($manifest, $settings)
try { while ($reader.Read()) { } } finally { $reader.Dispose() }

$text = [System.IO.File]::ReadAllText($manifest)
$required = @(
    '/usr/local/emhttp/plugins/md12xx.fancontrol/MD12xxFanControl.page',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/include/common.php',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/include/controller.php',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/include/discovery.php',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/include/api.php',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/include/download.php',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/icon.svg',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/assets/icon.svg',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/assets/js/settings.js',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/assets/css/settings.css',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/start.sh',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/stop.sh',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/commission.sh',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/commission-job.sh',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/diagnose.sh'
)
foreach ($path in $required) { if (-not $text.Contains("Name=`"$path`"")) { throw "Missing packaged file: $path" } }

foreach ($xmlPath in @('ca_profile.xml', 'plugins/md12xx.fancontrol.xml', 'icon.svg')) {
    $document = [xml][System.IO.File]::ReadAllText((Join-Path $projectRoot $xmlPath))
    if (-not $document.DocumentElement) { throw "Invalid XML: $xmlPath" }
}
$profileText = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'ca_profile.xml'))
if (-not $profileText.Contains('<Profile>')) { throw 'Community Apps profile is missing.' }
$wrapperText = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'plugins/md12xx.fancontrol.xml'))
if (-not $wrapperText.Contains('<Beta>true</Beta>')) { throw 'Community Apps beta marker is missing.' }
if (-not $wrapperText.Contains('<PluginURL>https://raw.githubusercontent.com/TheIlluminate92/unraid-md12xx-fan-control/main/releases/md12xx.fancontrol.plg</PluginURL>')) { throw 'Community Apps PluginURL is incorrect.' }
$hardwareForm = [System.IO.File]::ReadAllText((Join-Path $projectRoot '.github/ISSUE_TEMPLATE/hardware-report.yml'))
$bugForm = [System.IO.File]::ReadAllText((Join-Path $projectRoot '.github/ISSUE_TEMPLATE/bug-report.yml'))
foreach ($field in 'Shelf model', 'Unraid version', 'HBA and driver', 'EMM and cabling arrangement', 'Commissioning result', 'Discovery summary', 'Disk mapping', 'Competing fan controller state during testing', 'Optional redacted diagnostics') {
    if (-not $hardwareForm.Contains($field)) { throw "Hardware issue form is missing: $field" }
}
foreach ($form in $bugForm, $hardwareForm) {
    if (-not $form.Contains('type: upload') -or -not $form.Contains('accept: ".zip,.gz,.tar.gz"')) { throw 'Issue form diagnostic archive upload field is missing.' }
    if (-not $form.Contains('can be accessed without authentication')) { throw 'Issue form public-attachment warning is missing.' }
}

foreach ($forbidden in @(
    '/dev/sg18', '/dev/sg11', 'FTE33O9T', 'FTE32AB2',
    '/mnt/user/Back-Up', 'MD1200_TOP_', 'MD1200_BOTTOM_'
)) { if ($text.Contains($forbidden)) { throw "Server-specific value remains in standalone package: $forbidden" } }

foreach ($marker in @(
    'MD1200', 'MD1220', '/dev/serial/by-id/', 'commissioned',
    'set_speed', 'Actual\s+speed', 'window.csrf_token',
    'assigned disks spun down', 'temperature unavailable; fail-safe',
    'Another fan controller', 'fan speeds cannot decrease as temperature rises',
    'autoProbeKnownFtdi', 'MD12xx EMM console verified as primary and active'
)) { if ($text -notmatch [regex]::Escape($marker) -and $marker -notmatch '\\') { throw "Missing required marker: $marker" } }

if ($text -notmatch 'Actual\\s\+speed') { throw 'RPM parsing expression is missing.' }
if ($text -notmatch '\$payload = ''set_speed '' \. \$speed \. "\\r";') { throw 'Controller is not using carriage-return-only framing.' }
if ($text -match '\$payload = ''set_speed '' \. \$speed \. "\\r\\n";') { throw 'CRLF framing was reintroduced.' }
if ($text -notmatch 'rm -rf[^\r\n]*"\$CONFIG_DIR"') { throw 'Uninstall does not remove persistent settings.' }
if (-not $text.Contains('Configuration, commissioning results, and runtime state were deleted.')) { throw 'Clean-uninstall message is missing.' }
$pageSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/MD12xxFanControl.page'))
if ($pageSource -match 'require_once\s+__DIR__') { throw 'Settings page relies on the page builder __DIR__ context.' }
if (-not $pageSource.Contains("`$pluginRoot = '/usr/local/emhttp/plugins/md12xx.fancontrol';")) { throw 'Settings page explicit plugin root is missing.' }
if (-not $pageSource.Contains('id="md12xx-help-toggle"') -or -not $pageSource.Contains('id="md12xx-curve-points"')) { throw 'Beta setup help or variable Auto curve control is missing.' }
if (-not $pageSource.Contains('Version @@VERSION@@')) { throw 'Visible Settings version is missing.' }
if (-not $pageSource.Contains('Report issue on GitHub') -or -not $pageSource.Contains('Development transparency:')) { throw 'Support or development-transparency UI is missing.' }
if (-not $pageSource.Contains('Icon="icon-disks"') -or -not $pageSource.Contains('Tag="icon-disk"')) { throw 'Built-in Settings tile icon is not configured.' }
if (-not $pageSource.Contains('JSON_HEX_TAG') -or -not $pageSource.Contains('JSON_HEX_AMP')) { throw 'Settings bootstrap JSON is not safe for inline script embedding.' }
if ($pageSource -match '(?i)terminal') { throw 'Terminal-only wording remains in Settings.' }
$discoverySource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/include/discovery.php'))
if ($discoverySource -match 'set_speed') { throw 'Read-only discovery contains a fan-speed command.' }
if (-not $discoverySource.Contains('md12xx_commission_active')) { throw 'Discovery does not yield to commissioning.' }
$settingsSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/assets/js/settings.js'))
if (-not $settingsSource.Contains('Setup is complete; turn off Test likely FTDI adapters')) { throw 'Completed-setup discovery reminder is missing.' }
if (-not $settingsSource.Contains('Associated Unraid disks') -or -not $pageSource.Contains('Export local diagnostics')) { throw 'Shelf mapping or local diagnostics UI is missing.' }
foreach ($marker in 'md12xx-commission-start', 'startCommission', 'pollCommission', 'It continues safely even if this page is closed', 'window.setTimeout(updateTitle, 400)', 'updateShelfStatus', 'Download test results') {
    if (-not $settingsSource.Contains($marker)) { throw "App commissioning or focus-safe refresh marker is missing: $marker" }
}
foreach ($marker in 'scheduleCurveResize', 'window.setTimeout(apply, 300)', 'addEventListener("input"', 'addEventListener("change"') {
    if (-not $settingsSource.Contains($marker)) { throw "Dynamic curve resize marker is missing: $marker" }
}
foreach ($marker in 'downloadLocalArchive', 'link.download = file', 'downloadLocalArchive("diagnostics", payload.file)') {
    if (-not $settingsSource.Contains($marker)) { throw "Non-navigating archive download marker is missing: $marker" }
}
if ($settingsSource.Contains('window.location.assign(downloadEndpoint')) { throw 'Archive download still navigates away from Settings.' }
foreach ($marker in 'refresh-discovery', 'Only the discovery options were saved', 'commissioningRunning', 'Wait for Identify & test to finish before saving configuration', 'md12xx-controller-detail', 'data-status="reason"', 'readJson', 'Enable MD12xx fan control now?') {
    if (-not ($settingsSource.Contains($marker) -or $pageSource.Contains($marker))) { throw "No-terminal UI safety marker is missing: $marker" }
}
foreach ($marker in 'liveControlSignature', 'Apply these live fan-control changes now?') {
    if (-not $settingsSource.Contains($marker)) { throw "Live-control confirmation marker is missing: $marker" }
}
if (-not $settingsSource.Contains('syncHardwareOptions({ refreshSerialPorts: true })')) { throw 'Serial assignment options do not refresh immediately.' }
foreach ($marker in 'payload.stale ? "fault"', 'Controller status is stale', 'Discovery data is stale', 'calibrationSummary') {
    if (-not $settingsSource.Contains($marker)) { throw "Stale-state or calibration UI marker is missing: $marker" }
}
if (-not $settingsSource.Contains('persisted.calibration && typeof persisted.calibration === "object"')) { throw 'Form collection does not fall back to the last saved calibration.' }
$commissionSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/commission.sh'))
if (-not $commissionSource.Contains('flock -w 15 9')) { throw 'Commissioning lock wait is missing.' }
if (-not $commissionSource.Contains('timeout "$SPEED_RESPONSE_SECONDS" cat "$PORT"')) { throw 'Commissioning does not open its response reader before writing.' }
if (-not $commissionSource.Contains('Final 20% restoration: PASS')) { throw 'Commissioning does not require final RPM restoration proof.' }
if (-not $commissionSource.Contains('md12xx_disable_active_discovery_after_setup')) { throw 'Commissioning does not disable active discovery after setup completes.' }
if (-not $commissionSource.Contains('$shelf["calibration"]=[')) { throw 'Commissioning calibration persistence is missing.' }
$markerIndex = $commissionSource.IndexOf('trap ''rm -f "$COMMISSION_MARKER"'' EXIT')
$mappingIndex = $commissionSource.IndexOf('MAPPING_JSON=')
if ($markerIndex -lt 0 -or $mappingIndex -lt 0 -or $markerIndex -gt $mappingIndex) { throw 'Commissioning marker is not retained through the final mapping write.' }
$commissionJobSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/commission-job.sh'))
foreach ($marker in 'RESULT_DIR_FILE', 'MD12XX_JOB_DIR=', 'tar -czf', 'Results: %s') {
    if (-not $commissionJobSource.Contains($marker)) { throw "Failed-test archive marker is missing: $marker" }
}
$templateSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'plugin/md12xx.fancontrol.plg.in'))
if (-not $templateSource.Contains('Setup is already complete; active connection testing was turned off.')) { throw 'Upgrade-time discovery shutdown is missing.' }
$commonSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/include/common.php'))
if (-not $commonSource.Contains('[20, 30, 40, 50, 60, 70, 80, 90, 100]')) { throw 'The complete Manual speed list is missing.' }
if (-not $commonSource.Contains("'pollSeconds' => 5") -or -not $commonSource.Contains('max(5, min(300')) { throw 'Five-second telemetry polling is not configured.' }
foreach ($marker in 'A serial adapter cannot be assigned to more than one shelf', 'An SES enclosure cannot be assigned to more than one shelf', 'An Unraid disk cannot be assigned to more than one shelf') {
    if (-not $commonSource.Contains($marker)) { throw "Duplicate hardware validation is missing: $marker" }
}
foreach ($marker in 'md12xx_commission_active', 'time() - $modified', "'calibration' => `$calibration") {
    if (-not $commonSource.Contains($marker)) { throw "Commissioning recovery or calibration validation marker is missing: $marker" }
}
foreach ($marker in 'md12xx_merge_settings_config', 'server-authoritative', "`$shelf['sesAddress'] = ''", "`$shelf['calibration'] = []") {
    if (-not $commonSource.Contains($marker)) { throw "Server-authoritative shelf merge marker is missing: $marker" }
}
if ($commonSource.IndexOf('if (!is_file($marker)) return false;') -gt $commonSource.IndexOf("glob('/proc/[0-9]*/cmdline')")) { throw 'Idle controller still scans every process for commissioning.' }
if (-not $commonSource.Contains('md12xx_fuser_binary')) { throw 'Fail-closed serial ownership check is missing.' }
$apiSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/include/api.php'))
if (-not $apiSource.Contains('md12xx_merge_settings_config($current, $decoded)')) { throw 'Settings saves do not use the server-authoritative shelf merge.' }
if (-not $apiSource.Contains('$activeId === $id && md12xx_commission_active()')) { throw 'Stale commissioning jobs can still appear to run forever.' }
foreach ($speed in 20, 30, 40, 50, 60, 70, 80, 90, 100) {
    if (-not $pageSource.Contains("value=`"$speed`">$speed%</option>")) { throw "Manual speed $speed% is missing from Settings." }
}
$controllerSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/include/controller.php'))
if (-not $controllerSource.Contains('$reader = @fopen($port, ''r'');') -or -not $controllerSource.Contains('$writer = @fopen($port, ''w'');')) { throw 'Controller reader-first serial session is missing.' }
if (-not $controllerSource.Contains("'state' => 'blocked'")) { throw 'Competing-controller shelf status is not blocked.' }
if (-not $controllerSource.Contains("'timeout 10 '")) { throw 'SES telemetry command timeout is missing.' }
if (-not $controllerSource.Contains("unset(`$controlConfig['discovery'], `$controlConfig['pollSeconds'])") -or -not $controllerSource.Contains('$controlChanged')) { throw 'Discovery-only changes are not isolated from control writes.' }
foreach ($marker in 'md12xx_controller_verify_target', '$pendingVerifications', "`$write['state'] === 'pending'", 'no assigned disks; using fail-safe', 'assigned disk inventory incomplete; fail-safe', 'automatic disk mapping changed; fail-safe') {
    if (-not $controllerSource.Contains($marker)) { throw "Closed-loop controller safety marker is missing: $marker" }
}
if (-not $text.Contains("'sas-expander'")) { throw 'SAS expander disk mapping fallback is missing.' }
if (-not (Test-Path (Join-Path $projectRoot 'tests/fixtures/disks-md1220.ini'))) { throw 'Synthetic MD1220 disk fixture is missing.' }
if (-not (Test-Path (Join-Path $projectRoot 'tests/fixtures/disks-duplicates.ini'))) { throw 'Duplicate block-device alias fixture is missing.' }

$runtimeRoot = Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol'
$runtimeFiles = Get-ChildItem $runtimeRoot -Recurse -File | Where-Object { $_.Extension -ne '.svg' }
$runtimeText = ($runtimeFiles | ForEach-Object { [System.IO.File]::ReadAllText($_.FullName) }) -join "`n"
$allowedSupportUrl = 'https://github.com/TheIlluminate92/unraid-md12xx-fan-control/issues/new?template=bug-report.yml'
if (-not $pageSource.Contains("href=`"$allowedSupportUrl`"") -or -not $pageSource.Contains('target="_blank" rel="noopener noreferrer"')) { throw 'User-activated GitHub support link is missing or unsafe.' }
if ($runtimeText.Replace($allowedSupportUrl, '') -match 'https?://|\b(curl|wget|socat|telnet|ssh|scp)\b|fsockopen|stream_socket_client|curl_[a-z_]+') { throw 'Outbound network primitive found in installed runtime.' }
if ($runtimeText -match '/mnt(/|$)|/root(/|$)|/home(/|$)|docker\.sock') { throw 'Broad filesystem or Docker socket access found in installed runtime.' }
$diagnosticsSource = [System.IO.File]::ReadAllText((Join-Path $runtimeRoot 'scripts/diagnose.sh'))
foreach ($marker in 'config.redacted.json', 'status.redacted.json', 'discovery.redacted.json', 'uname -rmo') {
    if (-not $diagnosticsSource.Contains($marker)) { throw "Diagnostics privacy marker is missing: $marker" }
}
foreach ($marker in 'diagnostics.lock', 'diagnostics.pid', 'flock -n 9') {
    if (-not $diagnosticsSource.Contains($marker)) { throw "Diagnostics lifecycle marker is missing: $marker" }
}
if ($diagnosticsSource.Contains('cp -f "$CONFIG_DIR/config.json"')) { throw 'Diagnostics copies raw configuration.' }
if (-not $diagnosticsSource.Contains('.missingDisks = ')) { throw 'Missing-disk diagnostics redaction is absent.' }
$downloadSource = [System.IO.File]::ReadAllText((Join-Path $runtimeRoot 'include/download.php'))
if (-not $downloadSource.Contains('basename(') -or -not $downloadSource.Contains('realpath(') -or -not $downloadSource.Contains('application/zip') -or -not $downloadSource.Contains('application/gzip')) { throw 'Local archive download validation is incomplete.' }
if ($text.Contains('@@')) { throw 'An unexpanded build placeholder remains.' }

$node = Get-Command node -ErrorAction SilentlyContinue
if ($node) { & $node.Source --check (Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/assets/js/settings.js') }

Write-Output 'MD12xx plugin manifest verification passed.'
