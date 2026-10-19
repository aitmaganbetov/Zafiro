/// Адрес сервера задаётся при сборке:
/// flutter run --dart-define=API_BASE_URL=https://club.example.com/api
const String apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://10.0.2.2/api',
);

/// Имя устройства для токена Sanctum (видно в журнале входов клуба).
const String deviceName = 'mobileclient';
