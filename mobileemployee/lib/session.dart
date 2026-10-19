import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api/api_client.dart';
import 'api/models.dart';

/// Токен сотрудника, клуб и права. Токен хранится только в защищённом хранилище.
class Session extends ChangeNotifier {
  Session(this.api, {FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage() {
    api.onUnauthorized = _expired;
  }

  static const _tokenKey = 'staff_token';
  static const _clubKey = 'staff_club_name';
  static const _clubsKey = 'staff_clubs';
  static const noStaffAccess = 'Нет доступа сотрудника';

  final ApiClient api;
  final FlutterSecureStorage _storage;

  bool restoring = true;

  /// Профиль, права и счётчики из GET /staff/me.
  StaffMe? me;

  /// Профиль не загрузился (нет сети и т. п.), а токен есть.
  String? loadError;

  String clubName = '';
  List<ClubInfo> clubs = const [];

  /// Меняется при смене клуба: экраны с данными клуба создаются заново.
  int clubVersion = 0;

  /// Сообщение для экрана входа (например, «сессия истекла»).
  String? signInNotice;

  bool get signedIn => api.token != null;

  /// Клубы, где аккаунт — сотрудник.
  List<ClubInfo> get staffClubs => clubs.where((c) => c.isStaff).toList();

  Permissions get permissions => me?.permissions ?? const Permissions();

  Counters get counters => me?.counters ?? const Counters();

  String get currency => me?.currency ?? 'KZT';

  Future<void> restore() async {
    try {
      api.token = await _storage.read(key: _tokenKey);
      clubName = await _storage.read(key: _clubKey) ?? '';
      clubs = _decodeClubs(await _storage.read(key: _clubsKey));
    } catch (_) {
      api.token = null;
    }
    if (api.token != null) await reloadMe();
    restoring = false;
    notifyListeners();
  }

  /// Загрузить профиль заново (после ошибки сети или смены клуба).
  Future<void> reloadMe() async {
    try {
      me = await api.me();
      clubName = me!.clubName.isNotEmpty ? me!.clubName : clubName;
      loadError = null;
    } on ApiException catch (e) {
      if (e.isUnauthorized) return;
      if (e.statusCode == 403) {
        await _forget();
        signInNotice = noStaffAccess;
      } else {
        me = null;
        loadError = e.message;
      }
    }
    notifyListeners();
  }

  /// Тихо обновить счётчики и смену (опрос из главного экрана).
  Future<void> refresh() async {
    if (!signedIn) return;
    try {
      me = await api.me();
      notifyListeners();
    } on ApiException {
      // 401 обработан в ApiClient; остальное — подождём следующего опроса.
    }
  }

  /// Экран знает свежий счётчик (новые заказы, непрочитанные) — показать сразу.
  void updateCounters({
    int? ordersNew,
    int? chatUnread,
    int? notificationsUnread,
  }) {
    final current = me;
    if (current == null) return;
    final next = current.counters.copyWith(
      ordersNew: ordersNew,
      chatUnread: chatUnread,
      notificationsUnread: notificationsUnread,
    );
    if (next.ordersNew == current.counters.ordersNew &&
        next.chatUnread == current.counters.chatUnread &&
        next.notificationsUnread == current.counters.notificationsUnread) {
      return;
    }
    me = current.withCounters(next);
    notifyListeners();
  }

  Future<void> login(String phone, String password) async {
    signInNotice = null;
    var result = await api.login(phone, password);
    api.token = result.token;
    try {
      // Токен выдан для клуба, где аккаунт не сотрудник — переходим в клуб сотрудника.
      if (!isStaffRole(result.clubRole)) {
        final staff = result.clubs.where((c) => c.isStaff).toList();
        if (staff.isEmpty) {
          throw ApiException(403, noStaffAccess);
        }
        result = await api.switchClub(staff.first.id);
        api.token = result.token;
      }
      final profile = await api.me();
      await _apply(result);
      me = profile;
      loadError = null;
    } on ApiException catch (e) {
      await _dropToken();
      if (e.statusCode == 403) throw ApiException(403, noStaffAccess);
      rethrow;
    }
    notifyListeners();
  }

  /// Перейти в другой клуб: сервер выдаёт новый токен, старый перестаёт работать.
  Future<void> switchClub(ClubInfo club) async {
    final result = await api.switchClub(club.id);
    await _apply(result);
    clubVersion++;
    await reloadMe();
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

  void _expired() {
    if (api.token == null) return;
    signInNotice = 'Сессия истекла. Войдите снова.';
    _forget().whenComplete(notifyListeners);
  }

  /// Вход не удался после выдачи токена: отзываем его на сервере.
  Future<void> _dropToken() async {
    try {
      await api.logout();
    } on ApiException {
      // Не важно: токен короткоживущий и нигде не сохранён.
    }
    api.token = null;
  }

  Future<void> _apply(LoginResult result) async {
    api.token = result.token;
    clubName = result.clubName;
    clubs = result.clubs;
    await _storage.write(key: _tokenKey, value: result.token);
    await _storage.write(key: _clubKey, value: result.clubName);
    await _storage.write(
      key: _clubsKey,
      value: jsonEncode([
        for (final c in result.clubs)
          {'id': c.id, 'name': c.name, 'current': c.current, 'role': c.role},
      ]),
    );
  }

  Future<void> _forget() async {
    api.token = null;
    me = null;
    loadError = null;
    clubs = const [];
    try {
      await _storage.delete(key: _tokenKey);
      await _storage.delete(key: _clubsKey);
    } catch (_) {
      // Хранилище недоступно — токен в памяти уже стёрт.
    }
  }

  static List<ClubInfo> _decodeClubs(String? raw) {
    if (raw == null || raw.isEmpty) return const [];
    try {
      final list = jsonDecode(raw);
      if (list is! List) return const [];
      return [
        for (final e in list)
          if (e is Map<String, dynamic>) ClubInfo.fromJson(e),
      ];
    } on FormatException {
      return const [];
    }
  }
}
