# ============================================================
# Olive Note - リリースビルドスクリプト (Windows PowerShell 版)
#
# 使い方:
#   powershell -ExecutionPolicy Bypass -File scripts/build-release.ps1
#   powershell -ExecutionPolicy Bypass -File scripts/build-release.ps1 -Version 1.2.3
# ============================================================
param(
    [string]$Version
)

$ErrorActionPreference = 'Stop'

$RootDir   = (Resolve-Path "$PSScriptRoot\..").Path
$PkgDir    = Join-Path $RootDir 'olivenote-package'
$BuildDir  = Join-Path $RootDir 'build'

# バージョン
if (-not $Version) {
    $Version = (Get-Content (Join-Path $PkgDir 'app\VERSION') -Raw).Trim()
} else {
    Set-Content -Path (Join-Path $PkgDir 'app\VERSION') -Value $Version -NoNewline -Encoding ASCII
}

$ZipName = "olivenote-$Version.zip"
$ZipPath = Join-Path $BuildDir $ZipName

Write-Host "🌿 Olive Note v$Version をビルドします" -ForegroundColor Green

# クリーンアップ
if (Test-Path $BuildDir) { Remove-Item -Recurse -Force $BuildDir }
$Staging = Join-Path $BuildDir 'staging\olivenote'
New-Item -ItemType Directory -Force -Path $Staging | Out-Null

# コピー（除外パターン付き）
$Excludes = @(
    'config\config.php',
    'data\backups\*',
    'data\cache\*',
    'data\tmp\*',
    'data\MAINTENANCE.lock',
    'installer.locked',
    '.DS_Store',
    'Thumbs.db'
)

# robocopy で除外コピー
$xfPaths = $Excludes | Where-Object { $_ -notmatch '\*' }
$xfArgs = @()
foreach ($p in $xfPaths) { $xfArgs += '/XF'; $xfArgs += $p }

robocopy $PkgDir $Staging /E /XD installer.locked data\backups data\cache data\tmp @xfArgs | Out-Null

# data/ 以下の空ディレクトリを保つ
foreach ($d in @('backups','cache','tmp')) {
    $path = Join-Path $Staging "data\$d"
    New-Item -ItemType Directory -Force -Path $path | Out-Null
    New-Item -ItemType File -Force -Path "$path\.gitkeep" | Out-Null
}

# config/config.php が紛れ込んだ場合の保険
$cfgPath = Join-Path $Staging 'config\config.php'
if (Test-Path $cfgPath) { Remove-Item -Force $cfgPath }

# docs/ を同梱（インストーラのリンク先 ../docs/view.php が動くようにするため）
$DocsSrc = Join-Path $RootDir 'docs'
$DocsDst = Join-Path $Staging 'docs'
if (Test-Path $DocsSrc) {
    New-Item -ItemType Directory -Force -Path $DocsDst | Out-Null
    robocopy $DocsSrc $DocsDst /E /XF '.DS_Store' 'Thumbs.db' | Out-Null
} else {
    Write-Host "⚠ docs/ ソースが見つかりません: $DocsSrc （ドキュメントなしでビルド継続）" -ForegroundColor Yellow
}

# ZIP化
Push-Location (Join-Path $BuildDir 'staging')
Compress-Archive -Path 'olivenote' -DestinationPath $ZipPath -Force
Pop-Location

# SHA256
$Sha = (Get-FileHash -Algorithm SHA256 -Path $ZipPath).Hash.ToLower()
$Size = (Get-Item $ZipPath).Length

# CHECKSUMS.txt
"$Sha  $ZipName" | Set-Content -Path (Join-Path $BuildDir 'CHECKSUMS.txt')

# CHANGELOG から該当バージョンを抜粋
$Changelog = Join-Path $RootDir 'CHANGELOG.md'
$Notes = ''
if (Test-Path $Changelog) {
    $lines = Get-Content $Changelog
    $inSection = $false
    $sb = New-Object System.Text.StringBuilder
    foreach ($line in $lines) {
        if ($line -match "^## \[$([regex]::Escape($Version))\]") { $inSection = $true; continue }
        if ($inSection -and $line -match '^## \[') { break }
        if ($inSection) { [void]$sb.AppendLine($line) }
    }
    $Notes = $sb.ToString().Trim() -replace '"', '\"' -replace "`r`n|`n", '\n'
}

# manifest.json
$Manifest = @{
    latest          = $Version
    download_url    = "https://github.com/Ha-llelujah3528/olivenote/releases/download/v$Version/$ZipName"
    sha256          = $Sha
    size_bytes      = $Size
    min_php_version = '8.0'
    changelog       = $Notes
    published_at    = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
} | ConvertTo-Json -Depth 3

Set-Content -Path (Join-Path $BuildDir 'manifest.json') -Value $Manifest -Encoding UTF8

# クリーンアップ
Remove-Item -Recurse -Force (Join-Path $BuildDir 'staging')

Write-Host "✅ ビルド完了:" -ForegroundColor Green
Write-Host "   ZIP:      $ZipPath ($Size bytes)"
Write-Host "   SHA256:   $Sha"
Write-Host "   Manifest: $(Join-Path $BuildDir 'manifest.json')"
