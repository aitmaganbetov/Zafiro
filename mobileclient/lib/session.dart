import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api/api_client.dart';
import 'api/models.dart';

/// Токен входа, клуб и профиль гостя. Токен хранится только в защищённом хранилище.
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

  /// Клубы аккаунта; больше одного — в профиле можно сменить клуб.
  List<ClubInfo> clubs = const [];

  /// Сразу после входа гость с несколькими клубами выбирает клуб.
  bool choosingClub = false;

  /// Меняется при смене клуба: экраны с данными клуба создаются заново.
  int clubVersion = 0;

  bool get signedIn => api.token != null;

  Future<void> restore() async {
    api.token = await _storage.read(key: _tokenKey);
    clubName = await _storage.read(key: _clubKey) ?? '';
    if (api.token != null) {
      try {
        profile = await api.me();
        clubs = await api.clubs();
      } on ApiException catch (e) {
        if (e.isUnauthorized) await _forget();
      }
    }
    restoring = false;
    notifyListeners();
  }

  Future<void> login(String phone, String password) async {
    final result = await api.login(phone, password);
    await _apply(result);
    choosingClub = result.clubs.length > 1;
    notifyListeners();
  }

  /// Выбор клуба после входа: тот же клуб — просто дальше.
  Future<void> chooseClub(ClubInfo club) async {
    if (!club.current) await _switch(club.id);
    choosingClub = false;
    notifyListeners();
  }

  /// Смена клуба из профиля.
  Future<void> switchClub(ClubInfo club) async {
    if (club.current) return;
    await _switch(club.id);
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

  Future<void> _switch(int clubId) async {
    await _apply(await api.switchClub(clubId));
    clubVersion++;
  }

  Future<void> _apply(LoginResult result) async {
    api.token = result.token;
    profile = result.profile;
    clubName = result.clubName;
    clubs = result.clubs;
    await _storage.write(key: _tokenKey, value: result.token);
    await _storage.write(key: _clubKey, value: result.clubName);
  }

  Future<void> _forget() async {
    api.token = null;
    profile = null;
    clubs = const [];
    choosingClub = false;
    await _storage.delete(key: _tokenKey);
  }
}
