import 'package:intl/intl.dart';

String money(double value, String currency) {
  final symbol = switch (currency) {
    'KZT' => '₸',
    'RUB' => '₽',
    'USD' => r'$',
    _ => currency,
  };
  return NumberFormat.currency(
    locale: 'ru',
    symbol: symbol,
    decimalDigits: value % 1 == 0 ? 0 : 2,
  ).format(value);
}

String dateTime(DateTime? value) =>
    value == null ? '' : DateFormat('d MMM yyyy, HH:mm', 'ru').format(value);

/// «+5 000», «−2 500»: со знаком, разделителем тысяч и настоящим минусом.
String signedAmount(double value) {
  final number = NumberFormat.decimalPatternDigits(
    locale: 'ru',
    decimalDigits: value % 1 == 0 ? 0 : 2,
  ).format(value.abs());
  return '${value > 0
      ? '+'
      : value < 0
      ? '\u2212'
      : ''}$number';
}
