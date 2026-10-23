import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zafiro_staff/api/api_client.dart';
import 'package:zafiro_staff/api/models.dart';
import 'package:zafiro_staff/format.dart';
import 'package:zafiro_staff/sections.dart';
import 'package:zafiro_staff/theme.dart';
import 'package:zafiro_staff/widgets.dart';

String plain(String s) => s.replaceAll(' ', ' ');

Permissions perms(String role) => Permissions.fromJson(switch (role) {
  'cashier' => {
    'cash': true,
    'guest_session': true,
    'end_session': false,
    'control': true,
    'chat_reply': true,
    'stock_receive': true,
    'stock_manage': false,
    'reports': false,
  },
  'operator' => {
    'cash': false,
    'guest_session': false,
    'end_session': true,
    'control': true,
    'chat_reply': true,
    'stock_receive': true,
    'stock_manage': false,
    'reports': false,
  },
  _ => {
    'cash': true,
    'guest_session': true,
    'end_session': true,
    'control': true,
    'chat_reply': false,
    'stock_receive': true,
    'stock_manage': true,
    'reports': true,
  },
});

void main() {
  group('деньги', () {
    test('разряды через пробел и символ валюты', () {
      expect(plain(money(15000, 'KZT')), '15 000 ₸');
      expect(plain(money(toMoney('1500.00'), 'KZT')), '1 500 ₸');
      expect(plain(money(toMoney('1234567.50'), 'RUB')), '1 234 567,50 ₽');
      expect(plain(money(0, 'KZT')), '0 ₸');
      expect(plain(money(999, 'USD')), r'999 $');
    });

    test('знак у сумм', () {
      expect(plain(signedMoney(5000, 'KZT')), '+5 000 ₸');
      expect(plain(signedMoney(-2500, 'KZT')), '−2 500 ₸');
    });

    test('сумма из поля ввода', () {
      expect(parseAmount('1 500,5'), 1500.5);
      expect(parseAmount('2000'), 2000);
      expect(parseAmount(''), isNull);
      expect(parseAmount('abc'), isNull);
    });
  });

  group('телефон', () {
    test('номер для API', () {
      expect(normalizePhone('+7 (700) 123-45-67'), '+77001234567');
      expect(normalizePhone('87001234567'), '+77001234567');
      expect(isValidPhone('+7 (700) 123-45'), isFalse);
      expect(formatPhone('+77011112233'), '+7 (701) 111-22-33');
    });

    test('маска при вводе', () {
      final formatter = PhoneMaskFormatter();
      TextEditingValue type(String text) => formatter.formatEditUpdate(
        TextEditingValue.empty,
        TextEditingValue(text: text),
      );
      expect(type('+77001234567').text, '+7 (700) 123-45-67');
      expect(type('87001234567').text, '+7 (700) 123-45-67');
      expect(type('+7700').text, '+7 (700)');
    });
  });

  group('разделы по правам', () {
    test('кассир: касса есть, отчёта нет', () {
      final sections = visibleSections(perms('cashier'));
      expect(sections, contains(Section.cash));
      expect(sections, isNot(contains(Section.report)));
      expect(sections, contains(Section.map));
      expect(bottomSections(perms('cashier')), [
        Section.map,
        Section.cash,
        Section.orders,
        Section.chat,
      ]);
    });

    test('оператор: без кассы и отчёта', () {
      final sections = visibleSections(perms('operator'));
      expect(sections, isNot(contains(Section.cash)));
      expect(sections, isNot(contains(Section.report)));
      expect(bottomSections(perms('operator')), [
        Section.map,
        Section.orders,
        Section.chat,
        Section.customers,
      ]);
    });

    test('владелец видит всё', () {
      expect(visibleSections(perms('owner')), Section.values);
    });

    test('без прав — ничего лишнего', () {
      final sections = visibleSections(const Permissions());
      expect(sections, isNot(contains(Section.cash)));
      expect(sections.first, isNot(Section.report));
    });

    test('счётчики на разделах', () {
      const counters = Counters(
        ordersNew: 2,
        chatUnread: 1,
        notificationsUnread: 3,
      );
      expect(Section.orders.badge(counters), 2);
      expect(Section.chat.badge(counters), 1);
      expect(Section.notifications.badge(counters), 3);
      expect(Section.cash.badge(counters), 0);
    });
  });

  group('ответы API', () {
    test('профиль сотрудника из GET /staff/me', () {
      final me = StaffMe.fromJson({
        'user': {'id': 1, 'name': 'Арман', 'phone': '+77001234567'},
        'club': {
          'id': 5,
          'name': 'Cyber Arena',
          'currency': 'KZT',
          'timezone': 'Asia/Almaty',
        },
        'role': 'cashier',
        'permissions': {'cash': true, 'reports': false},
        'counters': {
          'orders_new': 2,
          'chat_unread': 1,
          'notifications_unread': 3,
        },
        'shift': {
          'id': 12,
          'opened_at': '2026-10-19T08:00:00Z',
          'balance': '15000.00',
        },
      });
      expect(me.permissions.cash, isTrue);
      expect(me.permissions.reports, isFalse);
      expect(me.counters.notificationsUnread, 3);
      expect(me.shift?.balance, 15000);
    });

    test('статус ПК на карте', () {
      Computer pc(Map<String, dynamic> extra) => Computer.fromJson({
        'id': 7,
        'number': 21,
        'name': 'PC 21',
        'zone_id': 1,
        'status': 'online',
        'connection': 'online',
        'reserved': false,
        'session': null,
        'staff_visit': null,
        ...extra,
      });
      expect(pc({}).state, PcState.free);
      expect(pc({}).agentVersion, isNull);
      final old = pc({'agent_version': '0.0.58', 'agent_outdated': true});
      expect(old.agentVersion, '0.0.58');
      expect(old.agentOutdated, isTrue);
      expect(
        pc({'connection': 'offline', 'status': 'offline'}).state,
        PcState.offline,
      );
      expect(
        pc({'reserved': true, 'status': 'reserved'}).state,
        PcState.reserved,
      );
      expect(
        pc({
          'session': {
            'id': 'uuid',
            'ends_at': '2026-10-19T12:30:00Z',
            'guest': false,
            'user': {'id': 9, 'name': 'Ерлан'},
          },
        }).state,
        PcState.busy,
      );
      expect(
        pc({
          'staff_visit': {'until': '2026-10-19T10:15:00Z', 'name': 'Админ'},
        }).state,
        PcState.staff,
      );
    });

    test('остаток времени сессии', () {
      final now = DateTime(2026, 10, 19, 10);
      expect(remaining(now.add(const Duration(minutes: 85)), now: now), '1:25');
      expect(remaining(now.add(const Duration(seconds: 30)), now: now), '0:01');
      expect(
        remaining(now.subtract(const Duration(minutes: 1)), now: now),
        '0:00',
      );
    });

    test('тариф подходит зонам выбранных ПК', () {
      const tariff = Tariff(id: 3, name: '1 час', zoneIds: [1]);
      expect(tariff.fits([1, 1]), isTrue);
      expect(tariff.fits([1, 2]), isFalse);
      expect(const Tariff(id: 4, name: 'Все').fits([2]), isTrue);
    });

    test('вход: токен и роль в клубе', () {
      final result = LoginResult.fromJson({
        'token': 't',
        'club_id': 2,
        'club_name': 'Beta',
        'user': {'id': 1, 'role': 'client'},
        'clubs': [
          {'id': 1, 'name': 'Alpha', 'current': false, 'role': 'cashier'},
          {'id': 2, 'name': 'Beta', 'current': true, 'role': 'client'},
        ],
      });
      expect(result.token, 't');
      expect(isStaffRole(result.clubRole), isFalse);
      expect(result.clubs.where((c) => c.isStaff).single.id, 1);
    });
  });

  group('ошибки сервера', () {
    test('422: первое сообщение из errors', () async {
      final api = ApiClient(
        baseUrl: 'https://example.test/api',
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({
              'message': 'The given data was invalid.',
              'errors': {
                'amount': ['Введите сумму'],
              },
            }),
            422,
            headers: {'content-type': 'application/json; charset=utf-8'},
          ),
        ),
      )..token = 'x';
      await expectLater(
        api.cash(),
        throwsA(
          isA<ApiException>()
              .having((e) => e.statusCode, 'status', 422)
              .having((e) => e.message, 'message', 'Введите сумму'),
        ),
      );
    });

    test('401 с токеном — назад ко входу', () async {
      var expired = false;
      final api =
          ApiClient(
              baseUrl: 'https://example.test/api',
              client: MockClient(
                (_) async =>
                    http.Response('{"message":"Unauthenticated."}', 401),
              ),
            )
            ..token = 'x'
            ..onUnauthorized = () => expired = true;
      await expectLater(api.me(), throwsA(isA<ApiException>()));
      expect(expired, isTrue);
    });

    test('402 без текста — понятное сообщение', () {
      final e = ApiClient.errorFromResponse(402, const {});
      expect(e.message, 'Подписка клуба не активна.');
    });
  });

  testWidgets('счётчик в бейдже и карточка показателя', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: buildTheme(Brightness.light),
        home: Scaffold(
          body: Column(
            children: [
              const CountBadge(3),
              const CountBadge(0),
              StatTile(
                label: 'Доход сегодня',
                value: '45 000 ₸',
                icon: Section.report.icon,
              ),
            ],
          ),
        ),
      ),
    );
    expect(find.text('3'), findsOneWidget);
    expect(find.text('0'), findsNothing);
    expect(find.text('Доход сегодня'), findsOneWidget);
  });
}
