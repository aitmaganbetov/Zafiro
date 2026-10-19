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

  /// Все клубы аккаунта: несколько — гость выбирает клуб после входа.
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
