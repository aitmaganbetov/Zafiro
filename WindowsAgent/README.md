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
  `dotnet publish WindowsAgent/ZafiroAgent.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:EnableCompressionInSingleFile=true -o publish`

Результат — один `ZafiroAgent.exe` (около 75 МБ, .NET встроен). На ПК нужен WebView2 Runtime
(в Windows 10/11 обычно уже установлен).

## Интерфейс

Окно агента загружает `Frontend/index.html` из папки рядом с exe. Исходники интерфейса должны
лежать в `Frontend/` в корне репозитория: проект копирует их в сборку. Пока папки нет, сборка
проходит с предупреждением, а агент показывает «UI не найден».
