# EventFlow — reparo WSL/Docker (rode no PowerShell COMO ADMINISTRADOR)
# Clique direito PowerShell -> Executar como administrador
# Depois:  Set-ExecutionPolicy Bypass -Scope Process -Force
#          cd "C:\Users\cleiv\OneDrive\Documentos\Nova pasta\EventFlow-Engine"
#          .\scripts\repair-wsl-docker.ps1

$ErrorActionPreference = 'Continue'
Write-Host "== EventFlow WSL/Docker repair ==" -ForegroundColor Cyan

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).
    IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host "ERRO: abra este script em PowerShell Administrador." -ForegroundColor Red
    exit 1
}

Write-Host "`n[1/6] Ativando features Windows..." -ForegroundColor Yellow
dism.exe /online /enable-feature /featurename:Microsoft-Windows-Subsystem-Linux /all /norestart
dism.exe /online /enable-feature /featurename:VirtualMachinePlatform /all /norestart

Write-Host "`n[2/6] Baixando WSL MSI oficial..." -ForegroundColor Yellow
$msi = Join-Path $env:TEMP 'wsl-setup.msi'
Remove-Item $msi -Force -ErrorAction SilentlyContinue

# Resolve real asset URL from GitHub API when possible
$urls = New-Object System.Collections.Generic.List[string]
try {
    $rel = Invoke-RestMethod -Uri 'https://api.github.com/repos/microsoft/WSL/releases/latest' -Headers @{ 'User-Agent' = 'EventFlow-Repair' }
    Write-Host "  release=$($rel.tag_name)"
    foreach ($a in $rel.assets) {
        if ($a.name -match '(?i)x64.*\.msi$|wsl\..*\.x64\.msi$') {
            $urls.Add([string]$a.browser_download_url)
        }
    }
} catch {
    Write-Host "  GitHub API falhou: $($_.Exception.Message)" -ForegroundColor DarkYellow
}
$urls.Add('https://aka.ms/wslstoremsi')
$urls.Add('https://github.com/microsoft/WSL/releases/latest/download/wsl.2.5.7.0.x64.msi')

$ok = $false
foreach ($url in $urls) {
    try {
        Write-Host "  tentando $url"
        # curl is more resilient than Invoke-WebRequest on some networks
        & curl.exe -L --fail --retry 5 --retry-all-errors --connect-timeout 30 -o $msi $url
        if ((Test-Path $msi) -and ((Get-Item $msi).Length -gt 1MB)) {
            $ok = $true
            Write-Host "  OK size=$((Get-Item $msi).Length)" -ForegroundColor Green
            break
        }
    } catch {
        Write-Host "  falhou: $($_.Exception.Message)" -ForegroundColor DarkYellow
    }
}

if (-not $ok) {
    Write-Host @"

ERRO: download automatico falhou (rede).

FACO MANUAL (1 minuto):
1) No browser abra: https://github.com/microsoft/WSL/releases/latest
2) Baixe o arquivo .msi x64 (ex.: wsl.x.x.x.x64.msi)
3) Rode no PowerShell Admin:

   msiexec /i "`$HOME\Downloads\NOME_DO_ARQUIVO.msi"

4) Reinicie o PC
5) Abra Docker Desktop

OU Microsoft Store -> procure "Windows Subsystem for Linux" -> Instalar/Atualizar

"@ -ForegroundColor Red
    exit 1
}

Write-Host "`n[3/6] Instalando/reparando WSL MSI..." -ForegroundColor Yellow
Start-Process msiexec.exe -ArgumentList "/i `"$msi`" /qn /norestart" -Wait -Verb RunAs

Write-Host "`n[4/6] winget Microsoft.WSL (se existir)..." -ForegroundColor Yellow
winget install --id Microsoft.WSL -e --accept-package-agreements --accept-source-agreements --force 2>&1

Write-Host "`n[5/6] wsl --update / set v2..." -ForegroundColor Yellow
wsl --update 2>&1
wsl --set-default-version 2 2>&1
wsl --status 2>&1

Write-Host "`n[6/6] Status Docker..." -ForegroundColor Yellow
Get-Process 'Docker Desktop' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2
$dockerExe = "$env:LOCALAPPDATA\Programs\DockerDesktop\Docker Desktop.exe"
if (Test-Path $dockerExe) {
    Start-Process $dockerExe
    Write-Host "Docker Desktop iniciado. Espere o engine ficar verde." -ForegroundColor Green
} else {
    Write-Host "Docker Desktop.exe nao encontrado em $dockerExe" -ForegroundColor Yellow
}

Write-Host @"

=== PROXIMO PASSO OBRIGATORIO ===
1) REINICIE o PC agora.
2) Abra Docker Desktop e espere 'Engine running'.
3) No projeto: docker compose up -d

Se wsl --status ainda der REGDB_E_CLASSNOTREG:
- Windows Update (instalar tudo + reiniciar)
- Microsoft Store -> 'Windows Subsystem for Linux' -> Instalar
- Ou reparo do Windows: Settings -> System -> Recovery -> Fix problems using Windows Update

"@ -ForegroundColor Cyan
