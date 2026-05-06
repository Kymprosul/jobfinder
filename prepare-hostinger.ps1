param(
    [string]$ProjectRoot = $PSScriptRoot
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path $ProjectRoot).Path
$packageRoot = Join-Path $projectRoot 'hostinger'
$publicRoot = Join-Path $packageRoot 'public_html'
$apiRoot = Join-Path $publicRoot 'api'
$backendSource = Join-Path $projectRoot 'backend'
$backendTarget = Join-Path $packageRoot 'backend'
$frontendRoot = Join-Path $projectRoot 'frontend'
$storageSource = Join-Path $backendSource 'storage'

function Write-Utf8File {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [string]$Content
    )

    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content + [Environment]::NewLine, $encoding)
}

Write-Host 'Building frontend...'
Push-Location $frontendRoot
try {
    npm run build | Out-Host
} finally {
    Pop-Location
}

if (Test-Path $packageRoot) {
    Remove-Item $packageRoot -Recurse -Force
}

New-Item -ItemType Directory -Path $publicRoot -Force | Out-Null
New-Item -ItemType Directory -Path $apiRoot -Force | Out-Null

Write-Host 'Copying frontend build...'
Copy-Item -Path (Join-Path $frontendRoot 'dist\*') -Destination $publicRoot -Recurse -Force

Write-Utf8File -Path (Join-Path $publicRoot '.htaccess') -Content @'
Options -Indexes

<IfModule mod_headers.c>
  <FilesMatch "\.(js|css|svg|png|jpg|jpeg|gif|webp|ico|woff|woff2)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>

<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /

  RewriteCond %{REQUEST_URI} !^/api/
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]
</IfModule>
'@

Write-Utf8File -Path (Join-Path $apiRoot '.htaccess') -Content @'
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.php [L,QSA]
</IfModule>
'@

Write-Utf8File -Path (Join-Path $apiRoot 'index.php') -Content @'
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/backend/public/index.php';
'@

Write-Host 'Copying backend...'
Copy-Item -Path $backendSource -Destination $packageRoot -Recurse -Force -Exclude '.env', 'composer.phar'

$storageRoot = Join-Path $backendTarget 'storage'
if (Test-Path $storageRoot) {
    Remove-Item $storageRoot -Recurse -Force
}

New-Item -ItemType Directory -Path $storageRoot -Force | Out-Null

$configSourcePath = Join-Path $storageSource 'config.json'
$configTargetPath = Join-Path $storageRoot 'config.json'

if (Test-Path $configSourcePath) {
    Copy-Item -Path $configSourcePath -Destination $configTargetPath -Force
} else {
    Write-Utf8File -Path $configTargetPath -Content '{}'
}

Write-Utf8File -Path (Join-Path $storageRoot 'jobs.json') -Content '[]'
Write-Utf8File -Path (Join-Path $storageRoot 'preview_jobs.json') -Content '[]'
Write-Utf8File -Path (Join-Path $storageRoot 'sent_jobs.json') -Content '[]'
Write-Utf8File -Path (Join-Path $storageRoot 'logs.json') -Content '[]'
Write-Utf8File -Path (Join-Path $storageRoot 'runs.json') -Content '[]'

Write-Host ''
Write-Host "Package ready in: $packageRoot"
Write-Host 'Upload the contents of hostinger/ so that public_html/ is your web root and backend/ stays as its sibling.'
