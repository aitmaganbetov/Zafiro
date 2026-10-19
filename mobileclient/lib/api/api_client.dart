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

  Future<LoginResult> login(String phone, String password) async {
    final json = await _send(
      'POST',
      '/auth/login',
      body: {'phone': phone, 'password': password, 'device_name': deviceName},
    );
    return LoginResult.fromJson(json);
  }

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
