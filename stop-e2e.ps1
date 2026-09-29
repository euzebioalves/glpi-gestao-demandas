[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
docker compose --env-file .env.e2e -f docker-compose.e2e.yml down
if ($LASTEXITCODE -ne 0) {
    throw 'Não foi possível parar a base E2E.'
}
Write-Host 'Base E2E parada. Os volumes fictícios foram preservados.' -ForegroundColor Green
