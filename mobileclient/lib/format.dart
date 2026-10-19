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
