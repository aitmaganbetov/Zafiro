import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:zafiro_client/api/models.dart';
import 'package:zafiro_client/format.dart';
import 'package:zafiro_client/qr.dart';

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

  test('сумма в истории: знак, минус и разделитель тысяч', () {
    String plain(String s) => s.replaceAll(RegExp(r'\s'), ' ');
    expect(plain(signedAmount(5000)), '+5 000');
    expect(plain(signedAmount(-2500)), '\u22122 500');
    expect(signedAmount(0), '0');
    expect(plain(signedAmount(-12.5)), '\u221212,50');
  });

  test('из QR берётся только код входа на ПК', () {
    final code = '${'a' * 20}${'B' * 10}0123456789';
    expect(pcLoginCode('https://zafiro.kz/pc-login/$code'), code);
    expect(pcLoginCode(' https://zafiro.kz:8443/pc-login/$code\n'), code);
    expect(pcLoginCode('https://zafiro.kz/pc-login/done'), isNull);
    expect(pcLoginCode('https://zafiro.kz/pc-login/${code}x'), isNull);
    expect(pcLoginCode('https://evil.example/other/$code'), isNull);
    expect(pcLoginCode('WIFI:S:club;P:secret;;'), isNull);
    expect(pcLoginCode(null), isNull);
  });

  test('вход: список клубов аккаунта и текущий клуб', () {
    final result = LoginResult.fromJson({
      'access_token': 't',
      'club_id': 2,
      'club_name': 'Beta',
      'user': {'id': 1, 'name': 'A'},
      'clubs': [
        {'id': 1, 'name': 'Alpha', 'current': false},
        {'id': 2, 'name': 'Beta', 'current': true},
      ],
    });
    expect(result.clubs.map((c) => c.name), ['Alpha', 'Beta']);
    expect(result.clubs.where((c) => c.current).single.id, 2);
    expect(
      LoginResult.fromJson({
        'access_token': 't',
        'user': {'id': 1},
      }).clubs,
      isEmpty,
    );
  });

  test('друзья: возможности клуба и группы заявок', () {
    final overview = FriendsOverview.fromJson({
      'features': {'friends': true, 'balance_transfer': false},
      'friends': [
        {'id': 3, 'name': 'Dana', 'phone': '+7 *** 77'},
      ],
      'incoming': [],
      'outgoing': [
        {'id': 4, 'name': 'Erlan', 'phone': null},
      ],
    });
    expect(overview.friendsEnabled, isTrue);
    expect(overview.transfersEnabled, isFalse);
    expect(overview.friends.single.id, 3);
    expect(overview.outgoing.single.phone, '');
  });
}
