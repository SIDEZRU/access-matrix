<#
	Публикация приложения access_matrix в магазине Nextcloud (apps.nextcloud.com).

	Шаги (официальный REST API магазина):
	  1. POST /api/v1/apps          — регистрация приложения: certificate + signature
	  2. POST /api/v1/apps/releases — публикация релиза: download + signature

	Входные данные берутся из OpenBao и локального каталога сертификатов:
	  - токен магазина  : sidez/prod/nextcloud/appstore → token
	  - сертификат     : C:\Users\Tim\.nextcloud\certificates\access_matrix.crt
	  - подписи        : C:\Users\Tim\.nextcloud\certificates\access_matrix-<version>.release.txt
	  - архив релиза   : URL публикации GitHub Release

	Пример:
	  powershell -File tools/publish-appstore.ps1 -Version 0.5.7 `
	      -Download https://github.com/SIDEZRU/access-matrix/releases/download/v0.5.7/access_matrix-0.5.7.tar.gz
#>
[CmdletBinding()]
param(
	[string]$AppId = 'access_matrix',
	[string]$Version = '0.5.7',
	[string]$Download = '',
	[string]$CertificatePath = "$env:USERPROFILE\.nextcloud\certificates\$AppId.crt",
	[string]$SignaturePath = "$env:USERPROFILE\.nextcloud\certificates\$AppId-$Version.release.txt",
	[string]$Token = '',
	[string]$Store = 'https://apps.nextcloud.com'
)

$ErrorActionPreference = 'Stop'

if ($Token -eq '') {
	$Token = & 'E:\sidez-dev\sec\openbao\init\bao-get.ps1' -Mount prod -Path 'nextcloud/appstore' -Field 'token'
}
if (-not $Token) { throw 'Не удалось получить токен магазина из OpenBao' }
if ($Download -eq '') { throw 'Укажите -Download: публичная HTTPS-ссылка на архив релиза' }
if (-not (Test-Path -LiteralPath $CertificatePath)) { throw "Нет сертификата: $CertificatePath" }
if (-not (Test-Path -LiteralPath $SignaturePath)) { throw "Нет файла подписей: $SignaturePath" }

$certificate = (Get-Content -LiteralPath $CertificatePath -Raw).Trim()
$signatures = @{}
Get-Content -LiteralPath $SignaturePath | ForEach-Object {
	if ($_ -match '^([a-z_]+):\s*(.+)$') { $signatures[$matches[1]] = $matches[2].Trim() }
}
if (-not $signatures.ContainsKey('signature_id')) { throw 'В файле подписей нет signature_id' }
if (-not $signatures.ContainsKey('signature_archive')) { throw 'В файле подписей нет signature_archive' }

$headers = @{ 'Authorization' = "Token $Token"; 'Accept' = 'application/json'; 'Content-Type' = 'application/json' }

# 1. Регистрация приложения.
$registerBody = @{
	certificate       = $certificate
	signature         = $signatures['signature_id']
	is_enterprise_only = $false
} | ConvertTo-Json -Compress

try {
	$response = Invoke-WebRequest -Method Post -Uri "$Store/api/v1/apps" -Headers $headers -Body $registerBody
	Write-Output "регистрация: HTTP $($response.StatusCode)"
}
catch {
	$status = [int]$_.Exception.Response.StatusCode
	$detail = $_.ErrorDetails.Message
	if ($status -eq 204) { Write-Output 'регистрация: приложение уже зарегистрировано (204)' }
	else { throw "Регистрация не удалась (HTTP $status): $detail" }
}

# 2. Публикация релиза.
$releaseBody = @{
	download = $Download
	signature = $signatures['signature_archive']
	nightly = $false
} | ConvertTo-Json -Compress

try {
	$response = Invoke-WebRequest -Method Post -Uri "$Store/api/v1/apps/releases" -Headers $headers -Body $releaseBody
	Write-Output "релиз: HTTP $($response.StatusCode) — $($response.Content)"
}
catch {
	$status = [int]$_.Exception.Response.StatusCode
	throw "Публикация релиза не удалась (HTTP $status): $($_.ErrorDetails.Message)"
}

# 3. Проверка: приложение видно в каталоге магазина.
$apps = Invoke-RestMethod -Uri "$Store/api/v1/apps.json" -Headers @{ 'User-Agent' = 'kilo' }
$app = $apps | Where-Object { $_.id -eq $AppId }
if ($app) {
	Write-Output "в каталоге: $($app.id), релизов: $($app.releases.Count)"
	$app.releases | ForEach-Object { Write-Output "  версия $($_.version), загрузка $($_.download)" }
}
else {
	Write-Output "приложение $AppId пока не появилось в /api/v1/apps.json (проверьте позже)"
}
