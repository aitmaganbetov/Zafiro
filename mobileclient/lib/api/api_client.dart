import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config.dart';
import 'models.dart';

class ApiException implements Exception {
  ApiException(this.statusCode, this.message, {this.code});

  final int statusCode;
  final String message;
  final String? code;

  bool get isUnauthorized => statusCode == 401;

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({http.Client? client, this.locale = 'ru'})
    : _http = client ?? http.Client();

  final http.Client _http;
  String locale;
  String? token;

  /// Вход в клуб из списка: если гость ещё не клиент этого клуба, он им станет.
  Future<LoginResult> login(String phone, String password, int clubId) async {
    final json = await _send(
      'POST',
      '/auth/login',
      body: {
        'phone': phone,
        'password': password,
        'device_name': deviceName,
        'club_id': clubId,
      },
    );
    return LoginResult.fromJson(json);
  }

  /// Города и клубы города (без входа).
  Future<Catalog> catalog({String? city}) async => Catalog.fromJson(
    await _send('GET', '/catalog/clubs', query: {'city': ?city}),
  );

  /// Страница клуба: фото, описание, зоны с оборудованием (без входа).
  Future<ClubDetails> club(int id) async =>
      ClubDetails.fromJson(await _send('GET', '/catalog/clubs/$id'));

  Future<void> logout() => _send('POST', '/auth/logout');

  Future<Profile> me() async =>
      Profile.fromJson((await _send('GET', '/auth/me'))['user']);

  Future<NewsFeed> news() async =>
      NewsFeed.fromJson(await _send('GET', '/guest/news'));

  Future<HistoryPage> history({int? before}) async => HistoryPage.fromJson(
    await _send(
      'GET',
      '/guest/history',
      query: {if (before != null) 'before': '$before'},
    ),
  );

  /// ПК и клуб из QR-кода на экране входа ПК.
  Future<QrLoginInfo> qrLoginInfo(String code) async =>
      QrLoginInfo.fromJson(await _send('GET', '/guest/qr-login/$code'));

  /// Подтвердить вход на ПК: ПК откроется под этим аккаунтом.
  Future<String> qrLoginApprove(String code) async =>
      (await _send('POST', '/guest/qr-login/$code'))['message'] as String? ??
      '';

  /// Клубы аккаунта (текущий отмечен).
  Future<List<ClubInfo>> clubs() async =>
      ClubInfo.listFromJson((await _send('GET', '/auth/clubs'))['clubs']);

  /// Перейти в другой клуб (в том числе новый из списка — гость станет его клиентом):
  /// сервер выдаёт новый токен, старый перестаёт работать.
  Future<LoginResult> switchClub(int clubId) async => LoginResult.fromJson(
    await _send('POST', '/auth/switch-club', body: {'club_id': clubId}),
  );

  Future<FriendsOverview> friends() async =>
      FriendsOverview.fromJson(await _send('GET', '/guest/friends'));

  /// Заявка в друзья по номеру; возвращает сообщение сервера.
  Future<String> addFriend(String phone) async =>
      (await _send('POST', '/guest/friends', body: {'phone': phone}))['message']
          as String? ??
      '';

  Future<void> acceptFriend(int friendshipId) =>
      _send('POST', '/guest/friends/$friendshipId/accept');

  Future<void> removeFriend(int friendshipId) =>
      _send('POST', '/guest/friends/$friendshipId/remove');

  /// Код в Telegram для подтверждения перевода именно этой суммы этому другу.
  Future<CodeSent> transferCode(int friendshipId, String amount) async =>
      CodeSent.fromJson(
        await _send(
          'POST',
          '/guest/friends/$friendshipId/transfer/code',
          body: {'amount': amount},
        ),
      );

  /// Перевод по коду из Telegram; возвращает сообщение «Переведено …».
  Future<String> transferConfirm(
    int friendshipId,
    String amount,
    String code,
  ) async =>
      (await _send(
            'POST',
            '/guest/friends/$friendshipId/transfer/confirm',
            body: {'amount': amount, 'code': code},
          ))['message']
          as String? ??
      '';

  Future<BookingsOverview> bookings() async =>
      BookingsOverview.fromJson(await _send('GET', '/guest/bookings'));

  /// ПК клуба по зонам: гость сначала выбирает ПК.
  Future<List<PcZone>> bookingComputers() async => [
    for (final item
        in (await _send('GET', '/guest/bookings/computers'))['zones']
                as List? ??
            const [])
      PcZone.fromJson(item as Map<String, dynamic>),
  ];

  /// Тарифы, которые можно забронировать на это начало (для зоны ПК).
  Future<List<BookingTariff>> bookingTariffs(
    DateTime start, {
    int? computerId,
  }) async => [
    for (final item
        in (await _send(
                  'GET',
                  '/guest/bookings/tariffs',
                  query: {
                    'starts_at': start.toUtc().toIso8601String(),
                    'computer_id': ?computerId?.toString(),
                  },
                ))['tariffs']
                as List? ??
            const [])
      BookingTariff.fromJson(item as Map<String, dynamic>),
  ];

  /// Зоны и ПК, свободные на это время, с ценой: по часовой ([minutes]) или тариф.
  Future<BookingOptions> bookingOptions(
    DateTime start, {
    int? minutes,
    int? tariffId,
  }) async => BookingOptions.fromJson(
    await _send(
      'GET',
      '/guest/bookings/options',
      query: {
        'starts_at': start.toUtc().toIso8601String(),
        'duration_minutes': ?minutes?.toString(),
        'tariff_id': ?tariffId?.toString(),
      },
    ),
  );

  /// Бронь с оплатой с баланса; возвращает сообщение сервера.
  Future<String> book(
    int computerId,
    DateTime start, {
    int? minutes,
    int? tariffId,
  }) async =>
      (await _send(
            'POST',
            '/guest/bookings',
            body: {
              'club_computer_id': computerId,
              'starts_at': start.toUtc().toIso8601String(),
              'duration_minutes': ?minutes,
              'tariff_id': ?tariffId,
            },
          ))['message']
          as String? ??
      '';

  Future<String> cancelBooking(int id) async =>
      (await _send('POST', '/guest/bookings/$id/cancel'))['message']
          as String? ??
      '';

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) async {
    final uri = Uri.parse(
      '$apiBaseUrl$path',
    ).replace(queryParameters: (query == null || query.isEmpty) ? null : query);
    final request = http.Request(method, uri)
      ..headers.addAll({
        'Accept': 'application/json',
        'Accept-Language': locale,
        if (token != null) 'Authorization': 'Bearer $token',
      });
    if (body != null) {
      request.headers['Content-Type'] = 'application/json';
      request.body = jsonEncode(body);
    }

    final http.Response response;
    try {
      response = await http.Response.fromStream(
        await _http.send(request).timeout(const Duration(seconds: 20)),
      );
    } catch (_) {
      throw ApiException(0, 'Нет связи с сервером. Проверьте интернет.');
    }

    final decoded = response.body.isEmpty ? null : _tryDecode(response.body);
    final json = decoded is Map<String, dynamic>
        ? decoded
        : <String, dynamic>{};

    if (response.statusCode >= 400) {
      throw ApiException(
        response.statusCode,
        _errorMessage(json) ?? 'Ошибка сервера (${response.statusCode}).',
        code: json['code'] as String?,
      );
    }
    return json;
  }

  static Object? _tryDecode(String body) {
    try {
      return jsonDecode(body);
    } on FormatException {
      return null;
    }
  }

  /// Laravel: {"message": "..."} или {"errors": {"field": ["..."]}}.
  static String? _errorMessage(Map<String, dynamic> json) {
    final errors = json['errors'];
    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return '${first.first}';
    }
    return json['message'] as String?;
  }
}
