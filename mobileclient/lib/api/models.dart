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
    required this.clubName,
    required this.profile,
  });

  factory LoginResult.fromJson(Map<String, dynamic> json) => LoginResult(
    token: json['access_token'] as String,
    clubName: json['club_name'] as String? ?? '',
    profile: Profile.fromJson(json['user'] as Map<String, dynamic>),
  );

  final String token;
  final String clubName;
  final Profile profile;
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
    this.at,
    this.note,
    this.amount,
    this.bonusAmount,
  });

  factory HistoryEntry.fromJson(Map<String, dynamic> json) => HistoryEntry(
    id: json['id'] as int,
    title: json['title'] as String? ?? '',
    at: _date(json['at']),
    note: json['note'] as String?,
    amount: json['amount'] is num ? (json['amount'] as num).toDouble() : null,
    bonusAmount: json['bonus_amount'] is num
        ? (json['bonus_amount'] as num).toDouble()
        : null,
  );

  final int id;
  final String title;
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
