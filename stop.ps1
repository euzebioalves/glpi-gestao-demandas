$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

docker compose down
Write-Host "Ambiente interrompido. Os dados foram preservados nos volumes Docker." -ForegroundColor Green
