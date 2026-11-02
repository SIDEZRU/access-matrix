<#
	Сборка архива релиза для магазина приложений Nextcloud.

	Правила исключения берутся из .nextcloudignore в корне приложения.
	Требования магазина (nextcloudappstore.readthedocs.io → App Developer Guide):
	  - в архиве ровно один каталог верхнего уровня — id приложения
	    (строчные ASCII-символы и подчёркивание);
	  - внутри него appinfo/info.xml;
	  - CHANGELOG.md в корне приложения — из него магазин берёт changelog релиза;
	  - каталог .git в архиве запрещён.

	Пример:
	  powershell -File tools/build-release.ps1 -SourceDir C:\path\to\access-matrix
#>
[CmdletBinding()]
param(
	[string]$SourceDir = (Split-Path -Parent $PSScriptRoot),
	[string]$OutDir = $SourceDir
)

$ErrorActionPreference = 'Stop'

function ConvertTo-Regex([string]$Pattern) {
	$escaped = [regex]::Escape($Pattern).Replace('\*', '[^/]*')
	return $escaped
}

# Возвращает $true, если путь относительно корня исключён правилом .nextcloudignore.
function Test-Ignored([string]$RelativePath, [array]$Patterns) {
	foreach ($pattern in $Patterns) {
		$glob = ConvertTo-Regex $pattern
		if ($pattern.StartsWith('/')) {
			# Правило от корня приложения: /docs → docs и всё, что внутри.
			if ($RelativePath -match "^$glob(/.*)?$") { return $true }
		}
		else {
			# Правило по имени: .git → любой .git на любом уровне.
			if ($RelativePath -match "(^|/)$glob(/.*)?$") { return $true }
		}
	}

	return $false
}

$source = (Resolve-Path -LiteralPath $SourceDir).Path
$ignoreFile = Join-Path $source '.nextcloudignore'
if (-not (Test-Path -LiteralPath $ignoreFile)) {
	throw "Не найден .nextcloudignore в $source"
}

$patterns = @(Get-Content -LiteralPath $ignoreFile | Where-Object { $_.Trim() -ne '' -and -not $_.StartsWith('#') })

[xml]$info = Get-Content -LiteralPath (Join-Path $source 'appinfo\info.xml') -Raw
$appId = $info.info.id
$version = $info.info.version
if (-not $appId -or -not $version) {
	throw 'Не удалось прочитать id/version из appinfo/info.xml'
}

$staging = Join-Path ([IO.Path]::GetTempPath()) ("nc-release-" + [guid]::NewGuid().ToString('N'))
$appDir = Join-Path $staging $appId
New-Item -ItemType Directory -Path $appDir -Force | Out-Null

$copied = 0
Get-ChildItem -LiteralPath $source -Recurse -File -Force | ForEach-Object {
	$relative = $_.FullName.Substring($source.Length + 1).Replace('\', '/')
	if (Test-Ignored $relative $patterns) { return }
	$target = Join-Path $appDir $relative
	New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
	Copy-Item -LiteralPath $_.FullName -Destination $target -Force
	$script:copied++
}

$archive = Join-Path $OutDir "$appId-$version.tar.gz"
if (Test-Path -LiteralPath $archive) { Remove-Item -LiteralPath $archive -Force }
& tar -czf $archive -C $staging $appId
if ($LASTEXITCODE -ne 0) { throw "tar завершился с кодом $LASTEXITCODE" }

# Проверки требований магазина.
$listing = @(& tar -tf $archive)
$roots = @($listing | ForEach-Object { ($_ -split '/')[0] } | Select-Object -Unique)
if ($roots.Count -ne 1 -or $roots[0] -ne $appId) {
	throw "В архиве ожидался единственный каталог $appId, получено: $($roots -join ', ')"
}
if ($listing -notcontains "$appId/appinfo/info.xml") { throw 'В архиве нет appinfo/info.xml' }
if ($listing -notcontains "$appId/CHANGELOG.md") { throw 'В архиве нет CHANGELOG.md — магазин не покажет changelog' }
if ($listing | Where-Object { $_ -match '(^|/)\.git(/|$)' }) { throw 'В архиве найден каталог .git — он запрещён' }

Remove-Item -LiteralPath $staging -Recurse -Force

$hash = (Get-FileHash -LiteralPath $archive -Algorithm SHA512).Hash.ToLower()
Write-Output "archive: $archive"
Write-Output "files: $copied"
Write-Output "sha512: $hash"
