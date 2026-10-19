import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api/api_client.dart';
import 'api/models.dart';

/// Токен входа и профиль гостя. Токен хранится только в защищённом хранилище.
class Session extends ChangeNotifier {
  Session(this.api, {FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();

  static const _tokenKey = 'auth_token';
  static const _clubKey = 'club_name';

  final ApiClient api;
  final FlutterSecureStorage _storage;

  bool restoring = true;
  Profile? profile;
  String clubName = '';

  bool get signedIn => api.token != null;

  Future<void> restore() async {
    api.token = await _storage.read(key: _tokenKey);
    clubName = await _storage.read(key: _clubKey) ?? '';
    if (api.token != null) {
      try {
        profile = await api.me();
      } on ApiException catch (e) {
        if (e.isUnauthorized) await _forget();
      }
    }
    restoring = false;
    notifyListeners();
  }

  Future<void> login(String phone, String password) async {
    final result = await api.login(phone, password);
    api.token = result.token;
    profile = result.profile;
    clubName = result.clubName;
    await _storage.write(key: _tokenKey, value: result.token);
    await _storage.write(key: _clubKey, value: result.clubName);
    notifyListeners();
  }

  Future<void> refreshProfile() async {
    try {
      profile = await api.me();
      notifyListeners();
    } on ApiException catch (e) {
      if (!e.isUnauthorized) rethrow;
      await _forget();
      notifyListeners();
    }
  }

  Future<void> logout() async {
    try {
      await api.logout();
    } on ApiException {
      // Токен уже недействителен или нет сети — локально выходим всё равно.
    }
    await _forget();
    notifyListeners();
  }

  Future<void> _forget() async {
    api.token = null;
    profile = null;
    await _storage.delete(key: _tokenKey);
  }
}
