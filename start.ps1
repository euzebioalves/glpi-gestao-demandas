$ErrorActionPreference = "Stop"

Set-Location $PSScriptRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw "Docker não foi encontrado. Instale ou inicie o Docker Desktop."
}

& cmd.exe /d /c "docker info >nul 2>&1"
if ($LASTEXITCODE -ne 0) {
    throw "O Docker Desktop não está em execução."
}

if (-not (Test-Path ".env")) {
    function New-RandomHex([int]$Bytes) {
        $buffer = New-Object byte[] $Bytes
        $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
        try {
            $generator.GetBytes($buffer)
        }
        finally {
            $generator.Dispose()
        }

        return ([System.BitConverter]::ToString($buffer) -replace '-', '').ToLowerInvariant()
    }

    $glpiPassword = New-RandomHex 24
    $glpiRootPassword = New-RandomHex 24
    $openProjectSecret = New-RandomHex 64
    $openProjectAdminPassword = "Op!" + (New-RandomHex 12)

    $environment = @"
TZ=America/Sao_Paulo

GLPI_HTTP_PORT=8180
GLPI_DB_NAME=glpi
GLPI_DB_USER=glpi
GLPI_DB_PASSWORD=$glpiPassword
GLPI_DB_ROOT_PASSWORD=$glpiRootPassword

OPENPROJECT_HTTP_PORT=8280
OPENPROJECT_HOST_NAME=localhost:8280
SECRET_KEY_BASE=$openProjectSecret
OPENPROJECT_ADMIN_PASSWORD=$openProjectAdminPassword
"@

    [System.IO.File]::WriteAllText(
        (Join-Path $PSScriptRoot ".env"),
        $environment,
        [System.Text.UTF8Encoding]::new($false)
    )

    Write-Host "Arquivo .env criado com credenciais aleatórias." -ForegroundColor Green
    Write-Host "Senha inicial do administrador do OpenProject: $openProjectAdminPassword" -ForegroundColor Yellow
    Write-Host "Guarde esta senha antes de fechar a janela." -ForegroundColor Yellow
}

# Migra pacotes anteriores para o nome oficial esperado pela imagem do
# OpenProject, preservando exatamente a chave que já estava em uso.
$envLines = [System.Collections.Generic.List[string]](Get-Content ".env")
$hasOfficialSecret = $envLines | Where-Object { $_ -match '^SECRET_KEY_BASE=.+' }
if (-not $hasOfficialSecret) {
    $legacySecretLine = $envLines | Where-Object { $_ -match '^OPENPROJECT_SECRET_KEY_BASE=(.+)$' } | Select-Object -Last 1
    if (-not $legacySecretLine) {
        throw "Não foi encontrada uma chave do OpenProject no arquivo .env."
    }

    $legacySecret = $legacySecretLine.Substring('OPENPROJECT_SECRET_KEY_BASE='.Length).Trim()
    if ($legacySecret.Length -lt 32) {
        throw "A chave existente do OpenProject é inválida."
    }

    $envLines.Add("SECRET_KEY_BASE=$legacySecret")
    [System.IO.File]::WriteAllLines(
        (Join-Path $PSScriptRoot ".env"),
        $envLines,
        [System.Text.UTF8Encoding]::new($false)
    )
    Write-Host "Variável SECRET_KEY_BASE migrada no arquivo .env." -ForegroundColor Green
}

docker compose config --quiet
if ($LASTEXITCODE -ne 0) {
    throw "A configuração do Docker Compose é inválida."
}

docker compose pull glpi-db openproject
docker compose up -d --build

Write-Host ""
Write-Host "Ambiente iniciado." -ForegroundColor Green
Write-Host "GLPI:        http://localhost:8180"
Write-Host "OpenProject: http://localhost:8280"
Write-Host ""
Write-Host "Use '.\status.ps1' para acompanhar a inicialização."
