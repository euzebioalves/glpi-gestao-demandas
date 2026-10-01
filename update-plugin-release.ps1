[CmdletBinding(SupportsShouldProcess)]
param(
    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string]$PluginDirectory,

    [ValidatePattern('^(latest|v[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?)$')]
    [string]$Tag = 'latest',

    # Baixa e valida o ZIP, mas não substitui a pasta do plugin.
    [switch]$ValidatePackage,

    # Requer janela de manutenção; só então substitui a pasta do plugin.
    [switch]$Apply
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repositoryApi = 'https://api.github.com/repos/euzebioalves/glpi-gestao-demandas/releases'
$allowedDownloadHosts = @(
    'github.com',
    'objects.githubusercontent.com',
    'github-releases.githubusercontent.com',
    'release-assets.githubusercontent.com'
)

function Get-OfficialRelease {
    $uri = if ($Tag -eq 'latest') { "$repositoryApi/latest" } else { "$repositoryApi/tags/$Tag" }
    $release = Invoke-RestMethod -Method Get -Uri $uri -Headers @{
        Accept = 'application/vnd.github+json'
        'User-Agent' = 'GLPI-Demandas-controlled-updater'
    }
    if ($release.draft -or $release.prerelease) {
        throw 'A release solicitada não está publicada para atualização.'
    }
    $version = ([string]$release.tag_name) -replace '^v', ''
    if ($version -notmatch '^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$') {
        throw 'A release oficial não informou uma versão válida.'
    }
    $asset = @($release.assets | Where-Object {
        $_.name -match ('^demandas-' + [regex]::Escape($version) + '(?:[-.].+)?\.zip$')
    } | Select-Object -First 1)
    if ($asset.Count -ne 1) {
        throw "A release v$version não contém um instalador ZIP compatível do plugin."
    }
    $downloadUri = [uri][string]$asset[0].browser_download_url
    if ($downloadUri.Scheme -ne 'https' -or $allowedDownloadHosts -notcontains $downloadUri.Host.ToLowerInvariant()) {
        throw 'O arquivo publicado não está em um domínio GitHub permitido.'
    }
    return [pscustomobject]@{
        Version = $version
        Tag = [string]$release.tag_name
        ReleaseUrl = [string]$release.html_url
        AssetName = [string]$asset[0].name
        AssetUrl = $downloadUri.AbsoluteUri
    }
}

$pluginPath = (Resolve-Path -LiteralPath $PluginDirectory).Path
$pluginItem = Get-Item -LiteralPath $pluginPath -Force
if (-not $pluginItem.PSIsContainer -or $pluginItem.Name -ne 'demandas' -or $pluginItem.LinkType) {
    throw 'PluginDirectory deve apontar para uma pasta real chamada "demandas", sem links simbólicos.'
}
$currentSetup = Join-Path $pluginPath 'setup.php'
if (-not (Test-Path -LiteralPath $currentSetup -PathType Leaf)) {
    throw 'A pasta informada não contém o setup.php do plugin.'
}

$release = Get-OfficialRelease
Write-Host "Release oficial encontrada: $($release.Tag) ($($release.AssetName))"
Write-Host "Release: $($release.ReleaseUrl)"
if (-not $Apply -and -not $ValidatePackage) {
    Write-Host 'Modo de consulta: nenhum arquivo foi alterado. Use -ValidatePackage para validar também o ZIP, ou -Apply durante a janela de manutenção.'
    return
}
if ($WhatIfPreference) {
    Write-Host 'WhatIf: o pacote não será baixado nem a pasta será substituída. Nenhum arquivo foi alterado.'
    return
}

$pluginParent = Split-Path -Parent $pluginPath
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupPath = Join-Path $pluginParent ("demandas-backup-$timestamp")
$temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) ("demandas-update-" + [guid]::NewGuid().ToString('N'))

try {
    New-Item -ItemType Directory -Path $temporaryRoot | Out-Null
    $zipPath = Join-Path $temporaryRoot $release.AssetName
    $stagingPath = Join-Path $temporaryRoot 'staging'
    Invoke-WebRequest -Uri $release.AssetUrl -OutFile $zipPath -Headers @{
        Accept = 'application/octet-stream'
        'User-Agent' = 'GLPI-Demandas-controlled-updater'
    }
    Expand-Archive -LiteralPath $zipPath -DestinationPath $stagingPath

    $stagedPlugin = Join-Path $stagingPath 'demandas'
    $stagedSetup = Join-Path $stagedPlugin 'setup.php'
    if (-not (Test-Path -LiteralPath $stagedSetup -PathType Leaf)) {
        throw 'O instalador não possui a estrutura esperada demandas/setup.php.'
    }
    $setupContent = Get-Content -LiteralPath $stagedSetup -Raw
    $versionMatch = [regex]::Match($setupContent, "define\('PLUGIN_DEMANDAS_VERSION',\s*'([^']+)'\)")
    if (-not $versionMatch.Success -or $versionMatch.Groups[1].Value -ne $release.Version) {
        throw 'A versão declarada no instalador não corresponde à release oficial.'
    }
    if (Test-Path -LiteralPath $backupPath) {
        throw 'Já existe um backup com o mesmo identificador. Tente novamente.'
    }

    if (-not $Apply) {
        Write-Host "Pacote v$($release.Version) validado. Nenhum arquivo foi alterado. Execute com -Apply somente durante a janela de manutenção."
        return
    }

    if ($PSCmdlet.ShouldProcess($pluginPath, "Substituir pelo pacote $($release.Tag) e preservar backup em $backupPath")) {
        # Pare o serviço web/contêiner do GLPI antes de executar com -Apply.
        # A troca preserva o pacote anterior para rollback e não toca no banco.
        Move-Item -LiteralPath $pluginPath -Destination $backupPath
        try {
            Move-Item -LiteralPath $stagedPlugin -Destination $pluginParent
        } catch {
            Move-Item -LiteralPath $backupPath -Destination $pluginParent
            throw
        }
        Write-Host "Plugin atualizado para v$($release.Version). Backup preservado em: $backupPath"
        Write-Host 'Inicie o GLPI e execute a atualização do plugin em Configuração > Plugins.'
    }
} finally {
    if (Test-Path -LiteralPath $temporaryRoot) {
        $resolvedTemporary = [IO.Path]::GetFullPath($temporaryRoot)
        $resolvedTempParent = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd([IO.Path]::DirectorySeparatorChar)
        if ((Split-Path -Parent $resolvedTemporary) -ne $resolvedTempParent -or (Split-Path -Leaf $resolvedTemporary) -notmatch '^demandas-update-[a-f0-9]{32}$') {
            throw 'Diretório temporário fora do escopo esperado; limpeza cancelada.'
        }
        Remove-Item -LiteralPath $resolvedTemporary -Recurse -Force
    }
}
