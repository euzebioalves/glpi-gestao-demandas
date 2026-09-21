$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

docker compose ps

$output = & cmd.exe /d /c 'docker compose exec -T glpi php /opt/demandas-bootstrap/glpi_verify.php 2>&1'
$exitCode = $LASTEXITCODE
$output | ForEach-Object { Write-Host $_ }

if ($exitCode -ne 0) {
    throw "A homologação não atende aos requisitos mínimos. Execute '.\configure-mvp.ps1' e consulte os logs."
}

Write-Host "Homologação GLPI 11.0.9 validada com sucesso." -ForegroundColor Green
