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
foreach ($field in 'Shelf model', 'Unraid version', 'HBA and driver', 'EMM and cabling arrangement', 'Commissioning result', 'Discovery summary', 'Disk mapping', 'Competing fan controller state during testing', 'Optional redacted diagnostics') {
    if (-not $hardwareForm.Contains($field)) { throw "Hardware issue form is missing: $field" }
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
if (-not $pageSource.Contains('Icon="icon.svg"')) { throw 'Packaged Settings icon is not configured.' }
if ($pageSource -match '(?i)terminal') { throw 'Terminal-only wording remains in Settings.' }
$discoverySource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/include/discovery.php'))
if ($discoverySource -match 'set_speed') { throw 'Read-only discovery contains a fan-speed command.' }
if (-not $discoverySource.Contains('commissioning.active')) { throw 'Discovery does not yield to commissioning.' }
$settingsSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/assets/js/settings.js'))
if (-not $settingsSource.Contains('Setup is complete; turn off Test likely FTDI adapters')) { throw 'Completed-setup discovery reminder is missing.' }
if (-not $settingsSource.Contains('Associated Unraid disks') -or -not $pageSource.Contains('Export local diagnostics')) { throw 'Shelf mapping or local diagnostics UI is missing.' }
foreach ($marker in 'md12xx-commission-start', 'startCommission', 'pollCommission', 'It continues safely even if this page is closed', 'window.setTimeout(updateTitle, 400)', 'updateShelfStatus', 'Download test results', 'type=diagnostics') {
    if (-not $settingsSource.Contains($marker)) { throw "App commissioning or focus-safe refresh marker is missing: $marker" }
}
$commissionSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/commission.sh'))
if (-not $commissionSource.Contains('flock -w 15 9')) { throw 'Commissioning lock wait is missing.' }
if (-not $commissionSource.Contains('timeout "$SPEED_RESPONSE_SECONDS" cat "$PORT"')) { throw 'Commissioning does not open its response reader before writing.' }
if (-not $commissionSource.Contains('Final 20% restoration: PASS')) { throw 'Commissioning does not require final RPM restoration proof.' }
if (-not $commissionSource.Contains('md12xx_disable_active_discovery_after_setup')) { throw 'Commissioning does not disable active discovery after setup completes.' }
$templateSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'plugin/md12xx.fancontrol.plg.in'))
if (-not $templateSource.Contains('Setup is already complete; active connection testing was turned off.')) { throw 'Upgrade-time discovery shutdown is missing.' }
$commonSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/include/common.php'))
if (-not $commonSource.Contains('[20, 30, 40, 50, 60, 70, 80, 90, 100]')) { throw 'The complete Manual speed list is missing.' }
if (-not $commonSource.Contains("'pollSeconds' => 5") -or -not $commonSource.Contains('max(5, min(300')) { throw 'Five-second telemetry polling is not configured.' }
foreach ($speed in 20, 30, 40, 50, 60, 70, 80, 90, 100) {
    if (-not $pageSource.Contains("value=`"$speed`">$speed%</option>")) { throw "Manual speed $speed% is missing from Settings." }
}
$controllerSource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/include/controller.php'))
if (-not $controllerSource.Contains('$reader = @fopen($port, ''r'');') -or -not $controllerSource.Contains('$writer = @fopen($port, ''w'');')) { throw 'Controller reader-first serial session is missing.' }
if (-not $controllerSource.Contains("'state' => 'blocked'")) { throw 'Competing-controller shelf status is not blocked.' }
if (-not $text.Contains("'sas-expander'")) { throw 'SAS expander disk mapping fallback is missing.' }
if (-not (Test-Path (Join-Path $projectRoot 'tests/fixtures/disks-md1220.ini'))) { throw 'Synthetic MD1220 disk fixture is missing.' }

$runtimeRoot = Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol'
$runtimeFiles = Get-ChildItem $runtimeRoot -Recurse -File | Where-Object { $_.Extension -ne '.svg' }
$runtimeText = ($runtimeFiles | ForEach-Object { [System.IO.File]::ReadAllText($_.FullName) }) -join "`n"
if ($runtimeText -match 'https?://|\b(curl|wget|socat|telnet|ssh|scp)\b|fsockopen|stream_socket_client|curl_[a-z_]+') { throw 'Outbound network primitive found in installed runtime.' }
if ($runtimeText -match '/mnt(/|$)|/root(/|$)|/home(/|$)|docker\.sock') { throw 'Broad filesystem or Docker socket access found in installed runtime.' }
$diagnosticsSource = [System.IO.File]::ReadAllText((Join-Path $runtimeRoot 'scripts/diagnose.sh'))
foreach ($marker in 'config.redacted.json', 'status.redacted.json', 'discovery.redacted.json', 'uname -rmo') {
    if (-not $diagnosticsSource.Contains($marker)) { throw "Diagnostics privacy marker is missing: $marker" }
}
if ($diagnosticsSource.Contains('cp -f "$CONFIG_DIR/config.json"')) { throw 'Diagnostics copies raw configuration.' }
$downloadSource = [System.IO.File]::ReadAllText((Join-Path $runtimeRoot 'include/download.php'))
if (-not $downloadSource.Contains('basename(') -or -not $downloadSource.Contains('realpath(') -or -not $downloadSource.Contains('application/zip')) { throw 'Local archive download validation is incomplete.' }
if ($text.Contains('@@')) { throw 'An unexpanded build placeholder remains.' }

$node = Get-Command node -ErrorAction SilentlyContinue
if ($node) { & $node.Source --check (Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/assets/js/settings.js') }

Write-Output 'MD12xx plugin manifest verification passed.'
