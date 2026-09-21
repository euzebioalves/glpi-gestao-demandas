$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

function New-RandomHex([int]$Bytes) {
    $buffer = New-Object byte[] $Bytes
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) } finally { $generator.Dispose() }
    return ([System.BitConverter]::ToString($buffer) -replace '-', '').ToLowerInvariant()
}

function Get-MvpResult([string[]]$Output, [string]$Application) {
    $line = $Output | Where-Object { $_ -like 'MVP_RESULT=*' } | Select-Object -Last 1
    if (-not $line) {
        throw "O bootstrap do $Application não retornou um resultado reconhecível. Consulte os logs exibidos acima."
    }
    return ($line.Substring('MVP_RESULT='.Length) | ConvertFrom-Json)
}

if (-not (Test-Path ".env")) {
    throw "O arquivo .env não foi encontrado. Execute primeiro .\start.ps1."
}

$secretLine = Get-Content ".env" | Where-Object { $_ -match '^SECRET_KEY_BASE=(.+)$' } | Select-Object -Last 1
if (-not $secretLine) {
    throw "SECRET_KEY_BASE não foi encontrado ou está vazio no arquivo .env. Execute primeiro .\start.ps1."
}
$secretValue = ($secretLine -replace '^SECRET_KEY_BASE=', '').Trim().Trim('"').Trim("'")
if ($secretValue.Length -lt 32) {
    throw "SECRET_KEY_BASE é inválido. Execute .\start.ps1 para gerar uma chave segura."
}

& cmd.exe /d /c "docker info >nul 2>&1"
if ($LASTEXITCODE -ne 0) { throw "O Docker Desktop não está em execução." }

$services = docker compose ps --services --filter status=running
if ($LASTEXITCODE -ne 0 -or $services -notcontains 'glpi' -or $services -notcontains 'openproject') {
    throw "GLPI e OpenProject precisam estar em execução antes da configuração."
}

$credentialsPath = Join-Path $PSScriptRoot "integration.env"
$existing = @{}
if (Test-Path $credentialsPath) {
    Get-Content $credentialsPath | ForEach-Object {
        if ($_ -match '^([^#=]+)=(.*)$') { $existing[$matches[1]] = $matches[2] }
    }
}

$opPassword = if ($existing['OPENPROJECT_INTEGRATION_PASSWORD']) { $existing['OPENPROJECT_INTEGRATION_PASSWORD'] } else { "Op!" + (New-RandomHex 12) }
$opTestUserPassword = if ($existing['OPENPROJECT_TEST_USER_PASSWORD']) { $existing['OPENPROJECT_TEST_USER_PASSWORD'] } else { "Hom!" + (New-RandomHex 12) }
$requirementsPassword = if ($existing['GLPI_REQUIREMENTS_PASSWORD']) { $existing['GLPI_REQUIREMENTS_PASSWORD'] } else { "Req!" + (New-RandomHex 12) }
$customerPassword = if ($existing['GLPI_CUSTOMER_PASSWORD']) { $existing['GLPI_CUSTOMER_PASSWORD'] } else { "Cli!" + (New-RandomHex 12) }
$rotateOpenProjectToken = if ($existing['OPENPROJECT_API_TOKEN']) { "0" } else { "1" }

Write-Host "Configurando o OpenProject..." -ForegroundColor Cyan
$env:MVP_OPENPROJECT_INTEGRATION_PASSWORD = $opPassword
$env:MVP_OPENPROJECT_ROTATE_API_TOKEN = $rotateOpenProjectToken
$opOutput = & cmd.exe /d /c 'docker compose exec -T -e MVP_OPENPROJECT_INTEGRATION_PASSWORD -e MVP_OPENPROJECT_ROTATE_API_TOKEN openproject bundle exec rails runner /opt/demandas-bootstrap/openproject_bootstrap.rb 2>&1'
$opExitCode = $LASTEXITCODE
Remove-Item Env:MVP_OPENPROJECT_INTEGRATION_PASSWORD -ErrorAction SilentlyContinue
Remove-Item Env:MVP_OPENPROJECT_ROTATE_API_TOKEN -ErrorAction SilentlyContinue
$opOutput | ForEach-Object {
    Write-Host ($_ -replace '"api_token":"[^"]+"', '"api_token":"***"')
}
if ($opExitCode -ne 0) { throw "Falha durante a configuração do OpenProject." }
$opResult = Get-MvpResult $opOutput "OpenProject"

Write-Host "Configurando o modelo corporativo do OpenProject..." -ForegroundColor Cyan
$env:MVP_OPENPROJECT_TEST_USER_PASSWORD = $opTestUserPassword
$opCorporateOutput = & cmd.exe /d /c 'docker compose exec -T -e MVP_OPENPROJECT_TEST_USER_PASSWORD openproject bundle exec rails runner /opt/demandas-bootstrap/openproject_corporate_homologation.rb 2>&1'
$opCorporateExitCode = $LASTEXITCODE
Remove-Item Env:MVP_OPENPROJECT_TEST_USER_PASSWORD -ErrorAction SilentlyContinue
$opCorporateOutput | ForEach-Object { Write-Host $_ }
if ($opCorporateExitCode -ne 0) { throw "Falha durante a configuração do modelo corporativo do OpenProject." }
$opCorporateResult = Get-MvpResult $opCorporateOutput "OpenProject corporate homologation"

Write-Host "Configurando o GLPI..." -ForegroundColor Cyan
$env:MVP_GLPI_REQUIREMENTS_PASSWORD = $requirementsPassword
$env:MVP_GLPI_CUSTOMER_PASSWORD = $customerPassword
$glpiOutput = & cmd.exe /d /c 'docker compose exec -T -e MVP_GLPI_REQUIREMENTS_PASSWORD -e MVP_GLPI_CUSTOMER_PASSWORD glpi php /opt/demandas-bootstrap/glpi_bootstrap.php 2>&1'
$glpiExitCode = $LASTEXITCODE
Remove-Item Env:MVP_GLPI_REQUIREMENTS_PASSWORD -ErrorAction SilentlyContinue
Remove-Item Env:MVP_GLPI_CUSTOMER_PASSWORD -ErrorAction SilentlyContinue
$glpiOutput | ForEach-Object { Write-Host $_ }
if ($glpiExitCode -ne 0) { throw "Falha durante a configuração do GLPI." }
$glpiResult = Get-MvpResult $glpiOutput "GLPI"

$apiToken = if ($opResult.api_token) { $opResult.api_token } elseif ($existing['OPENPROJECT_API_TOKEN']) { $existing['OPENPROJECT_API_TOKEN'] } else { '' }

$integrationEnvironment = @"
# Gerado por configure-mvp.ps1. Não versionar nem compartilhar.
OPENPROJECT_INTERNAL_URL=http://openproject/api/v3
OPENPROJECT_EXTERNAL_URL=http://localhost:8280
OPENPROJECT_PROJECT_ID=$($opResult.project_id)
OPENPROJECT_PROJECT_IDENTIFIER=$($opResult.project_identifier)
OPENPROJECT_INTEGRATION_LOGIN=integracao.demandas
OPENPROJECT_INTEGRATION_PASSWORD=$opPassword
OPENPROJECT_API_TOKEN=$apiToken
OPENPROJECT_TEST_USER_PASSWORD=$opTestUserPassword
OPENPROJECT_TEST_USERS=ana.requisitos,bruno.desenvolvimento,carla.qa,diego.lideranca,elisa.produto,fabio.relacionamento

GLPI_INTERNAL_URL=http://glpi
GLPI_EXTERNAL_URL=http://localhost:8180
GLPI_REQUIREMENTS_LOGIN=requisitos.mvp
GLPI_REQUIREMENTS_PASSWORD=$requirementsPassword
GLPI_CUSTOMER_LOGIN=cliente.mvp
GLPI_CUSTOMER_PASSWORD=$customerPassword
GLPI_TEST_TICKET_ID=$($glpiResult.ticket_id)
"@
[System.IO.File]::WriteAllText($credentialsPath, $integrationEnvironment, [System.Text.UTF8Encoding]::new($false))

$openProjectReport = $opResult.PSObject.Copy()
$openProjectReport.PSObject.Properties.Remove('api_token')

$report = [ordered]@{
    generated_at = (Get-Date).ToString('o')
    openproject = $openProjectReport
    openproject_corporate_homologation = $opCorporateResult
    glpi = $glpiResult
}
$report | ConvertTo-Json -Depth 8 | Set-Content -Path "bootstrap-result.json" -Encoding utf8

Write-Host ""
Write-Host "Configuração concluída." -ForegroundColor Green
Write-Host "Credenciais locais: $credentialsPath" -ForegroundColor Yellow
Write-Host "Relatório: $(Join-Path $PSScriptRoot 'bootstrap-result.json')"
if (-not $apiToken) {
    Write-Warning "Já existia um token de API do usuário técnico e ele não pôde ser revelado. Gere um novo no OpenProject e informe-o em OPENPROJECT_API_TOKEN no integration.env."
}
