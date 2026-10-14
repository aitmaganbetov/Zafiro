<#
.SYNOPSIS
    Защищает ПК клуба для Zafiro Agent: клиент работает под ограниченной учётной записью
    и не может отключить агент, редактор реестра, командную строку или PowerShell.

.DESCRIPTION
    Запускать от имени администратора, один раз на каждом ПК (и повторно после обновления агента).
    1. Копирует агент в "C:\Program Files\Zafiro Agent": обычный пользователь не может подменить exe.
    2. Создаёт ограниченную учётную запись клиента (или проверяет существующую): без прав администратора.
    3. Для этой учётной записи запрещает: диспетчер задач, редактор реестра, командную строку и
       скрипты, PowerShell, окно «Выполнить», панель управления, MMC, msconfig, mshta, wscript/cscript.
    4. Регистрирует службу Windows «Zafiro Agent» (SYSTEM): она хранит токен ПК в папке, недоступной
       клиенту, и запускает агент снова, если клиент его закрыл. Учётные записи администраторов служба
       не трогает.
    С ключом -Undo снимает все запреты и удаляет службу (файлы агента и учётная запись остаются).

    Обычно скрипт запускает установщик ZafiroAgentSetup (галочка «Защитить учётную запись клиента»).
    Windows применяет запреты к программам, запущенным из проводника; агент защищает служба.

.EXAMPLE
    .\Protect-ClubPc.ps1 -AgentPath D:\ZafiroAgent-win-x64-1.0.0
.EXAMPLE
    .\Protect-ClubPc.ps1 -ClientUser Client -Undo
#>
[CmdletBinding()]
param(
    # Папка с распакованным архивом агента (ZafiroAgent.exe и папка Frontend).
    [string] $AgentPath,
    # Учётная запись Windows, под которой играют клиенты.
    [string] $ClientUser = 'Client',
    [switch] $Undo
)

$ErrorActionPreference = 'Stop'
$InstallDir = Join-Path $env:ProgramFiles 'Zafiro Agent'
$AgentExe = Join-Path $InstallDir 'ZafiroAgent.exe'
$ServiceName = 'ZafiroAgent'
$BlockedPrograms = @(
    'cmd.exe', 'powershell.exe', 'powershell_ise.exe', 'pwsh.exe', 'regedit.exe', 'regedt32.exe',
    'taskmgr.exe', 'mmc.exe', 'msconfig.exe', 'mshta.exe', 'wscript.exe', 'cscript.exe', 'control.exe'
)

function Assert-Administrator {
    $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Запустите скрипт от имени администратора (правой кнопкой → «Запуск от имени администратора»).'
    }
}

function Install-Agent {
    if (-not $AgentPath) { return }
    $source = Join-Path $AgentPath 'ZafiroAgent.exe'
    if (-not (Test-Path $source) -or -not (Test-Path (Join-Path $AgentPath 'Frontend\index.html'))) {
        throw "В папке $AgentPath нет ZafiroAgent.exe и Frontend\index.html. Укажите папку распакованного архива агента."
    }

    Stop-Service $ServiceName -ErrorAction SilentlyContinue # иначе служба сразу запустит старый агент
    Get-Process ZafiroAgent -ErrorAction SilentlyContinue | Stop-Process -Force
    New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
    Copy-Item -Path (Join-Path $AgentPath '*') -Destination $InstallDir -Recurse -Force
    Write-Host "Агент установлен: $AgentExe"
}

function Install-AgentService {
    if (-not (Test-Path $AgentExe)) {
        Write-Warning "Агент не найден в ${InstallDir}: укажите -AgentPath, чтобы установить его и службу."
        return
    }

    if (-not (Get-Service $ServiceName -ErrorAction SilentlyContinue)) {
        New-Service -Name $ServiceName -BinaryPathName "`"$AgentExe`" --service" -DisplayName 'Zafiro Agent' -StartupType Automatic `
            -Description 'Хранит токен ПК клуба и перезапускает Zafiro Agent, если его закрыли.' | Out-Null
        & sc.exe failure $ServiceName reset= 86400 actions= restart/5000/restart/5000/restart/5000 | Out-Null
        Write-Host 'Служба Zafiro Agent установлена.'
    }

    Start-Service $ServiceName
}

function Remove-AgentService {
    if (-not (Get-Service $ServiceName -ErrorAction SilentlyContinue)) { return }
    Stop-Service $ServiceName -Force -ErrorAction SilentlyContinue
    & sc.exe delete $ServiceName | Out-Null
    Write-Host 'Служба Zafiro Agent удалена.'
}

function Ensure-ClientUser {
    $user = Get-LocalUser -Name $ClientUser -ErrorAction SilentlyContinue
    if (-not $user) {
        $password = Read-Host "Пароль для новой учётной записи $ClientUser" -AsSecureString
        New-LocalUser -Name $ClientUser -Password $password -PasswordNeverExpires -UserMayNotChangePassword -Description 'Клиенты клуба (Zafiro)' | Out-Null
        Add-LocalGroupMember -Group (Get-LocalGroup -SID 'S-1-5-32-545') -Member $ClientUser
        Write-Host "Создана учётная запись $ClientUser. Войдите в неё один раз и снова запустите скрипт." -ForegroundColor Yellow
        return $false
    }

    $admins = Get-LocalGroup -SID 'S-1-5-32-544'
    if (Get-LocalGroupMember -Group $admins | Where-Object { $_.Name -match "\\$([regex]::Escape($ClientUser))$" }) {
        Remove-LocalGroupMember -Group $admins -Member $ClientUser
        Write-Host "$ClientUser убран из администраторов."
    }

    return $true
}

# Реестр учётной записи клиента: уже загружен, если клиент вошёл в Windows, иначе подгружается из NTUSER.DAT.
function Open-ClientHive {
    $sid = (Get-LocalUser -Name $ClientUser).SID.Value
    if (Test-Path "Registry::HKEY_USERS\$sid") { return @{ Root = "Registry::HKEY_USERS\$sid"; Loaded = $false } }

    $profilePath = (Get-ItemProperty "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid" -ErrorAction SilentlyContinue).ProfileImagePath
    if (-not $profilePath -or -not (Test-Path "$profilePath\NTUSER.DAT")) {
        throw "Профиль $ClientUser ещё не создан. Войдите в эту учётную запись один раз и запустите скрипт снова."
    }

    & reg.exe load "HKU\ZafiroClient" "$profilePath\NTUSER.DAT" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Не удалось открыть реестр учётной записи $ClientUser." }
    return @{ Root = 'Registry::HKEY_USERS\ZafiroClient'; Loaded = $true }
}

function Close-ClientHive($hive) {
    if (-not $hive.Loaded) { return }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
    & reg.exe unload 'HKU\ZafiroClient' | Out-Null
}

function Set-Value($root, $path, $name, $value, $type = 'DWord') {
    $key = Join-Path $root $path
    New-Item -Path $key -Force | Out-Null
    New-ItemProperty -Path $key -Name $name -Value $value -PropertyType $type -Force | Out-Null
}

function Remove-Value($root, $path, $name) {
    $key = Join-Path $root $path
    if (Test-Path $key) { Remove-ItemProperty -Path $key -Name $name -ErrorAction SilentlyContinue }
}

function Protect-Client($root) {
    $system = 'Software\Microsoft\Windows\CurrentVersion\Policies\System'
    $explorer = 'Software\Microsoft\Windows\CurrentVersion\Policies\Explorer'

    Set-Value $root $system 'DisableTaskMgr' 1
    Set-Value $root $system 'DisableRegistryTools' 1
    Set-Value $root 'Software\Policies\Microsoft\Windows\System' 'DisableCMD' 1
    Set-Value $root $explorer 'NoRun' 1
    Set-Value $root $explorer 'NoControlPanel' 1
    Set-Value $root $explorer 'DisallowRun' 1

    $list = Join-Path $root "$explorer\DisallowRun"
    if (Test-Path $list) { Remove-Item $list -Recurse -Force }
    $index = 1
    foreach ($program in $BlockedPrograms) {
        Set-Value $root "$explorer\DisallowRun" "$index" $program 'String'
        $index++
    }

    # Агент запускает служба; своя запись автозапуска дала бы второй запуск.
    Remove-Value $root 'Software\Microsoft\Windows\CurrentVersion\Run' 'ZafiroAgent'
}

function Unprotect-Client($root) {
    $system = 'Software\Microsoft\Windows\CurrentVersion\Policies\System'
    $explorer = 'Software\Microsoft\Windows\CurrentVersion\Policies\Explorer'

    Remove-Value $root $system 'DisableTaskMgr'
    Remove-Value $root $system 'DisableRegistryTools'
    Remove-Value $root 'Software\Policies\Microsoft\Windows\System' 'DisableCMD'
    Remove-Value $root $explorer 'NoRun'
    Remove-Value $root $explorer 'NoControlPanel'
    Remove-Value $root $explorer 'DisallowRun'
    $list = Join-Path $root "$explorer\DisallowRun"
    if (Test-Path $list) { Remove-Item $list -Recurse -Force }
    Remove-Value $root 'Software\Microsoft\Windows\CurrentVersion\Run' 'ZafiroAgent'
}

Assert-Administrator
if ($Undo) {
    Remove-AgentService
}
else {
    Install-Agent
    Install-AgentService
    if (-not (Ensure-ClientUser)) { return }
}

$hive = Open-ClientHive
try {
    if ($Undo) {
        Unprotect-Client $hive.Root
        Write-Host "Запреты для $ClientUser сняты, служба агента удалена." -ForegroundColor Green
    }
    else {
        Protect-Client $hive.Root
        Write-Host "Готово: учётная запись $ClientUser защищена, служба запускает агент при входе." -ForegroundColor Green
        Write-Host 'Запреты вступят в силу при следующем входе клиента в Windows.'
    }
}
finally {
    Close-ClientHive $hive
}
