[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function New-RandomToken([int]$Bytes) {
    $buffer = New-Object byte[] $Bytes
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) } finally { $generator.Dispose() }
    return ([Convert]::ToHexString($buffer)).ToLowerInvariant()
}

function Invoke-E2ECompose([string[]]$Arguments) {
    & docker compose --env-file .env.e2e -f docker-compose.e2e.yml @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Falha no Docker Compose: $($Arguments -join ' ')" }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker não foi encontrado. Inicie o Docker Desktop antes de criar a base E2E.'
}

& cmd.exe /d /c 'docker info >nul 2>&1'
if ($LASTEXITCODE -ne 0) { throw 'O Docker Desktop não está em execução.' }

if (-not (Test-Path .env.e2e)) {
    $fixturePassword = 'E2E!' + (New-RandomToken 12)
    $dockerEnvironment = @"
# Ambiente local e descartável para Playwright. Não compartilhar.
E2E_GLPI_HTTP_PORT=8190
E2E_DB_NAME=glpi_e2e
E2E_DB_USER=glpi_e2e
E2E_DB_PASSWORD=$(New-RandomToken 24)
E2E_DB_ROOT_PASSWORD=$(New-RandomToken 24)
E2E_GLPI_FIXTURE_PASSWORD=$fixturePassword
"@
    [System.IO.File]::WriteAllText((Join-Path $PSScriptRoot '.env.e2e'), $dockerEnvironment, [System.Text.UTF8Encoding]::new($false))

    $playwrightEnvironment = @"
# Gerado por start-e2e.ps1. Base local isolada e exclusivamente fictícia.
E2E_GLPI_BASE_URL=http://localhost:8190
E2E_GLPI_OPERATOR_USER=demandas.e2e.operator
E2E_GLPI_OPERATOR_PASSWORD=$fixturePassword
E2E_GLPI_ADMIN_USER=demandas.e2e.admin
E2E_GLPI_ADMIN_PASSWORD=$fixturePassword
E2E_GLPI_VIEWER_USER=demandas.e2e.viewer
E2E_GLPI_VIEWER_PASSWORD=$fixturePassword
E2E_GLPI_RESTRICTED_USER=demandas.e2e.restricted
E2E_GLPI_RESTRICTED_PASSWORD=$fixturePassword
E2E_ISOLATED_FIXTURE=1
E2E_ALLOW_MUTATIONS=0
E2E_ALLOW_ARTIFACTS=1
E2E_UPDATE_MANUAL_SCREENSHOTS=0
"@
    [System.IO.File]::WriteAllText((Join-Path $PSScriptRoot 'tests/e2e/.env.e2e'), $playwrightEnvironment, [System.Text.UTF8Encoding]::new($false))
}

Invoke-E2ECompose @('up', '-d', '--build')

$healthy = $false
for ($attempt = 1; $attempt -le 48; $attempt++) {
    $status = (& docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' demandas-e2e1109-glpi 2>$null).Trim()
    if ($status -eq 'healthy') {
        $healthy = $true
        break
    }
    Start-Sleep -Seconds 5
}
if (-not $healthy) {
    Invoke-E2ECompose @('logs', '--tail', '120', 'glpi', 'db')
    throw 'A base E2E não ficou saudável no prazo esperado.'
}

$installed = $false
& docker compose --env-file .env.e2e -f docker-compose.e2e.yml exec -T glpi php bin/console database:is_up_to_date --quiet
if ($LASTEXITCODE -eq 0) { $installed = $true }
if (-not $installed) {
    & docker compose --env-file .env.e2e -f docker-compose.e2e.yml exec -T glpi sh -lc 'php bin/console database:install --db-host="$GLPI_DB_HOST" --db-port="$GLPI_DB_PORT" --db-name="$GLPI_DB_NAME" --db-user="$GLPI_DB_USER" --db-password="$GLPI_DB_PASSWORD" --no-interaction --no-telemetry'
    if ($LASTEXITCODE -ne 0) {
        throw 'Não foi possível instalar o banco isolado do GLPI para os testes E2E.'
    }
}

$pluginsJson = & docker compose --env-file .env.e2e -f docker-compose.e2e.yml exec -T glpi php bin/console plugin:list --format=json
if ($LASTEXITCODE -ne 0) { throw 'Não foi possível consultar o estado do plugin na base E2E.' }
$demandasPlugin = @($pluginsJson | ConvertFrom-Json | Where-Object { $_.key -eq 'demandas' }) | Select-Object -First 1
if ($null -eq $demandasPlugin) { throw 'O plugin demandas não foi encontrado no contêiner da base E2E.' }
if ($demandasPlugin.state -in @('Not installed', 'To update')) {
    Invoke-E2ECompose @('exec', '-T', 'glpi', 'php', 'bin/console', 'plugin:install', '--username=glpi', 'demandas')
    $demandasPlugin.state = 'Installed'
}
if ($demandasPlugin.state -ne 'Enabled') {
    Invoke-E2ECompose @('exec', '-T', 'glpi', 'php', 'bin/console', 'plugin:activate', 'demandas')
}

$fixturePasswordLine = Get-Content .env.e2e | Where-Object { $_ -match '^E2E_GLPI_FIXTURE_PASSWORD=' } | Select-Object -Last 1
$env:E2E_GLPI_FIXTURE_PASSWORD = $fixturePasswordLine.Substring('E2E_GLPI_FIXTURE_PASSWORD='.Length)
try {
    Invoke-E2ECompose @('exec', '-T', '-e', 'E2E_GLPI_FIXTURE_PASSWORD', 'glpi', 'php', '/opt/demandas-e2e/glpi_e2e_bootstrap.php')
} finally {
    Remove-Item Env:E2E_GLPI_FIXTURE_PASSWORD -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host 'Base E2E pronta: http://localhost:8190' -ForegroundColor Green
Write-Host 'Execute os testes com:' -ForegroundColor Green
Write-Host '.\run-e2e-tests.ps1 -EnvironmentFile tests/e2e/.env.e2e' -ForegroundColor Yellow
