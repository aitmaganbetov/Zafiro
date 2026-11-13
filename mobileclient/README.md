# Zafiro — мобильное приложение для гостей клуба (mobileclient)

Flutter (Android, iOS). Работает с API сайта Zafiro (`/api`, токен Sanctum).

## Что есть
- Первый экран — клубы города (`GET /api/catalog/clubs`): город по геолокации (ближайший из списка)
  или выбранный вручную. Клуб у себя задаёт город в «Настройки → Клуб → Город и адрес».
- Вход в клуб из списка по телефону и паролю (`POST /api/auth/login` с `club_id`): если гость ещё
  не клиент клуба, он им становится. Токен — в защищённом хранилище.
- Баланс, бонусы и скидка (`GET /api/auth/me`).
- Новости клуба и платформы (`GET /api/guest/news`).
- История операций с подгрузкой (`GET /api/guest/history?before=`).
- Профиль и выход (`POST /api/auth/logout`).

## Запуск
```bash
export PATH=$HOME/flutter/bin:$PATH
flutter pub get
flutter run --dart-define=API_BASE_URL=https://<домен-сайта>/api
```
Без `API_BASE_URL` используется `http://10.0.2.2/api` (localhost хоста из Android-эмулятора).

## Проверки
```bash
flutter analyze
flutter test
```

## Сборка
Для APK нужен Android SDK и JDK (на сервере их нет — собирать на машине разработчика или в CI):
`flutter build apk --dart-define=API_BASE_URL=https://<домен>/api`. Для iOS — macOS с Xcode.
