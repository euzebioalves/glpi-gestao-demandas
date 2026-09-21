$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$confirmation = Read-Host "Esta ação apagará TODOS os dados do GLPI e OpenProject deste MVP. Digite APAGAR para continuar"
if ($confirmation -cne "APAGAR") {
    Write-Host "Operação cancelada."
    exit 0
}

docker compose down --volumes --remove-orphans
Write-Host "Dados de homologação removidos. O arquivo .env foi preservado." -ForegroundColor Yellow
