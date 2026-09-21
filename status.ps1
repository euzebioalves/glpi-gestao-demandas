$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

docker compose ps

Write-Host ""
Write-Host "GLPI:        http://localhost:8180"
Write-Host "OpenProject: http://localhost:8280"
Write-Host ""
Write-Host "Na primeira execução, o OpenProject pode levar alguns minutos para ficar saudável."
