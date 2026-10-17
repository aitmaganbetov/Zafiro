; Установщик Zafiro Agent для ПК клуба (Inno Setup 6). Собирается в CI:
;   ISCC.exe /DAppVersion=1.2.3 /DSourceDir=<папка полной сборки> ZafiroAgent.iss
; Ставит агент в Program Files и службу Windows «Zafiro Agent» (SYSTEM): служба хранит
; токен ПК и перезапускает агент в учётной записи клиента, если его закрыли.
; Учётные записи Windows установщик не создаёт (на супер-рабочей станции бездисковой системы
; они попали бы в образ всех ПК): защита применяется к существующей учётной записи клиента —
; ZafiroAgentSetup.exe /CLIENTUSER=Имя (по умолчанию Client).

#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif
#ifndef SourceDir
  #define SourceDir "..\..\publish\full"
#endif

[Setup]
AppId={{6F0B8C1E-3D2A-4E57-9B61-2C8A4F7D1E30}
AppName=Zafiro Agent
AppVersion={#AppVersion}
AppPublisher=Zafiro
AppPublisherURL=https://zafiro.kz
DefaultDirName={autopf}\Zafiro Agent
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir=.
OutputBaseFilename=ZafiroAgentSetup-{#AppVersion}
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
UninstallDisplayIcon={app}\ZafiroAgent.exe
SetupIconFile=..\ZafiroAgent\Assets\zafiro.ico
UninstallDisplayName=Zafiro Agent
CloseApplications=no
SetupLogging=yes

[Languages]
Name: "ru"; MessagesFile: "compiler:Languages\Russian.isl"

[Tasks]
Name: "protect"; Description: "Защитить существующую учётную запись клиента ({param:CLIENTUSER|Client}): без прав администратора, без диспетчера задач, реестра, командной строки и PowerShell. Новая учётная запись не создаётся"

Name: "desktopicon"; Description: "Значок Zafiro Agent на рабочем столе"
Name: "adminautostart"; Description: "Запускать агент при входе в Windows и под администратором (если ПК клуба входит в Windows под учётной записью администратора). Выйти для обслуживания — Ctrl+Alt+P и PIN-код"; Flags: unchecked

[Files]
Source: "{#SourceDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{commondesktop}\Zafiro Agent"; Filename: "{app}\ZafiroAgent.exe"; WorkingDir: "{app}"; Tasks: desktopicon
Name: "{autoprograms}\Zafiro Agent"; Filename: "{app}\ZafiroAgent.exe"; WorkingDir: "{app}"

[Registry]
; Служба читает настройку при запуске; клиент без прав администратора её не изменит.
Root: HKLM64; Subkey: "SOFTWARE\Zafiro\Agent"; Flags: uninsdeletekey
Root: HKLM64; Subkey: "SOFTWARE\Zafiro\Agent"; ValueType: dword; ValueName: "LaunchForAdministrators"; ValueData: 1; Tasks: adminautostart
Root: HKLM64; Subkey: "SOFTWARE\Zafiro\Agent"; ValueType: none; ValueName: "LaunchForAdministrators"; Flags: deletevalue; Tasks: not adminautostart

[Run]
; Служба создаётся при первой установке; при обновлении — только перенастраивается и запускается снова.
Filename: "{sys}\sc.exe"; Parameters: "create ZafiroAgent binPath= ""\""{app}\ZafiroAgent.exe\"" --service"" start= auto DisplayName= ""Zafiro Agent"""; Flags: runhidden; StatusMsg: "Регистрация службы Zafiro Agent..."
Filename: "{sys}\sc.exe"; Parameters: "config ZafiroAgent binPath= ""\""{app}\ZafiroAgent.exe\"" --service"" start= auto"; Flags: runhidden
Filename: "{sys}\sc.exe"; Parameters: "description ZafiroAgent ""Хранит токен ПК клуба и перезапускает Zafiro Agent, если его закрыли."""; Flags: runhidden
Filename: "{sys}\sc.exe"; Parameters: "failure ZafiroAgent reset= 86400 actions= restart/5000/restart/5000/restart/5000"; Flags: runhidden
Filename: "{sys}\sc.exe"; Parameters: "start ZafiroAgent"; Flags: runhidden; StatusMsg: "Запуск службы Zafiro Agent..."
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\Protect-ClubPc.ps1"" -ClientUser ""{param:CLIENTUSER|Client}"""; Tasks: protect; Flags: waituntilterminated; StatusMsg: "Защита учётной записи клиента..."
; Агент сразу после установки — в учётной записи, из которой запустили установщик.
Filename: "{app}\ZafiroAgent.exe"; Description: "Запустить Zafiro Agent"; Flags: postinstall nowait skipifsilent runasoriginaluser

[UninstallRun]
; net stop ждёт, пока служба остановится (sc stop возвращается сразу).
Filename: "{sys}\net.exe"; Parameters: "stop ZafiroAgent"; Flags: runhidden; RunOnceId: "StopService"
Filename: "{sys}\taskkill.exe"; Parameters: "/F /IM ZafiroAgent.exe"; Flags: runhidden; RunOnceId: "StopAgent"
Filename: "{sys}\sc.exe"; Parameters: "delete ZafiroAgent"; Flags: runhidden; RunOnceId: "DeleteService"

[UninstallDelete]
; Токен ПК службы: после удаления агента его нужно выдать заново.
Type: filesandordirs; Name: "{commonappdata}\ZafiroAgent"

[Code]
const
  UninstallKey = 'SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\{6F0B8C1E-3D2A-4E57-9B61-2C8A4F7D1E30}_is1';

var
  // Версия, которая уже стоит на ПК; пусто — первая установка.
  InstalledVersion: String;

function InitializeSetup(): Boolean;
begin
  InstalledVersion := '';
  RegQueryStringValue(HKLM, UninstallKey, 'DisplayVersion', InstalledVersion);
  Result := True;
end;

function IsUpdate(): Boolean;
begin
  Result := InstalledVersion <> '';
end;

// Задача уже выбрана или снята при прошлой установке (Inno Setup хранит списки в записи об установке).
function TaskDecided(const Name: String): Boolean;
var
  Selected, Deselected: String;
begin
  Selected := '';
  Deselected := '';
  RegQueryStringValue(HKLM, UninstallKey, 'Inno Setup: Selected Tasks', Selected);
  RegQueryStringValue(HKLM, UninstallKey, 'Inno Setup: Deselected Tasks', Deselected);
  Result := (Pos(',' + Name + ',', ',' + Lowercase(Selected) + ',') > 0)
    or (Pos(',' + Name + ',', ',' + Lowercase(Deselected) + ',') > 0);
end;

// Обновление: задачи берутся из прошлой установки (UsePreviousTasks), сразу страница с кнопкой «Обновить».
// Страница задач показывается, только если в новой версии появилась задача, которой раньше не было.
function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := IsUpdate() and (PageID = wpSelectTasks)
    and TaskDecided('protect') and TaskDecided('desktopicon') and TaskDecided('adminautostart');
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if (CurPageID <> wpReady) or not IsUpdate() then Exit;
  if InstalledVersion = '{#AppVersion}' then
  begin
    WizardForm.PageNameLabel.Caption := 'Переустановка Zafiro Agent';
    WizardForm.PageDescriptionLabel.Caption := 'Эта версия уже установлена.';
    WizardForm.ReadyLabel.Caption := 'Установлена версия {#AppVersion}. Нажмите «Переустановить», чтобы заменить файлы агента. Ключ клуба и токен ПК сохранятся.';
    WizardForm.NextButton.Caption := 'Переустановить';
  end
  else
  begin
    WizardForm.PageNameLabel.Caption := 'Обновление Zafiro Agent';
    WizardForm.PageDescriptionLabel.Caption := 'Агент уже установлен на этом ПК.';
    WizardForm.ReadyLabel.Caption := 'Установлена версия ' + InstalledVersion + ', будет установлена {#AppVersion}. Нажмите «Обновить». Ключ клуба и токен ПК сохранятся, служба запустится снова.';
    WizardForm.NextButton.Caption := 'Обновить';
  end;
end;

// Перед обновлением файлов останавливаем службу (иначе она сразу перезапустит агент) и сам агент.
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
begin
  // net stop ждёт остановки службы: иначе taskkill ниже мог бы оборвать её на полпути.
  Exec(ExpandConstant('{sys}\net.exe'), 'stop ZafiroAgent', '', SW_HIDE, ewWaitUntilTerminated, Code);
  Exec(ExpandConstant('{sys}\taskkill.exe'), '/F /IM ZafiroAgent.exe', '', SW_HIDE, ewWaitUntilTerminated, Code);
  Sleep(500);
  Result := '';
end;
