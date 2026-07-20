# Deploy Future Account on Windows (manual / self-hosted runner).
# Usage: .\scripts\deploy.ps1
$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$DeployEnv = if ($env:DEPLOY_ENV) { $env:DEPLOY_ENV } else { "prod" }
$Branch = if ($env:DEPLOY_BRANCH) { $env:DEPLOY_BRANCH } else { "main" }

$ComposeArgs = @("compose")
if ($DeployEnv -eq "prod" -and (Test-Path "docker-compose.prod.yml")) {
    $ComposeArgs = @("compose", "-f", "docker-compose.yml", "-f", "docker-compose.prod.yml")
}

function Invoke-Compose {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Args)
    & docker @ComposeArgs @Args
    if ($LASTEXITCODE -ne 0) { throw "docker compose failed: $($Args -join ' ')" }
}

Write-Host "==> Future Account deploy (env=$DeployEnv, branch=$Branch)"
Write-Host "==> Reminder: create a DB backup from Settings -> Backup before major upgrades."

Write-Host "==> Pulling latest from origin/$Branch..."
git fetch origin $Branch
git checkout $Branch
git pull --ff-only origin $Branch

Write-Host "==> Building containers..."
Invoke-Compose @("build")

Write-Host "==> Starting containers..."
Invoke-Compose @("up", "-d")

Write-Host "==> Waiting for backend..."
for ($i = 0; $i -lt 30; $i++) {
    Invoke-Compose @("exec", "-T", "backend", "php", "artisan", "--version") 2>$null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 2
}

Write-Host "==> Running migrations..."
Invoke-Compose @("exec", "-T", "backend", "php", "artisan", "migrate", "--force", "--no-ansi")

Write-Host "==> Checking whether admin user seed is needed..."
$userCount = (Invoke-Compose @("exec", "-T", "backend", "php", "artisan", "tinker", "--execute=echo \App\Models\User::count();") 2>$null | Out-String).Trim()
if ([string]::IsNullOrWhiteSpace($userCount)) { $userCount = "0" }
if ($userCount -eq "0") {
    Write-Host "==> No users — running AdminUserSeeder..."
    Invoke-Compose @("exec", "-T", "backend", "php", "artisan", "db:seed", "--class=AdminUserSeeder", "--force", "--no-ansi")
} else {
    Write-Host "==> Users exist ($userCount) — skipping AdminUserSeeder."
}

$BackendPort = if ($env:BACKEND_HEALTH_PORT) { $env:BACKEND_HEALTH_PORT } else { "8000" }
$FrontendPort = if ($env:FRONTEND_HEALTH_PORT) { $env:FRONTEND_HEALTH_PORT } else { "8080" }

Write-Host "==> Health check..."
try {
    Invoke-WebRequest -Uri "http://127.0.0.1:$BackendPort/up" -UseBasicParsing | Out-Null
    Write-Host "Backend health OK."
} catch {
    Write-Host "WARN: Backend health check failed on port $BackendPort."
}

Write-Host "==> Deploy finished successfully."
