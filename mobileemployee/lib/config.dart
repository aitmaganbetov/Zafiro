// Адрес сервера задаётся при сборке:
// flutter build apk --dart-define=API_BASE_URL=https://zafiro.kz/api
const String apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'https://zafiro.kz/api',
);

/// Имя устройства для токена (видно в журнале входов клуба).
const String deviceName = 'Zafiro Staff';
