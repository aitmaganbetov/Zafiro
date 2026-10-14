; Установщик Zafiro Agent для ПК клуба (Inno Setup 6). Собирается в CI:
;   ISCC.exe /DAppVersion=1.2.3 /DSourceDir=<папка полной сборки> ZafiroAgent.iss
; Ставит агент в Program Files и службу Windows «Zafiro Agent» (SYSTEM): служба хранит
; токен ПК и перезапускает агент в учётной записи клиента, если его закрыли.

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
UninstallDisplayName=Zafiro Agent
CloseApplications=no
SetupLogging=yes

[Languages]
Name: "ru"; MessagesFile: "compiler:Languages\Russian.isl"

[Tasks]
Name: "protect"; Description: "Защитить учётную запись клиента: без прав администратора, без диспетчера задач, реестра, командной строки и PowerShell"

[Files]
Source: "{#SourceDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Run]
; Служба создаётся при первой установке; при обновлении — только перенастраивается и запускается снова.
Filename: "{sys}\sc.exe"; Parameters: "create ZafiroAgent binPath= ""\""{app}\ZafiroAgent.exe\"" --service"" start= auto DisplayName= ""Zafiro Agent"""; Flags: runhidden; StatusMsg: "Регистрация службы Zafiro Agent..."
Filename: "{sys}\sc.exe"; Parameters: "config ZafiroAgent binPath= ""\""{app}\ZafiroAgent.exe\"" --service"" start= auto"; Flags: runhidden
Filename: "{sys}\sc.exe"; Parameters: "description ZafiroAgent ""Хранит токен ПК клуба и перезапускает Zafiro Agent, если его закрыли."""; Flags: runhidden
Filename: "{sys}\sc.exe"; Parameters: "failure ZafiroAgent reset= 86400 actions= restart/5000/restart/5000/restart/5000"; Flags: runhidden
Filename: "{sys}\sc.exe"; Parameters: "start ZafiroAgent"; Flags: runhidden; StatusMsg: "Запуск службы Zafiro Agent..."
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\Protect-ClubPc.ps1"""; Tasks: protect; Flags: waituntilterminated; StatusMsg: "Защита учётной записи клиента..."

[UninstallRun]
Filename: "{sys}\sc.exe"; Parameters: "stop ZafiroAgent"; Flags: runhidden; RunOnceId: "StopService"
Filename: "{sys}\taskkill.exe"; Parameters: "/F /IM ZafiroAgent.exe"; Flags: runhidden; RunOnceId: "StopAgent"
Filename: "{sys}\sc.exe"; Parameters: "delete ZafiroAgent"; Flags: runhidden; RunOnceId: "DeleteService"

[UninstallDelete]
; Токен ПК службы: после удаления агента его нужно выдать заново.
Type: filesandordirs; Name: "{commonappdata}\ZafiroAgent"

[Code]
// Перед обновлением файлов останавливаем службу (иначе она сразу перезапустит агент) и сам агент.
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
begin
  Exec(ExpandConstant('{sys}\sc.exe'), 'stop ZafiroAgent', '', SW_HIDE, ewWaitUntilTerminated, Code);
  Sleep(1500);
  Exec(ExpandConstant('{sys}\taskkill.exe'), '/F /IM ZafiroAgent.exe', '', SW_HIDE, ewWaitUntilTerminated, Code);
  Sleep(500);
  Result := '';
end;
