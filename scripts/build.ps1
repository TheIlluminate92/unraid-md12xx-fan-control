param([string]$OutputPath)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$templatePath = Join-Path $projectRoot 'plugin/md12xx.fancontrol.plg.in'
$sourceRoot = Join-Path $projectRoot 'source'
$version = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'VERSION')).Trim()

if (-not $OutputPath) {
    $OutputPath = Join-Path $projectRoot 'dist/md12xx.fancontrol.plg'
}

$blocks = foreach ($file in (Get-ChildItem -LiteralPath $sourceRoot -File -Recurse | Sort-Object FullName)) {
    $relative = $file.FullName.Substring($sourceRoot.Length).Replace('\', '/')
    $content = [System.IO.File]::ReadAllText($file.FullName).Replace("`r`n", "`n")
    $content = $content.Replace('@@VERSION@@', $version)
    if ($content.Contains(']]>')) { throw "Source file cannot be embedded in CDATA: $($file.FullName)" }
@"
<FILE Name="$relative">
<INLINE>
<![CDATA[
$content
]]>
</INLINE>
</FILE>
"@
}

$template = [System.IO.File]::ReadAllText($templatePath).Replace("`r`n", "`n")
$manifest = $template.Replace('@@FILES@@', ($blocks -join "`n")).Replace('@@VERSION@@', $version)
$outputDirectory = Split-Path -Parent $OutputPath
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
[System.IO.File]::WriteAllText($OutputPath, $manifest, [System.Text.UTF8Encoding]::new($false))
Write-Output "Built $OutputPath"

