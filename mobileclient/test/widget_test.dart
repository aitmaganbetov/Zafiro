import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:zafiro_client/api/models.dart';
import 'package:zafiro_client/format.dart';
import 'package:zafiro_client/geo.dart';
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

  test('список клубов: города и клубы города из /api/catalog/clubs', () {
    final catalog = Catalog.fromJson({
      'cities': [
        {
          'key': 'aktobe',
          'name': 'Актобе',
          'lat': 50.28,
          'lng': 57.17,
          'clubs': 2,
        },
      ],
      'city': 'aktobe',
      'clubs': [
        {'id': 5, 'name': 'Asia Game Club', 'address': null, 'logo_url': null},
      ],
    });

    expect(catalog.cities.single.clubs, 2);
    expect(catalog.clubs.single.name, 'Asia Game Club');
  });

  test('ближайший город по координатам телефона', () {
    final cities = [
      City(key: 'astana', name: 'Астана', lat: 51.17, lng: 71.45),
      City(key: 'aktobe', name: 'Актобе', lat: 50.28, lng: 57.17),
      City(key: 'almaty', name: 'Алматы', lat: 43.24, lng: 76.89),
    ];

    expect(nearestCity(cities, 50.30, 57.20)?.key, 'aktobe');
    expect(nearestCity(cities, 43.30, 76.95)?.key, 'almaty');
    expect(nearestCity(const [], 0, 0), isNull);
  });

  test('страница клуба: зоны, свободные ПК и оборудование', () {
    final club = ClubDetails.fromJson({
      'id': 5,
      'name': 'Asia Game Club',
      'city': 'Актобе',
      'address': 'ул. Есет батыра, 138А',
      'photos': ['https://zafiro.kz/club-photos/5/a.jpg'],
      'zones': [
        {
          'id': 1,
          'name': 'VIP',
          'price_per_hour': 800,
          'pcs': 10,
          'free_pcs': 4,
          'devices': [
            {'key': 'gpu', 'label': 'Видеокарта', 'value': 'RTX 3060'},
          ],
        },
        {
          'id': 2,
          'name': 'Main',
          'price_per_hour': 500,
          'pcs': 19,
          'free_pcs': 0,
        },
      ],
    });

    expect(club.pcs, 29);
    expect(club.freePcs, 4);
    expect(club.zones.first.devices.single.value, 'RTX 3060');
    expect(club.photos.single, endsWith('a.jpg'));
  });

  test('брони: суммы строкой, время в часовом поясе телефона', () {
    final overview = BookingsOverview.fromJson({
      'enabled': true,
      'can_cancel': false,
      'currency': 'KZT',
      'bookings': [
        {
          'id': 3,
          'computer': 'PC-01',
          'zone': 'VIP',
          'starts_at': '2026-11-15T12:00:00+00:00',
          'ends_at': '2026-11-15T13:30:00+00:00',
          'state': 'pending',
          'paid': '900.00',
          'can_cancel': false,
        },
      ],
    });
    final booking = overview.bookings.single;

    expect(overview.canCancel, isFalse);
    expect(overview.maxDaysAhead, 7);
    expect(booking.paid, 900);
    expect(booking.isUpcoming, isTrue);
    expect(booking.startsAt, DateTime.utc(2026, 11, 15, 12).toLocal());

    final zone = BookingZone.fromJson({
      'id': 1,
      'name': 'VIP',
      'price_per_hour': '600.00',
      'price': '900.00',
      'computers': [
        {'id': 1, 'name': 'PC-01', 'available': false},
        {'id': 2, 'name': 'PC-02', 'available': true},
      ],
    });
    expect(zone.price, 900);
    expect(zone.freeCount, 1);
  });

  test('бронь тарифом: тарифы и зоны без почасовой цены', () {
    final tariff = BookingTariff.fromJson({
      'id': 4,
      'name': 'Ночь',
      'description': ' ',
      'minutes': 540,
      'price': '2000.00',
      'zone_ids': [1],
    });
    final options = BookingOptions.fromJson({
      'minutes': 540,
      'zones': [
        {'id': 1, 'name': 'VIP', 'price_per_hour': null, 'price': '2000.00'},
      ],
    });

    expect(tariff.description, isNull);
    expect(tariff.price, 2000);
    expect(options.minutes, 540);
    expect(options.zones.single.pricePerHour, isNull);
    expect(options.zones.single.price, 2000);
  });

  test('ПК для брони: зоны без цены за час бронируют только тарифом', () {
    final zone = PcZone.fromJson({
      'id': 2,
      'name': 'Общий',
      'price_per_hour': null,
      'computers': [
        {'id': 5, 'name': 'PC-05'},
      ],
    });

    expect(zone.pricePerHour, isNull);
    expect(zone.computers.single.name, 'PC-05');
  });

  test('бронь на компанию: код и все ПК', () {
    final booking = Booking.fromJson({
      'id': 9,
      'code': '048213',
      'computer': 'PC-01, PC-02',
      'computers': ['PC-01', 'PC-02'],
      'starts_at': '2026-11-17T12:00:00+00:00',
      'ends_at': '2026-11-17T13:00:00+00:00',
      'state': 'pending',
      'paid': '1200.00',
    });

    expect(booking.code, '048213');
    expect(booking.computers, ['PC-01', 'PC-02']);
    expect(booking.paid, 1200);
  });
}
