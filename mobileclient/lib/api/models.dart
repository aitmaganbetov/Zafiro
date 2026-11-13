double _num(Object? value) => value is num ? value.toDouble() : 0;

DateTime? _date(Object? value) =>
    value is String ? DateTime.tryParse(value)?.toLocal() : null;

class Profile {
  Profile({
    required this.id,
    required this.name,
    required this.phone,
    required this.discount,
    required this.balance,
    required this.bonusBalance,
    required this.currency,
  });

  factory Profile.fromJson(Map<String, dynamic> json) => Profile(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    phone: json['phone'] as String? ?? '',
    discount: _num(json['discount']),
    balance: _num(json['balance']),
    bonusBalance: _num(json['bonus_balance']),
    currency: json['currency'] as String? ?? 'KZT',
  );

  final int id;
  final String name;
  final String phone;
  final double discount;
  final double balance;
  final double bonusBalance;
  final String currency;
}

class LoginResult {
  LoginResult({
    required this.token,
    required this.clubId,
    required this.clubName,
    required this.profile,
    this.clubs = const [],
  });

  factory LoginResult.fromJson(Map<String, dynamic> json) => LoginResult(
    token: json['access_token'] as String,
    clubId: json['club_id'] as int? ?? 0,
    clubName: json['club_name'] as String? ?? '',
    profile: Profile.fromJson(json['user'] as Map<String, dynamic>),
    clubs: ClubInfo.listFromJson(json['clubs']),
  );

  final String token;
  final int clubId;
  final String clubName;
  final Profile profile;

  /// Все клубы аккаунта: в списке клубов города они отмечены.
  final List<ClubInfo> clubs;
}

class ClubInfo {
  ClubInfo({required this.id, required this.name, this.current = false});

  factory ClubInfo.fromJson(Map<String, dynamic> json) => ClubInfo(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    current: json['current'] as bool? ?? false,
  );

  static List<ClubInfo> listFromJson(Object? value) =>
      (value as List? ?? const [])
          .map((e) => ClubInfo.fromJson(e as Map<String, dynamic>))
          .toList();

  final int id;
  final String name;
  final bool current;
}

/// Город из списка Zafiro: координаты — чтобы найти ближайший к телефону.
class City {
  City({
    required this.key,
    required this.name,
    required this.lat,
    required this.lng,
    this.clubs = 0,
  });

  factory City.fromJson(Map<String, dynamic> json) => City(
    key: json['key'] as String,
    name: json['name'] as String? ?? '',
    lat: (json['lat'] as num).toDouble(),
    lng: (json['lng'] as num).toDouble(),
    clubs: json['clubs'] as int? ?? 0,
  );

  final String key;
  final String name;
  final double lat;
  final double lng;
  final int clubs;
}

/// Клуб в списке клубов города.
class CatalogClub {
  CatalogClub({
    required this.id,
    required this.name,
    this.address,
    this.logoUrl,
    this.photoUrl,
    this.hours,
    this.pcs = 0,
    this.freePcs = 0,
    this.priceFrom,
  });

  factory CatalogClub.fromJson(Map<String, dynamic> json) => CatalogClub(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    address: json['address'] as String?,
    logoUrl: json['logo_url'] as String?,
    photoUrl: json['photo_url'] as String?,
    hours: json['hours'] as String?,
    pcs: json['pcs'] as int? ?? 0,
    freePcs: json['free_pcs'] as int? ?? 0,
    priceFrom: (json['price_from'] as num?)?.toDouble(),
  );

  final int id;
  final String name;
  final String? address;
  final String? logoUrl;

  /// Обложка — первое фото клуба.
  final String? photoUrl;
  final String? hours;
  final int pcs;

  /// ПК, за которые можно сесть сейчас (агент в сети, без сессии и брони).
  final int freePcs;

  /// Самая низкая цена часа среди зон.
  final double? priceFrom;
}

/// Оборудование зоны: подпись и значение («Видеокарта» — «RTX 3060»).
class ZoneDevice {
  ZoneDevice({required this.key, required this.label, required this.value});

  factory ZoneDevice.fromJson(Map<String, dynamic> json) => ZoneDevice(
    key: json['key'] as String? ?? '',
    label: json['label'] as String? ?? '',
    value: json['value'] as String? ?? '',
  );

  final String key;
  final String label;
  final String value;
}

class ClubZoneInfo {
  ClubZoneInfo({
    required this.id,
    required this.name,
    this.description,
    this.pricePerHour = 0,
    this.pcs = 0,
    this.freePcs = 0,
    this.devices = const [],
  });

  factory ClubZoneInfo.fromJson(Map<String, dynamic> json) => ClubZoneInfo(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    description: json['description'] as String?,
    pricePerHour: (json['price_per_hour'] as num?)?.toDouble() ?? 0,
    pcs: json['pcs'] as int? ?? 0,
    freePcs: json['free_pcs'] as int? ?? 0,
    devices: (json['devices'] as List? ?? const [])
        .map((e) => ZoneDevice.fromJson(e as Map<String, dynamic>))
        .toList(),
  );

  final int id;
  final String name;
  final String? description;
  final double pricePerHour;
  final int pcs;
  final int freePcs;
  final List<ZoneDevice> devices;
}

/// Страница клуба (/api/catalog/clubs/{id}).
class ClubDetails {
  ClubDetails({
    required this.id,
    required this.name,
    this.city,
    this.address,
    this.logoUrl,
    this.description,
    this.phone,
    this.hours,
    this.instagram,
    this.photos = const [],
    this.zones = const [],
  });

  factory ClubDetails.fromJson(Map<String, dynamic> json) => ClubDetails(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    city: json['city'] as String?,
    address: json['address'] as String?,
    logoUrl: json['logo_url'] as String?,
    description: json['description'] as String?,
    phone: json['phone'] as String?,
    hours: json['hours'] as String?,
    instagram: json['instagram'] as String?,
    photos: (json['photos'] as List? ?? const []).cast<String>(),
    zones: (json['zones'] as List? ?? const [])
        .map((e) => ClubZoneInfo.fromJson(e as Map<String, dynamic>))
        .toList(),
  );

  final int id;
  final String name;
  final String? city;
  final String? address;
  final String? logoUrl;
  final String? description;
  final String? phone;
  final String? hours;
  final String? instagram;
  final List<String> photos;
  final List<ClubZoneInfo> zones;

  int get pcs => zones.fold(0, (sum, zone) => sum + zone.pcs);
  int get freePcs => zones.fold(0, (sum, zone) => sum + zone.freePcs);
}

/// Ответ /api/catalog/clubs: все города и клубы выбранного.
class Catalog {
  Catalog({required this.cities, required this.clubs});

  factory Catalog.fromJson(Map<String, dynamic> json) => Catalog(
    cities: (json['cities'] as List? ?? const [])
        .map((e) => City.fromJson(e as Map<String, dynamic>))
        .toList(),
    clubs: (json['clubs'] as List? ?? const [])
        .map((e) => CatalogClub.fromJson(e as Map<String, dynamic>))
        .toList(),
  );

  final List<City> cities;
  final List<CatalogClub> clubs;
}

class NewsItem {
  NewsItem({
    required this.id,
    required this.title,
    required this.body,
    this.imageUrl,
    this.publishedAt,
  });

  factory NewsItem.fromJson(Map<String, dynamic> json) => NewsItem(
    id: json['id'] as int,
    title: json['title'] as String? ?? '',
    body: json['body'] as String? ?? '',
    imageUrl: json['image_url'] as String?,
    publishedAt: _date(json['published_at']),
  );

  final int id;
  final String title;
  final String body;
  final String? imageUrl;
  final DateTime? publishedAt;
}

class NewsFeed {
  NewsFeed({required this.club, required this.platform});

  factory NewsFeed.fromJson(Map<String, dynamic> json) {
    List<NewsItem> list(Object? value) => (value as List? ?? const [])
        .map((e) => NewsItem.fromJson(e as Map<String, dynamic>))
        .toList();
    return NewsFeed(club: list(json['club']), platform: list(json['platform']));
  }

  final List<NewsItem> club;
  final List<NewsItem> platform;
}

class HistoryEntry {
  HistoryEntry({
    required this.id,
    required this.title,
    this.kind = '',
    this.at,
    this.note,
    this.amount,
    this.bonusAmount,
  });

  factory HistoryEntry.fromJson(Map<String, dynamic> json) => HistoryEntry(
    id: json['id'] as int,
    title: json['title'] as String? ?? '',
    kind: json['kind'] as String? ?? '',
    at: _date(json['at']),
    note: json['note'] as String?,
    amount: json['amount'] is num ? (json['amount'] as num).toDouble() : null,
    bonusAmount: json['bonus_amount'] is num
        ? (json['bonus_amount'] as num).toDouble()
        : null,
  );

  final int id;
  final String title;

  /// Событие журнала: tariff.purchased, shop.purchased, user.balance_topped_up…
  final String kind;
  final DateTime? at;
  final String? note;
  final double? amount;
  final double? bonusAmount;
}

class HistoryPage {
  HistoryPage({required this.entries, this.nextBefore});

  factory HistoryPage.fromJson(Map<String, dynamic> json) => HistoryPage(
    entries: (json['data'] as List? ?? const [])
        .map((e) => HistoryEntry.fromJson(e as Map<String, dynamic>))
        .toList(),
    nextBefore: json['next_before'] as int?,
  );

  final List<HistoryEntry> entries;
  final int? nextBefore;
}

class QrLoginInfo {
  QrLoginInfo({required this.computer, required this.clubName});

  factory QrLoginInfo.fromJson(Map<String, dynamic> json) => QrLoginInfo(
    computer: json['computer'] as String? ?? '',
    clubName: json['club_name'] as String? ?? '',
  );

  final String computer;
  final String clubName;
}

class Friend {
  Friend({required this.id, required this.name, required this.phone});

  factory Friend.fromJson(Map<String, dynamic> json) => Friend(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    phone: json['phone'] as String? ?? '',
  );

  /// Номер дружбы (friendship), по нему принимают, удаляют и переводят.
  final int id;
  final String name;

  /// Только последние цифры номера.
  final String phone;
}

class FriendsOverview {
  FriendsOverview({
    required this.friendsEnabled,
    required this.transfersEnabled,
    required this.friends,
    required this.incoming,
    required this.outgoing,
  });

  factory FriendsOverview.fromJson(Map<String, dynamic> json) {
    List<Friend> list(Object? value) => (value as List? ?? const [])
        .map((e) => Friend.fromJson(e as Map<String, dynamic>))
        .toList();
    final features = json['features'] as Map<String, dynamic>? ?? const {};
    return FriendsOverview(
      friendsEnabled: features['friends'] as bool? ?? false,
      transfersEnabled: features['balance_transfer'] as bool? ?? false,
      friends: list(json['friends']),
      incoming: list(json['incoming']),
      outgoing: list(json['outgoing']),
    );
  }

  /// Включено в настройках клуба («Гости»).
  final bool friendsEnabled;
  final bool transfersEnabled;
  final List<Friend> friends;
  final List<Friend> incoming;
  final List<Friend> outgoing;
}

class CodeSent {
  CodeSent({required this.message, required this.resendSeconds});

  factory CodeSent.fromJson(Map<String, dynamic> json) => CodeSent(
    message: json['message'] as String? ?? '',
    resendSeconds: json['resend_seconds'] as int? ?? 60,
  );

  final String message;
  final int resendSeconds;
}

/// Суммы брони сервер отдаёт строкой «900.00».
double _amount(Object? value) =>
    value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

/// Брони гостя в клубе и что клуб разрешает (GET /guest/bookings).
class BookingsOverview {
  BookingsOverview({
    required this.enabled,
    required this.canCancel,
    required this.currency,
    required this.minMinutes,
    required this.maxMinutes,
    required this.maxDaysAhead,
    required this.bookings,
  });

  factory BookingsOverview.fromJson(Map<String, dynamic> json) =>
      BookingsOverview(
        enabled: json['enabled'] == true,
        canCancel: json['can_cancel'] == true,
        currency: json['currency'] as String? ?? 'KZT',
        minMinutes: json['min_minutes'] as int? ?? 30,
        maxMinutes: json['max_minutes'] as int? ?? 720,
        maxDaysAhead: json['max_days_ahead'] as int? ?? 7,
        bookings: [
          for (final item in json['bookings'] as List? ?? const [])
            Booking.fromJson(item as Map<String, dynamic>),
        ],
      );

  final bool enabled;
  final bool canCancel;
  final String currency;
  final int minMinutes;
  final int maxMinutes;
  final int maxDaysAhead;
  final List<Booking> bookings;
}

class Booking {
  Booking({
    required this.id,
    required this.computer,
    required this.zone,
    required this.tariff,
    required this.startsAt,
    required this.endsAt,
    required this.state,
    required this.paid,
    required this.canCancel,
  });

  factory Booking.fromJson(Map<String, dynamic> json) => Booking(
    id: json['id'] as int,
    computer: json['computer'] as String? ?? '—',
    zone: json['zone'] as String?,
    tariff: json['tariff'] as String?,
    startsAt: _date(json['starts_at']) ?? DateTime.now(),
    endsAt: _date(json['ends_at']) ?? DateTime.now(),
    state: json['state'] as String? ?? 'pending',
    paid: _amount(json['paid']),
    canCancel: json['can_cancel'] == true,
  );

  final int id;
  final String computer;
  final String? zone;

  /// Тариф брони; null — по часовой.
  final String? tariff;
  final DateTime startsAt;
  final DateTime endsAt;

  /// pending, active, finished, arrived или cancelled.
  final String state;
  final double paid;
  final bool canCancel;

  /// Ещё впереди или идёт: такие брони показываются сверху.
  bool get isUpcoming => state == 'pending' || state == 'active';
}

/// Зона с ценой за выбранное время и её ПК (GET /guest/bookings/options).
class BookingZone {
  BookingZone({
    required this.id,
    required this.name,
    required this.pricePerHour,
    required this.price,
    required this.computers,
  });

  factory BookingZone.fromJson(Map<String, dynamic> json) => BookingZone(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    pricePerHour: json['price_per_hour'] == null
        ? null
        : _amount(json['price_per_hour']),
    price: _amount(json['price']),
    computers: [
      for (final item in json['computers'] as List? ?? const [])
        BookingPc.fromJson(item as Map<String, dynamic>),
    ],
  );

  final int id;
  final String name;

  /// null — у зоны нет почасовой цены (бронь тарифом).
  final double? pricePerHour;
  final double price;
  final List<BookingPc> computers;

  int get freeCount => computers.where((pc) => pc.available).length;
}

class BookingPc {
  BookingPc({required this.id, required this.name, required this.available});

  factory BookingPc.fromJson(Map<String, dynamic> json) => BookingPc(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    available: json['available'] == true,
  );

  final int id;
  final String name;
  final bool available;
}

/// Зоны и ПК для брони и сколько минут она даёт (у тарифа — его время).
class BookingOptions {
  BookingOptions({required this.minutes, required this.zones});

  factory BookingOptions.fromJson(Map<String, dynamic> json) => BookingOptions(
    minutes: json['minutes'] as int? ?? 0,
    zones: [
      for (final item in json['zones'] as List? ?? const [])
        BookingZone.fromJson(item as Map<String, dynamic>),
    ],
  );

  final int minutes;
  final List<BookingZone> zones;
}

/// Тариф, который можно забронировать на выбранное начало.
class BookingTariff {
  BookingTariff({
    required this.id,
    required this.name,
    required this.description,
    required this.minutes,
    required this.price,
  });

  factory BookingTariff.fromJson(Map<String, dynamic> json) => BookingTariff(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    description: (json['description'] as String?)?.trim().isEmpty ?? true
        ? null
        : json['description'] as String,
    minutes: json['minutes'] as int? ?? 0,
    price: _amount(json['price']),
  );

  final int id;
  final String name;
  final String? description;
  final int minutes;
  final double price;
}
