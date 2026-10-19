# Zafiro Staff — приложение для сотрудников клуба (mobileemployee)

Flutter, пока только Android. Для владельца, администратора, менеджера, кассира и оператора клуба.
Работает с API сотрудника сайта Zafiro (`/api/staff/*`, контракт — `docs/staff-api.md`).
Оформление — как у панели клуба на сайте: светлая и тёмная тема (по настройке телефона),
розовый цвет бренда, разделы и иконки как в сайдбаре.

## Что есть
- Вход по номеру (+7) и паролю, «Забыли пароль?» с кодом из Telegram, выбор клуба,
  если аккаунт — сотрудник нескольких клубов. Токен — в защищённом хранилище;
  истёк (401) — снова экран входа.
- Разделы показываются по правам из `GET /staff/me` (`permissions`):
  - **Карта клуба** — ПК по зонам и статусам, обновление каждые 10 с; бронь, включение,
    перезагрузка, выключение (`control`), завершение сессии (`end_session`),
    гостевая сессия (`guest_session`). Удержание ПК — выбор нескольких.
  - **Касса** (`cash`) — открытие и закрытие смены с Z-отчётом, X-отчёт, последние операции,
    пополнение баланса, продажа товаров, приход и расход.
  - **Пользователи** — поиск клиентов, баланс, бонусы, скидка, пополнение.
  - **Заказы** с ПК — «Выдан» / «Отменить».
  - **Чат** с клиентами — ответ только у кассиров и операторов (`chat_reply`).
  - **Уведомления** — колокольчик в шапке.
  - **Склад** — остатки, приход (`stock_receive`), приход и списание (`stock_manage`).
  - **Отчёт за день** (`reports`) — доход, расход, 7 дней, ПК, низкий остаток.

## Запуск
```bash
export PATH=$HOME/flutter/bin:$PATH
flutter pub get
flutter run --dart-define=API_BASE_URL=https://<домен-сайта>/api
```
Без `API_BASE_URL` используется `https://zafiro.kz/api`.

## Проверки
```bash
flutter analyze
flutter test
```

## Сборка APK
Сборка идёт в GitHub Actions: `.github/workflows/mobile-employee.yml` — при каждом push в `main`
и pull request, которые меняют `mobileemployee/`, и вручную (вкладка Actions → «Mobile staff
(Android)» → Run workflow). Адрес сервера — переменная репозитория `STAFF_API_BASE_URL`
(Settings → Variables), по умолчанию `https://zafiro.kz/api`.

Готовый APK — в артефакте `zafiro-staff-android-<номер запуска>` на странице запуска
(файл `zafiro-staff-<номер запуска>.apk`, хранится 30 дней). APK подписан отладочным ключом:
годится для установки на телефоны сотрудников, но не для Google Play.

Локально (нужны Android SDK и JDK 17):
`flutter build apk --release --dart-define=API_BASE_URL=https://<домен>/api` →
`build/app/outputs/flutter-apk/app-release.apk`.

## Иконка и экран запуска
Из логотипа сайта (`public/images/logo.svg`):
`dart run flutter_launcher_icons` и `dart run flutter_native_splash:create`.
