import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config.dart';
import 'models.dart';

class ApiException implements Exception {
  ApiException(
    this.statusCode,
    this.message, {
    this.code,
    this.errors = const {},
  });

  final int statusCode;
  final String message;
  final String? code;

  /// Ошибки проверки (422): поле → первое сообщение.
  final Map<String, String> errors;

  bool get isUnauthorized => statusCode == 401;

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({http.Client? client, this.baseUrl = apiBaseUrl})
    : _http = client ?? http.Client();

  final http.Client _http;
  final String baseUrl;
  String? token;

  /// Токен истёк или отозван (401 на запросе с токеном): назад ко входу.
  void Function()? onUnauthorized;

  // ── Вход ──────────────────────────────────────────────────────────────

  Future<LoginResult> login(String phone, String password) async =>
      LoginResult.fromJson(
        await _send(
          'POST',
          '/auth/login',
          body: {
            'phone': phone,
            'password': password,
            'device_name': deviceName,
          },
        ),
      );

  Future<LoginResult> switchClub(int clubId) async => LoginResult.fromJson(
    await _send('POST', '/auth/switch-club', body: {'club_id': clubId}),
  );

  Future<void> logout() => _send('POST', '/auth/logout');

  Future<Json> passwordCode(String phone) =>
      _send('POST', '/auth/password/code', body: {'phone': phone});

  Future<String> passwordReset({
    required String phone,
    required String code,
    required String password,
  }) async => toStr(
    (await _send(
      'POST',
      '/auth/password/reset',
      body: {
        'phone': phone,
        'code': code,
        'password': password,
        'password_confirmation': password,
      },
    ))['message'],
  );

  Future<StaffMe> me() async =>
      StaffMe.fromJson(await _send('GET', '/staff/me'));

  // ── Карта клуба ───────────────────────────────────────────────────────

  Future<ClubMap> computers() async =>
      ClubMap.fromJson(await _send('GET', '/staff/computers'));

  /// reserve | power_on | restart | power_off
  Future<Json> control(List<int> computerIds, String action) => _send(
    'POST',
    '/staff/computers/control',
    body: {'computer_ids': computerIds, 'action': action},
  );

  Future<GuestSessionResult> guestSession({
    required List<int> computerIds,
    int? tariffId,
    int? minutes,
    required String paymentMethod,
  }) async => GuestSessionResult.fromJson(
    await _send(
      'POST',
      '/staff/computers/guest-session',
      body: {
        'computer_ids': computerIds,
        'tariff_id': ?tariffId,
        'minutes': ?minutes,
        'payment_method': paymentMethod,
      },
    ),
  );

  Future<EndSessionResult> endSession(int computerId, String sessionId) async =>
      EndSessionResult.fromJson(
        await _send(
          'POST',
          '/staff/computers/$computerId/end-session',
          body: {'session_id': sessionId},
        ),
      );

  // ── Касса ─────────────────────────────────────────────────────────────

  Future<CashOverview> cash() async =>
      CashOverview.fromJson(await _send('GET', '/staff/cash'));

  Future<void> openShift(double openingBalance) => _send(
    'POST',
    '/staff/cash/shifts',
    body: {'opening_balance': openingBalance},
  );

  Future<ZReport> closeShift(int shiftId) async => ZReport.fromJson(
    await _send('POST', '/staff/cash/shifts/$shiftId/close'),
  );

  /// balanceType: balance | bonus_balance; paymentMethod: cash | card | qr.
  Future<TopUpResult> topUp({
    required int userId,
    required double amount,
    required String balanceType,
    required String paymentMethod,
  }) async => TopUpResult.fromJson(
    await _send(
      'POST',
      '/staff/cash/top-ups',
      body: {
        'user_id': userId,
        'amount': amount,
        'balance_type': balanceType,
        'payment_method': paymentMethod,
      },
    ),
  );

  Future<SaleResult> sale(Map<int, int> quantities) async =>
      SaleResult.fromJson(
        await _send(
          'POST',
          '/staff/cash/sales',
          body: {
            'items': [
              for (final e in quantities.entries)
                {'product_id': e.key, 'quantity': e.value},
            ],
          },
        ),
      );

  /// type: income | expense
  Future<void> cashTransaction({
    required String type,
    required double amount,
    required String description,
  }) => _send(
    'POST',
    '/staff/cash/transactions',
    body: {'type': type, 'amount': amount, 'description': description},
  );

  // ── Клиенты и товары ──────────────────────────────────────────────────

  Future<Paged<Customer>> customers({String search = '', int page = 1}) async =>
      Paged.fromJson(
        await _send(
          'GET',
          '/staff/customers',
          query: {
            if (search.trim().length >= 2) 'search': search.trim(),
            'page': '$page',
          },
        ),
        Customer.fromJson,
      );

  Future<Paged<Product>> products({String search = '', int page = 1}) async =>
      Paged.fromJson(
        await _send(
          'GET',
          '/staff/products',
          query: {
            if (search.trim().isNotEmpty) 'search': search.trim(),
            'page': '$page',
          },
        ),
        Product.fromJson,
      );

  Future<StockResult> stockReceive(
    int productId,
    int quantity,
    String note,
  ) async => StockResult.fromJson(
    await _send(
      'POST',
      '/staff/stock/receive',
      body: {
        'product_id': productId,
        'quantity': quantity,
        if (note.trim().isNotEmpty) 'note': note.trim(),
      },
    ),
  );

  /// type: receipt | write_off
  Future<StockResult> stockMovement(
    int productId,
    String type,
    int quantity,
    String note,
  ) async => StockResult.fromJson(
    await _send(
      'POST',
      '/staff/stock/movements',
      body: {
        'product_id': productId,
        'type': type,
        'quantity': quantity,
        if (note.trim().isNotEmpty) 'note': note.trim(),
      },
    ),
  );

  // ── Заказы ────────────────────────────────────────────────────────────

  /// status: new | done | cancelled | all
  Future<Paged<ShopOrder>> orders({
    String status = 'new',
    int page = 1,
  }) async => Paged.fromJson(
    await _send(
      'GET',
      '/staff/orders',
      query: {'status': status, 'page': '$page'},
    ),
    ShopOrder.fromJson,
  );

  Future<ShopOrder> orderDone(int id) async => ShopOrder.fromJson(
    _unwrap(await _send('POST', '/staff/orders/$id/done')),
  );

  Future<ShopOrder> orderCancel(int id) async => ShopOrder.fromJson(
    _unwrap(await _send('POST', '/staff/orders/$id/cancel')),
  );

  // ── Чат ───────────────────────────────────────────────────────────────

  Future<ChatList> chats() async =>
      ChatList.fromJson(await _send('GET', '/staff/chats'));

  Future<ChatPoll> chatPoll(int customerId, int after) async =>
      ChatPoll.fromJson(
        await _send(
          'GET',
          '/staff/chat/poll',
          query: {'customer': '$customerId', 'after': '$after'},
        ),
      );

  Future<ChatMessage?> chatSend(int customerId, String body) async {
    final message = (await _send(
      'POST',
      '/staff/chat/$customerId',
      body: {'body': body},
    ))['message'];
    return message is Map<String, dynamic>
        ? ChatMessage.fromJson(message)
        : null;
  }

  // ── Уведомления и отчёт ───────────────────────────────────────────────

  Future<NotificationList> notifications() async =>
      NotificationList.fromJson(await _send('GET', '/staff/notifications'));

  Future<void> notificationsSeen() =>
      _send('POST', '/staff/notifications/seen');

  Future<DayReport> reportToday() async =>
      DayReport.fromJson(await _send('GET', '/staff/reports/today'));

  // ── Транспорт ─────────────────────────────────────────────────────────

  /// Заказ может прийти как есть или в обёртке {order: …} / {data: …}.
  static Json _unwrap(Json json) {
    for (final key in const ['order', 'data']) {
      final inner = json[key];
      if (inner is Map<String, dynamic>) return inner;
    }
    return json;
  }

  Future<Json> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) async {
    final uri = Uri.parse(
      '$baseUrl$path',
    ).replace(queryParameters: (query == null || query.isEmpty) ? null : query);
    final sentToken = token;
    final request = http.Request(method, uri)
      ..headers.addAll({
        'Accept': 'application/json',
        'Accept-Language': 'ru',
        'Authorization': ?(sentToken == null ? null : 'Bearer $sentToken'),
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
      final error = errorFromResponse(response.statusCode, json);
      // Ответ на запрос со старым токеном: если токен уже сменили, это не выход.
      if (error.isUnauthorized && sentToken != null && sentToken == token) {
        onUnauthorized?.call();
      }
      throw error;
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

  /// Laravel: {"message": "..."} или {"message", "errors": {"field": ["..."]}}.
  static ApiException errorFromResponse(int status, Json json) {
    final errors = <String, String>{};
    final raw = json['errors'];
    if (raw is Map) {
      for (final entry in raw.entries) {
        final value = entry.value;
        if (value is List && value.isNotEmpty) {
          errors['${entry.key}'] = '${value.first}';
        } else if (value is String) {
          errors['${entry.key}'] = value;
        }
      }
    }
    final message = json['message'];
    final text = errors.isNotEmpty
        ? errors.values.first
        : message is String && message.isNotEmpty
        ? message
        : switch (status) {
            401 => 'Сессия истекла. Войдите снова.',
            402 => 'Подписка клуба не активна.',
            403 => 'Недостаточно прав для этого действия.',
            404 => 'Не найдено.',
            429 => 'Слишком много запросов. Подождите немного.',
            _ => 'Ошибка сервера ($status).',
          };
    return ApiException(
      status,
      status == 429 ? 'Слишком много запросов. Подождите немного.' : text,
      code: json['code'] is String ? json['code'] as String : null,
      errors: errors,
    );
  }
}
