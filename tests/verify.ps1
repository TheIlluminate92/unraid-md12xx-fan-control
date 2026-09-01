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
    '/usr/local/emhttp/plugins/md12xx.fancontrol/assets/js/settings.js',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/assets/css/settings.css',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/start.sh',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/stop.sh',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/commission.sh',
    '/usr/local/emhttp/plugins/md12xx.fancontrol/scripts/diagnose.sh'
)
foreach ($path in $required) { if (-not $text.Contains("Name=`"$path`"")) { throw "Missing packaged file: $path" } }

foreach ($forbidden in @(
    '/dev/sg18', '/dev/sg11', 'FTE33O9T', 'FTE32AB2',
    '/mnt/user/Back-Up', 'MD1200_TOP_', 'MD1200_BOTTOM_'
)) { if ($text.Contains($forbidden)) { throw "Server-specific value remains in standalone package: $forbidden" } }

foreach ($marker in @(
    'MD1200', 'MD1220', '/dev/serial/by-id/', 'commissioned',
    'set_speed', 'Actual\s+speed', 'window.csrf_token',
    'assigned disks spun down', 'temperature unavailable; fail-safe',
    'WAZ Dashboard MD1200 controller', 'fan speeds cannot decrease as temperature rises',
    'autoProbeKnownFtdi', 'MD12xx EMM console verified as primary and active'
)) { if ($text -notmatch [regex]::Escape($marker) -and $marker -notmatch '\\') { throw "Missing required marker: $marker" } }

if ($text -notmatch 'Actual\\s\+speed') { throw 'RPM parsing expression is missing.' }
if ($text -notmatch '\$payload = ''set_speed '' \. \$speed \. "\\r";') { throw 'Controller is not using carriage-return-only framing.' }
if ($text -match '\$payload = ''set_speed '' \. \$speed \. "\\r\\n";') { throw 'CRLF framing was reintroduced.' }
if ($text -match 'rm -rf\s+"/boot/config/plugins/md12xx\.fancontrol"') { throw 'Uninstall removes persistent settings.' }
$discoverySource = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/include/discovery.php'))
if ($discoverySource -match 'set_speed') { throw 'Read-only discovery contains a fan-speed command.' }
if ($text.Contains('@@')) { throw 'An unexpanded build placeholder remains.' }

$node = Get-Command node -ErrorAction SilentlyContinue
if ($node) { & $node.Source --check (Join-Path $projectRoot 'source/usr/local/emhttp/plugins/md12xx.fancontrol/assets/js/settings.js') }

Write-Output 'MD12xx plugin manifest verification passed.'
