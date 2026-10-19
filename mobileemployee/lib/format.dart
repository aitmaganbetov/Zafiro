import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

const _nbsp = ' ';

String currencySymbol(String currency) => switch (currency) {
  'KZT' => '₸',
  'RUB' => '₽',
  'USD' => r'$',
  'EUR' => '€',
  'KGS' => 'сом',
  'UZS' => 'сум',
  _ => currency,
};

/// Разряды через пробел: 15000 → «15 000», 1500.5 → «1 500,50».
String number(num value) {
  final cents = (value.abs() * 100).round();
  final whole = (cents ~/ 100).toString();
  final fraction = cents % 100;
  final buffer = StringBuffer();
  for (var i = 0; i < whole.length; i++) {
    if (i > 0 && (whole.length - i) % 3 == 0) buffer.write(_nbsp);
    buffer.write(whole[i]);
  }
  if (fraction != 0) {
    buffer
      ..write(',')
      ..write(fraction.toString().padLeft(2, '0'));
  }
  final sign = value < 0 && cents != 0 ? '−' : '';
  return '$sign$buffer';
}

/// «15 000 ₸» в валюте клуба.
String money(num value, String currency) =>
    '${number(value)}$_nbsp${currencySymbol(currency)}';

/// «+5 000», «−2 500».
String signedMoney(num value, String currency) =>
    '${value > 0 ? '+' : ''}${money(value, currency)}';

/// Сумма из поля ввода: «1 500,5» → 1500.5; пусто или не число — null.
double? parseAmount(String text) {
  final cleaned = text.replaceAll(RegExp(r'[\s ]'), '').replaceAll(',', '.');
  if (cleaned.isEmpty) return null;
  return double.tryParse(cleaned);
}

/// Время на устройстве: «14:05».
String time(DateTime? value) =>
    value == null ? '' : DateFormat('HH:mm').format(value);

/// «19 окт., 14:05».
String dateTime(DateTime? value) =>
    value == null ? '' : DateFormat('d MMM, HH:mm', 'ru').format(value);

/// Сколько осталось до конца сессии: «1:05», «0:45»; прошло — «0:00».
String remaining(DateTime? endsAt, {DateTime? now}) {
  if (endsAt == null) return '';
  final left = endsAt.difference(now ?? DateTime.now());
  if (left.isNegative) return '0:00';
  final minutes = (left.inSeconds / 60).ceil();
  return '${minutes ~/ 60}:${(minutes % 60).toString().padLeft(2, '0')}';
}

/// Номер для API: +7 и 10 цифр. 8 или 7 в начале — код страны.
String normalizePhone(String text) {
  var digits = text.replaceAll(RegExp(r'\D'), '');
  if (digits.length == 11 &&
      (digits.startsWith('8') || digits.startsWith('7'))) {
    digits = digits.substring(1);
  }
  return '+7$digits';
}

bool isValidPhone(String text) =>
    RegExp(r'^\+7\d{10}$').hasMatch(normalizePhone(text));

/// «+77011112233» → «+7 (701) 111-22-33»; другие номера — как есть.
String formatPhone(String phone) {
  final digits = phone.replaceAll(RegExp(r'\D'), '');
  if (digits.length != 11 || !digits.startsWith('7')) return phone;
  return _mask(digits.substring(1));
}

String _mask(String d) {
  final b = StringBuffer('+7');
  if (d.isEmpty) return b.toString();
  b.write(' (');
  b.write(d.substring(0, d.length < 3 ? d.length : 3));
  if (d.length < 3) return b.toString();
  b.write(')');
  if (d.length > 3) b.write(' ${d.substring(3, d.length < 6 ? d.length : 6)}');
  if (d.length > 6) b.write('-${d.substring(6, d.length < 8 ? d.length : 8)}');
  if (d.length > 8) b.write('-${d.substring(8)}');
  return b.toString();
}

/// Маска номера: «+7 (700) 123-45-67». Первая 8 или 7 считается кодом страны.
class PhoneMaskFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    var digits = newValue.text.replaceAll(RegExp(r'\D'), '');
    final countryCode =
        newValue.text.trimLeft().startsWith('+') ||
        (digits.length >= 11 &&
            (digits.startsWith('7') || digits.startsWith('8')));
    if (countryCode && digits.isNotEmpty) digits = digits.substring(1);
    // Стёрли закрывающую скобку или дефис — стираем и цифру перед ним.
    final oldDigits = oldValue.text.replaceAll(RegExp(r'\D'), '');
    if (newValue.text.length < oldValue.text.length &&
        newValue.text.replaceAll(RegExp(r'\D'), '') == oldDigits &&
        digits.isNotEmpty) {
      digits = digits.substring(0, digits.length - 1);
    }
    if (digits.length > 10) digits = digits.substring(0, 10);
    final text = _mask(digits);
    return TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }
}

const roleLabels = {
  'owner': 'Владелец',
  'admin': 'Администратор',
  'manager': 'Менеджер',
  'cashier': 'Кассир',
  'operator': 'Оператор',
};

String roleLabel(String? role) => roleLabels[role] ?? (role ?? '');

const paymentLabels = {'cash': 'Наличные', 'card': 'Карта', 'qr': 'QR-код'};
