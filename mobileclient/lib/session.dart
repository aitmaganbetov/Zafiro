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
  static const _clubIdKey = 'club_id';
  static const _cityKey = 'city';

  final ApiClient api;
  final FlutterSecureStorage _storage;

  bool restoring = true;
  Profile? profile;
  String clubName = '';
  int? clubId;

  /// Город списка клубов, выбранный или найденный по геолокации.
  String? city;

  /// Клубы аккаунта: в списке клубов города они отмечены.
  List<ClubInfo> clubs = const [];

  /// Приложение открывается списком клубов города; гость входит в клуб из него.
  bool inClub = false;

  /// Меняется при смене клуба: экраны с данными клуба создаются заново.
  int clubVersion = 0;

  bool get signedIn => api.token != null;

  Future<void> restore() async {
    api.token = await _storage.read(key: _tokenKey);
    clubName = await _storage.read(key: _clubKey) ?? '';
    clubId = int.tryParse(await _storage.read(key: _clubIdKey) ?? '');
    city = await _storage.read(key: _cityKey);
    if (api.token != null) {
      try {
        profile = await api.me();
        clubs = await api.clubs();
        for (final club in clubs) {
          if (club.current) clubId = club.id;
        }
      } on ApiException catch (e) {
        if (e.isUnauthorized) await _forget();
      }
    }
    restoring = false;
    notifyListeners();
  }

  bool isMember(int id) => clubs.any((club) => club.id == id);

  /// Вход в клуб из списка (гость без аккаунта в клубе становится его клиентом).
  Future<void> login(String phone, String password, int clubId) async {
    await _apply(await api.login(phone, password, clubId));
    clubVersion++;
    inClub = true;
    notifyListeners();
  }

  /// Уже вошедший гость открывает клуб из списка: свой — сразу, другой — сменой клуба.
  Future<void> enterClub(int id) async {
    if (id != clubId) await _switch(id);
    inClub = true;
    notifyListeners();
  }

  /// Назад к списку клубов города.
  void leaveClub() {
    inClub = false;
    notifyListeners();
  }

  Future<void> setCity(String key) async {
    city = key;
    await _storage.write(key: _cityKey, value: key);
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
    clubId = result.clubId;
    clubs = result.clubs;
    await _storage.write(key: _tokenKey, value: result.token);
    await _storage.write(key: _clubKey, value: result.clubName);
    await _storage.write(key: _clubIdKey, value: '${result.clubId}');
  }

  Future<void> _forget() async {
    api.token = null;
    profile = null;
    clubs = const [];
    clubId = null;
    inClub = false;
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _clubIdKey);
  }
}
