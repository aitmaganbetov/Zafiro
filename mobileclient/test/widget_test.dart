import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:zafiro_client/api/models.dart';
import 'package:zafiro_client/format.dart';

void main() {
  setUpAll(() => initializeDateFormatting('ru'));

  test('профиль читается из ответа /api/auth/me', () {
    final profile = Profile.fromJson({
      'id': 7,
      'name': 'Алия',
      'phone': '+77001234567',
      'discount': 5,
      'balance': 1500.5,
      'bonus_balance': 200,
      'currency': 'KZT',
    });

    expect(profile.balance, 1500.5);
    expect(profile.bonusBalance, 200);
    expect(profile.discount, 5);
  });

  test('история: следующая страница по next_before', () {
    final page = HistoryPage.fromJson({
      'data': [
        {
          'id': 10,
          'at': '2026-10-01T10:00:00Z',
          'kind': 'user.balance_topped_up',
          'title': 'Пополнение',
          'amount': 1000,
          'bonus_amount': null,
        },
      ],
      'next_before': 10,
    });

    expect(page.entries.single.amount, 1000);
    expect(page.nextBefore, 10);
  });

  test('сумма в тенге', () {
    expect(money(1500, 'KZT'), contains('₸'));
    expect(money(1500, 'KZT'), isNot(contains(',')));
  });
}
