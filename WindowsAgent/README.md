# Zafiro Agent (Windows)

Программа для ПК клуба: вход клиента, сессии, heartbeat и управление ПК через API zafiro.kz.
WPF + WebView2, .NET 10 (`net10.0-windows`).

## Сборка

- **GitHub Actions** (`.github/workflows/windows-agent.yml`): при каждом изменении `WindowsAgent/`
  в `main` или в pull request собирается `ZafiroAgent-win-x64-<версия>.zip` — его можно скачать
  на странице запуска (Artifacts, хранится 30 дней). Запуск вручную — вкладка Actions → Windows agent → Run workflow.
- **Релиз**: `git tag agent-v1.0.0 && git push origin agent-v1.0.0` — создаётся GitHub Release с zip
  и файлом `.sha256`.
- **Локально** (Windows, .NET 10 SDK):
  `dotnet publish WindowsAgent/ZafiroAgent/ZafiroAgent.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:EnableCompressionInSingleFile=true -o publish`

Результат — один `ZafiroAgent.exe` (около 75 МБ, .NET встроен). На ПК нужен WebView2 Runtime
(в Windows 10/11 обычно уже установлен).

## Структура

- `ZafiroAgent/` — приложение (WPF + WebView2).
- `Frontend/` — интерфейс окна (HTML/JS/CSS); при сборке копируется в `Frontend/` рядом с exe.
- `ZafiroAgent.Tests/` — тесты (xUnit), запускаются в GitHub Actions перед публикацией.
- `ZafiroAgent.slnx` — решение для Visual Studio.

Настройки агента на ПК хранятся в `%APPDATA%\ZafiroAgent\agent.config.json`, ключ клуба и токен —
зашифрованы DPAPI. В репозиторий не добавлять `bin/`, `obj/`, `*.user`, сертификаты и сохранённые
ответы сервера — они перечислены в `.gitignore`.
