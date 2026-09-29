[CmdletBinding()]
param(
    [ValidateSet('ReadOnly', 'Practical', 'Manual')]
    [string]$Mode = 'ReadOnly',
    [switch]$Configure,
    [string]$EnvironmentFile = 'tests/e2e/.env'
)

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function Invoke-Npm([string[]]$Arguments) {
    Write-Host ("> npm " + ($Arguments -join ' ')) -ForegroundColor DarkGray
    & npm.cmd @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "O comando npm falhou: npm $($Arguments -join ' ')"
    }
}

function Read-Required([string]$Prompt) {
    do { $value = (Read-Host $Prompt).Trim() } while ($value -eq '')
    return $value
}

function Read-PasswordText([string]$Prompt) {
    $secure = Read-Host $Prompt -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
}

function New-E2EEnvironment([string]$Path) {
    Write-Host ''
    Write-Host 'Configure somente uma instância descartável com dados fictícios.' -ForegroundColor Yellow
    $confirmation = Read-Host 'Digite AMBIENTE-FICTICIO para confirmar'
    if ($confirmation -ne 'AMBIENTE-FICTICIO') {
        throw 'Configuração interrompida: a suíte não pode ser preparada para ambiente compartilhado ou produção.'
    }

    $baseUrl = Read-Required 'URL do GLPI isolado (ex.: http://localhost:8180)'
    $operatorUser = Read-Required 'Usuário fictício operador'
    $operatorPassword = Read-PasswordText 'Senha do operador'
    $adminUser = Read-Required 'Usuário fictício administrador (pode ser o operador)'
    $adminPassword = Read-PasswordText 'Senha do administrador'
    $viewerUser = Read-Required 'Usuário fictício somente leitura'
    $viewerPassword = Read-PasswordText 'Senha do visualizador'
    $restrictedUser = Read-Required 'Usuário fictício sem direitos técnicos'
    $restrictedPassword = Read-PasswordText 'Senha do usuário restrito'

    # Administrador e operador podem compartilhar a conta. Os dois cenários de
    # autorização, porém, só são significativos com contas de interface distintas.
    $privilegedUsers = @($operatorUser, $adminUser)
    if ($viewerUser -in $privilegedUsers) {
        throw 'O usuário somente leitura deve ser diferente do administrador e do operador; caso contrário, a suíte não consegue validar a segregação de permissões.'
    }
    if ($restrictedUser -in ($privilegedUsers + $viewerUser)) {
        throw 'O usuário sem direitos técnicos deve ser uma conta distinta, sem permissões técnicas nem operacionais.'
    }

    $content = @"
# Gerado localmente por run-e2e-tests.ps1. Não versionar nem compartilhar.
E2E_GLPI_BASE_URL=$baseUrl
E2E_GLPI_OPERATOR_USER=$operatorUser
E2E_GLPI_OPERATOR_PASSWORD=$operatorPassword
E2E_GLPI_ADMIN_USER=$adminUser
E2E_GLPI_ADMIN_PASSWORD=$adminPassword
E2E_GLPI_VIEWER_USER=$viewerUser
E2E_GLPI_VIEWER_PASSWORD=$viewerPassword
E2E_GLPI_RESTRICTED_USER=$restrictedUser
E2E_GLPI_RESTRICTED_PASSWORD=$restrictedPassword
# Permanece bloqueado mesmo após o configurador. Libere manualmente somente
# depois de confirmar a instância descartável e os dados exclusivamente fictícios.
E2E_ISOLATED_FIXTURE=0
E2E_ALLOW_MUTATIONS=0
E2E_ALLOW_ARTIFACTS=0
E2E_UPDATE_MANUAL_SCREENSHOTS=0
"@
    [System.IO.File]::WriteAllText($Path, $content, [System.Text.UTF8Encoding]::new($false))
    Write-Host "Arquivo local criado: $Path" -ForegroundColor Green
}

function Test-IsolatedEnvironment([string]$Path, [string]$RequestedMode) {
    if ($RequestedMode -eq 'ReadOnly') { return }
    $isolated = Select-String -LiteralPath $Path -Pattern '^E2E_ISOLATED_FIXTURE=1$' -Quiet
    if (-not $isolated) {
        throw 'Os modos Practical e Manual exigem E2E_ISOLATED_FIXTURE=1 em tests/e2e/.env. Depois de confirmar uma base descartável com dados somente fictícios, altere esse valor manualmente.'
    }
    if ($RequestedMode -eq 'Manual') {
        $allowArtifacts = Select-String -LiteralPath $Path -Pattern '^E2E_ALLOW_ARTIFACTS=1$' -Quiet
        if (-not $allowArtifacts) {
            throw 'O modo Manual exige E2E_ALLOW_ARTIFACTS=1 em tests/e2e/.env, pois ele grava screenshots. Libere somente em uma base descartável com dados fictícios.'
        }
    }
}

if (-not (Get-Command node -ErrorAction SilentlyContinue) -or -not (Get-Command npm.cmd -ErrorAction SilentlyContinue)) {
    throw 'Node.js LTS e npm são necessários. Instale-os antes de executar a suíte Playwright.'
}

$environmentPath = if ([System.IO.Path]::IsPathRooted($EnvironmentFile)) {
    $EnvironmentFile
} else {
    Join-Path $PSScriptRoot $EnvironmentFile
}
if ($Configure) {
    New-E2EEnvironment $environmentPath
} elseif (-not (Test-Path -LiteralPath $environmentPath)) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'tests/e2e/.env.example') -Destination $environmentPath
    throw "Foi criado o modelo local $environmentPath. Edite as credenciais fictícias ou execute .\run-e2e-tests.ps1 -Configure."
}

Test-IsolatedEnvironment $environmentPath $Mode
$env:E2E_ENV_FILE = $environmentPath

if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'node_modules/@playwright/test'))) {
    Invoke-Npm @('install', '--no-package-lock', '--ignore-scripts')
}
Invoke-Npm @('run', 'test:e2e:install')

switch ($Mode) {
    'ReadOnly' { Invoke-Npm @('run', 'test:e2e') }
    'Practical' { Invoke-Npm @('run', 'test:e2e:practical') }
    'Manual' { Invoke-Npm @('run', 'test:e2e:manual') }
}

Write-Host ''
Write-Host 'Suíte concluída. Revise playwright-report e, quando liberados para base fictícia, test-results e as capturas em docs/assets/manual antes de versionar.' -ForegroundColor Green
