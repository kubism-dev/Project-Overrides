param(
	[string] $OutputDirectory = (Join-Path $PSScriptRoot '..\dist')
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) 'project-overrides-build'
$pluginDirectory = Join-Path $stagingRoot 'project-overrides'
$archive = Join-Path $OutputDirectory 'project-overrides.zip'

if (Test-Path -LiteralPath $stagingRoot) {
	Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}

New-Item -ItemType Directory -Path $pluginDirectory -Force | Out-Null
New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null

$include = @(
	'assets',
	'src',
	'project-overrides.php',
	'readme.txt',
	'uninstall.php'
)

foreach ($item in $include) {
	Copy-Item -LiteralPath (Join-Path $root $item) -Destination $pluginDirectory -Recurse
}

if (Test-Path -LiteralPath $archive) {
	Remove-Item -LiteralPath $archive -Force
}

Compress-Archive -LiteralPath $pluginDirectory -DestinationPath $archive -CompressionLevel Optimal
Remove-Item -LiteralPath $stagingRoot -Recurse -Force

Write-Output $archive
