// Ответы API сотрудника (docs/staff-api.md). Деньги приходят строкой «1500.00».

typedef Json = Map<String, dynamic>;

double toMoney(Object? value) => switch (value) {
  num v => v.toDouble(),
  String v => double.tryParse(v) ?? 0,
  _ => 0,
};

int toInt(Object? value) => switch (value) {
  int v => v,
  num v => v.round(),
  String v => int.tryParse(v) ?? double.tryParse(v)?.round() ?? 0,
  _ => 0,
};

int? toIntOrNull(Object? value) => value == null ? null : toInt(value);

bool toBool(Object? value) => value == true || value == 1 || value == '1';

String toStr(Object? value) => value == null ? '' : '$value';

DateTime? toDate(Object? value) =>
    value is String ? DateTime.tryParse(value)?.toLocal() : null;

Json _map(Object? value) => value is Map<String, dynamic> ? value : const {};

List<T> _list<T>(Object? value, T Function(Json) parse) => [
  for (final e in (value is List ? value : const []))
    if (e is Map<String, dynamic>) parse(e),
];

const staffRoles = {'owner', 'admin', 'manager', 'cashier', 'operator'};

bool isStaffRole(String? role) => staffRoles.contains(role);

// ── Вход ────────────────────────────────────────────────────────────────

class ClubInfo {
  const ClubInfo({
    required this.id,
    required this.name,
    this.current = false,
    this.role,
  });

  factory ClubInfo.fromJson(Json json) => ClubInfo(
    id: toInt(json['id']),
    name: toStr(json['name']),
    current: toBool(json['current']),
    role: json['role'] as String?,
  );

  final int id;
  final String name;
  final bool current;
  final String? role;

  bool get isStaff => isStaffRole(role);
}

class LoginResult {
  const LoginResult({
    required this.token,
    required this.clubId,
    required this.clubName,
    required this.role,
    required this.clubs,
  });

  factory LoginResult.fromJson(Json json) {
    final user = _map(json['user']);
    return LoginResult(
      token: toStr(json['token'] ?? json['access_token']),
      clubId: toInt(json['club_id']),
      clubName: toStr(json['club_name']),
      role: user['role'] as String?,
      clubs: _list(json['clubs'], ClubInfo.fromJson),
    );
  }

  final String token;
  final int clubId;
  final String clubName;
  final String? role;
  final List<ClubInfo> clubs;

  /// Роль в клубе токена: из списка клубов, иначе из user.role.
  String? get clubRole {
    for (final club in clubs) {
      if (club.id == clubId && club.role != null) return club.role;
    }
    return role;
  }
}

// ── Профиль сотрудника ──────────────────────────────────────────────────

class Permissions {
  const Permissions({
    this.cash = false,
    this.guestSession = false,
    this.endSession = false,
    this.control = false,
    this.chatReply = false,
    this.stockReceive = false,
    this.stockManage = false,
    this.reports = false,
  });

  factory Permissions.fromJson(Json json) => Permissions(
    cash: toBool(json['cash']),
    guestSession: toBool(json['guest_session']),
    endSession: toBool(json['end_session']),
    control: toBool(json['control']),
    chatReply: toBool(json['chat_reply']),
    stockReceive: toBool(json['stock_receive']),
    stockManage: toBool(json['stock_manage']),
    reports: toBool(json['reports']),
  );

  final bool cash;
  final bool guestSession;
  final bool endSession;
  final bool control;
  final bool chatReply;
  final bool stockReceive;
  final bool stockManage;
  final bool reports;
}

class Counters {
  const Counters({
    this.ordersNew = 0,
    this.chatUnread = 0,
    this.notificationsUnread = 0,
  });

  factory Counters.fromJson(Json json) => Counters(
    ordersNew: toInt(json['orders_new']),
    chatUnread: toInt(json['chat_unread']),
    notificationsUnread: toInt(json['notifications_unread']),
  );

  final int ordersNew;
  final int chatUnread;
  final int notificationsUnread;

  Counters copyWith({
    int? ordersNew,
    int? chatUnread,
    int? notificationsUnread,
  }) => Counters(
    ordersNew: ordersNew ?? this.ordersNew,
    chatUnread: chatUnread ?? this.chatUnread,
    notificationsUnread: notificationsUnread ?? this.notificationsUnread,
  );
}

class OpenShift {
  const OpenShift({required this.id, this.openedAt, this.balance = 0});

  factory OpenShift.fromJson(Json json) => OpenShift(
    id: toInt(json['id']),
    openedAt: toDate(json['opened_at']),
    balance: toMoney(json['balance']),
  );

  final int id;
  final DateTime? openedAt;
  final double balance;
}

class StaffMe {
  const StaffMe({
    required this.userId,
    required this.userName,
    required this.phone,
    required this.clubId,
    required this.clubName,
    required this.currency,
    required this.timezone,
    required this.role,
    required this.permissions,
    required this.counters,
    this.shift,
  });

  factory StaffMe.fromJson(Json json) {
    final user = _map(json['user']);
    final club = _map(json['club']);
    final shift = json['shift'];
    return StaffMe(
      userId: toInt(user['id']),
      userName: toStr(user['name']),
      phone: toStr(user['phone']),
      clubId: toInt(club['id']),
      clubName: toStr(club['name']),
      currency: club['currency'] as String? ?? 'KZT',
      timezone: toStr(club['timezone']),
      role: toStr(json['role']),
      permissions: Permissions.fromJson(_map(json['permissions'])),
      counters: Counters.fromJson(_map(json['counters'])),
      shift: shift is Map<String, dynamic> ? OpenShift.fromJson(shift) : null,
    );
  }

  final int userId;
  final String userName;
  final String phone;
  final int clubId;
  final String clubName;
  final String currency;
  final String timezone;
  final String role;
  final Permissions permissions;
  final Counters counters;
  final OpenShift? shift;

  StaffMe withCounters(Counters value) => StaffMe(
    userId: userId,
    userName: userName,
    phone: phone,
    clubId: clubId,
    clubName: clubName,
    currency: currency,
    timezone: timezone,
    role: role,
    permissions: permissions,
    counters: value,
    shift: shift,
  );
}

// ── Карта клуба ─────────────────────────────────────────────────────────

class Zone {
  const Zone({required this.id, required this.name, this.pricePerHour = 0});

  factory Zone.fromJson(Json json) => Zone(
    id: toInt(json['id']),
    name: toStr(json['name']),
    pricePerHour: toMoney(json['price_per_hour']),
  );

  final int id;
  final String name;
  final double pricePerHour;
}

class PcSession {
  const PcSession({
    required this.id,
    this.endsAt,
    this.guest = false,
    this.userId,
    this.userName = '',
  });

  factory PcSession.fromJson(Json json) {
    final user = _map(json['user']);
    return PcSession(
      id: toStr(json['id']),
      endsAt: toDate(json['ends_at']),
      guest: toBool(json['guest']),
      userId: toIntOrNull(user['id']),
      userName: toStr(user['name']),
    );
  }

  final String id;
  final DateTime? endsAt;
  final bool guest;
  final int? userId;
  final String userName;
}

class StaffVisit {
  const StaffVisit({this.until, this.name = ''});

  factory StaffVisit.fromJson(Json json) =>
      StaffVisit(until: toDate(json['until']), name: toStr(json['name']));

  final DateTime? until;
  final String name;
}

/// Как ПК выглядит на карте.
enum PcState { free, busy, reserved, restarting, offline, staff }

class Computer {
  const Computer({
    required this.id,
    required this.number,
    required this.name,
    this.zoneId,
    this.status = 'offline',
    this.connection = 'offline',
    this.agentState,
    this.reserved = false,
    this.bookedNow = false,
    this.session,
    this.staffVisit,
  });

  factory Computer.fromJson(Json json) {
    final session = json['session'];
    final visit = json['staff_visit'];
    return Computer(
      id: toInt(json['id']),
      number: toInt(json['number']),
      name: toStr(json['name']),
      zoneId: toIntOrNull(json['zone_id']),
      status: toStr(json['status']),
      connection: toStr(json['connection']),
      agentState: json['agent_state'] as String?,
      reserved: toBool(json['reserved']),
      bookedNow: toBool(json['booked_now']),
      session: session is Map<String, dynamic>
          ? PcSession.fromJson(session)
          : null,
      staffVisit: visit is Map<String, dynamic>
          ? StaffVisit.fromJson(visit)
          : null,
    );
  }

  final int id;
  final int number;
  final String name;
  final int? zoneId;

  /// online | offline | reserved | restarting
  final String status;

  /// online | offline — сигнал агента за последние 90 с.
  final String connection;

  /// idle | in_session | locked | shutting_down
  final String? agentState;
  final bool reserved;
  final bool bookedNow;
  final PcSession? session;
  final StaffVisit? staffVisit;

  String get title => name.isNotEmpty ? name : 'ПК $number';

  bool get isOnline => connection == 'online';

  PcState get state {
    if (staffVisit != null) return PcState.staff;
    if (session != null) return PcState.busy;
    if (reserved || status == 'reserved') return PcState.reserved;
    if (status == 'restarting') return PcState.restarting;
    if (!isOnline || status == 'offline') return PcState.offline;
    return PcState.free;
  }
}

class Tariff {
  const Tariff({
    required this.id,
    required this.name,
    this.minutes = 0,
    this.price = 0,
    this.zoneIds = const [],
  });

  factory Tariff.fromJson(Json json) => Tariff(
    id: toInt(json['id']),
    name: toStr(json['name']),
    minutes: toInt(json['minutes']),
    price: toMoney(json['price']),
    zoneIds: [
      for (final id
          in (json['zone_ids'] is List ? json['zone_ids'] as List : const []))
        toInt(id),
    ],
  );

  final int id;
  final String name;
  final int minutes;
  final double price;
  final List<int> zoneIds;

  /// Тариф подходит, если он открыт для зон всех выбранных ПК (без зон — для всех).
  bool fits(Iterable<int?> zones) =>
      zoneIds.isEmpty || zones.every((z) => z != null && zoneIds.contains(z));
}

class ClubMap {
  const ClubMap({
    required this.zones,
    required this.computers,
    this.guestMode = false,
    this.tariffs = const [],
  });

  factory ClubMap.fromJson(Json json) => ClubMap(
    zones: _list(json['zones'], Zone.fromJson),
    computers: _list(json['computers'], Computer.fromJson),
    guestMode: toBool(json['guest_mode']),
    tariffs: _list(json['tariffs'], Tariff.fromJson),
  );

  final List<Zone> zones;
  final List<Computer> computers;
  final bool guestMode;
  final List<Tariff> tariffs;

  Zone? zone(int? id) {
    for (final z in zones) {
      if (z.id == id) return z;
    }
    return null;
  }
}

class GuestSessionResult {
  const GuestSessionResult({
    required this.message,
    required this.amount,
    required this.endsAt,
  });

  factory GuestSessionResult.fromJson(Json json) => GuestSessionResult(
    message: toStr(json['message']),
    amount: toMoney(json['amount']),
    endsAt: [
      for (final c in _list(json['computers'], (j) => j)) toStr(c['ends_at']),
    ],
  );

  final String message;
  final double amount;

  /// «HH:MM» по каждому ПК.
  final List<String> endsAt;
}

class EndSessionResult {
  const EndSessionResult({
    this.refunded = 0,
    this.refundedBonus = 0,
    this.keptMinutes = 0,
  });

  factory EndSessionResult.fromJson(Json json) => EndSessionResult(
    refunded: toMoney(json['refunded']),
    refundedBonus: toMoney(json['refunded_bonus']),
    keptMinutes: toInt(json['kept_minutes']),
  );

  final double refunded;
  final double refundedBonus;
  final int keptMinutes;
}

// ── Касса ───────────────────────────────────────────────────────────────

class CashShift {
  const CashShift({
    required this.id,
    this.openedAt,
    this.closedAt,
    this.openingBalance = 0,
    this.closingBalance,
  });

  factory CashShift.fromJson(Json json) => CashShift(
    id: toInt(json['id']),
    openedAt: toDate(json['opened_at']),
    closedAt: toDate(json['closed_at']),
    openingBalance: toMoney(json['opening_balance']),
    closingBalance: json['closing_balance'] == null
        ? null
        : toMoney(json['closing_balance']),
  );

  final int id;
  final DateTime? openedAt;
  final DateTime? closedAt;
  final double openingBalance;
  final double? closingBalance;
}

class CashTotals {
  const CashTotals({this.income = 0, this.expense = 0, this.balance = 0});

  factory CashTotals.fromJson(Json json) => CashTotals(
    income: toMoney(json['income']),
    expense: toMoney(json['expense']),
    balance: toMoney(json['balance']),
  );

  final double income;
  final double expense;
  final double balance;
}

class ReportKind {
  const ReportKind({required this.label, this.count = 0, this.amount = 0});

  factory ReportKind.fromJson(Json json) => ReportKind(
    label: toStr(json['label']),
    count: toInt(json['count']),
    amount: toMoney(json['amount']),
  );

  final String label;
  final int count;
  final double amount;
}

class ReportItem {
  const ReportItem({required this.name, this.quantity = 0, this.amount = 0});

  factory ReportItem.fromJson(Json json) => ReportItem(
    name: toStr(json['name']),
    quantity: toInt(json['quantity']),
    amount: toMoney(json['amount']),
  );

  final String name;
  final int quantity;
  final double amount;
}

class ReportStaff {
  const ReportStaff({
    required this.name,
    this.count = 0,
    this.income = 0,
    this.expense = 0,
  });

  factory ReportStaff.fromJson(Json json) => ReportStaff(
    name: toStr(json['name']),
    count: toInt(json['count']),
    income: toMoney(json['income']),
    expense: toMoney(json['expense']),
  );

  final String name;
  final int count;
  final double income;
  final double expense;
}

class ShiftReport {
  const ShiftReport({
    this.kinds = const [],
    this.items = const [],
    this.itemsTotal = 0,
    this.discount = 0,
    this.staff = const [],
  });

  factory ShiftReport.fromJson(Json json) => ShiftReport(
    kinds: _list(json['kinds'], ReportKind.fromJson),
    items: _list(json['items'], ReportItem.fromJson),
    itemsTotal: toMoney(json['items_total']),
    discount: toMoney(json['discount']),
    staff: _list(json['staff'], ReportStaff.fromJson),
  );

  final List<ReportKind> kinds;
  final List<ReportItem> items;
  final double itemsTotal;
  final double discount;
  final List<ReportStaff> staff;
}

class CashTransaction {
  const CashTransaction({
    required this.id,
    required this.type,
    required this.amount,
    this.description = '',
    this.at,
  });

  factory CashTransaction.fromJson(Json json) => CashTransaction(
    id: toInt(json['id']),
    type: toStr(json['type']),
    amount: toMoney(json['amount']),
    description: toStr(json['description']),
    at: toDate(json['at']),
  );

  final int id;

  /// income | expense
  final String type;
  final double amount;
  final String description;
  final DateTime? at;

  bool get isIncome => type == 'income';
}

class CashOverview {
  const CashOverview({
    required this.currency,
    this.shift,
    this.totals,
    this.report,
    this.transactions = const [],
  });

  factory CashOverview.fromJson(Json json) {
    final shift = json['shift'];
    final totals = json['totals'];
    final report = json['report'];
    return CashOverview(
      currency: json['currency'] as String? ?? 'KZT',
      shift: shift is Map<String, dynamic> ? CashShift.fromJson(shift) : null,
      totals: totals is Map<String, dynamic>
          ? CashTotals.fromJson(totals)
          : null,
      report: report is Map<String, dynamic>
          ? ShiftReport.fromJson(report)
          : null,
      transactions: _list(json['transactions'], CashTransaction.fromJson),
    );
  }

  final String currency;
  final CashShift? shift;
  final CashTotals? totals;
  final ShiftReport? report;
  final List<CashTransaction> transactions;
}

/// Z-отчёт закрытой смены.
class ZReport {
  const ZReport({required this.shift, required this.totals, this.report});

  factory ZReport.fromJson(Json json) {
    final report = json['report'];
    return ZReport(
      shift: CashShift.fromJson(_map(json['shift'])),
      totals: CashTotals.fromJson(_map(json['totals'])),
      report: report is Map<String, dynamic>
          ? ShiftReport.fromJson(report)
          : null,
    );
  }

  final CashShift shift;
  final CashTotals totals;
  final ShiftReport? report;
}

class TopUpResult {
  const TopUpResult({
    required this.message,
    this.bonus = 0,
    this.balance = 0,
    this.bonusBalance = 0,
  });

  factory TopUpResult.fromJson(Json json) => TopUpResult(
    message: toStr(json['message']),
    bonus: toMoney(json['bonus']),
    balance: toMoney(json['balance']),
    bonusBalance: toMoney(json['bonus_balance']),
  );

  final String message;
  final double bonus;
  final double balance;
  final double bonusBalance;
}

class LineItem {
  const LineItem({
    required this.name,
    this.quantity = 0,
    this.price = 0,
    this.total = 0,
  });

  factory LineItem.fromJson(Json json) => LineItem(
    name: toStr(json['name']),
    quantity: toInt(json['quantity']),
    price: toMoney(json['price']),
    total: toMoney(json['total']),
  );

  final String name;
  final int quantity;
  final double price;
  final double total;
}

class SaleResult {
  const SaleResult({
    required this.id,
    required this.amount,
    this.items = const [],
  });

  factory SaleResult.fromJson(Json json) => SaleResult(
    id: toInt(json['id']),
    amount: toMoney(json['amount']),
    items: _list(json['items'], LineItem.fromJson),
  );

  final int id;
  final double amount;
  final List<LineItem> items;
}

// ── Списки с пагинацией ─────────────────────────────────────────────────

class Paged<T> {
  const Paged({
    required this.items,
    this.currentPage = 1,
    this.lastPage = 1,
    this.extra = const {},
  });

  factory Paged.fromJson(Json json, T Function(Json) parse) {
    final meta = _map(json['meta']);
    return Paged(
      items: _list(json['data'], parse),
      currentPage: meta['current_page'] == null
          ? 1
          : toInt(meta['current_page']),
      lastPage: meta['last_page'] == null ? 1 : toInt(meta['last_page']),
      extra: json,
    );
  }

  final List<T> items;
  final int currentPage;
  final int lastPage;

  /// Весь ответ: для полей рядом с data (например new_count).
  final Json extra;

  bool get hasMore => currentPage < lastPage;
}

class Customer {
  const Customer({
    required this.userId,
    required this.name,
    this.phone = '',
    this.balance = 0,
    this.bonusBalance = 0,
    this.discount = 0,
    this.discountCumulative = false,
    this.totalSpent = 0,
  });

  factory Customer.fromJson(Json json) => Customer(
    userId: toInt(json['user_id']),
    name: toStr(json['name']),
    phone: toStr(json['phone']),
    balance: toMoney(json['balance']),
    bonusBalance: toMoney(json['bonus_balance']),
    discount: toMoney(json['discount']),
    discountCumulative: toBool(json['discount_cumulative']),
    totalSpent: toMoney(json['total_spent']),
  );

  final int userId;
  final String name;
  final String phone;
  final double balance;
  final double bonusBalance;
  final double discount;
  final bool discountCumulative;
  final double totalSpent;
}

class Product {
  const Product({
    required this.id,
    required this.name,
    this.sku = '',
    this.category = '',
    this.price = 0,
    this.quantity = 0,
    this.imageUrl,
  });

  factory Product.fromJson(Json json) => Product(
    id: toInt(json['id']),
    name: toStr(json['name']),
    sku: toStr(json['sku']),
    category: toStr(json['category']),
    price: toMoney(json['price']),
    quantity: toInt(json['quantity']),
    imageUrl: (json['image_url'] as String?)?.trim().isEmpty ?? true
        ? null
        : json['image_url'] as String,
  );

  final int id;
  final String name;
  final String sku;
  final String category;
  final double price;
  final int quantity;
  final String? imageUrl;

  Product withQuantity(int value) => Product(
    id: id,
    name: name,
    sku: sku,
    category: category,
    price: price,
    quantity: value,
    imageUrl: imageUrl,
  );
}

class StockResult {
  const StockResult({required this.productId, required this.quantity});

  factory StockResult.fromJson(Json json) => StockResult(
    productId: toInt(json['product_id']),
    quantity: toInt(json['quantity']),
  );

  final int productId;
  final int quantity;
}

// ── Заказы с ПК ─────────────────────────────────────────────────────────

class ShopOrder {
  const ShopOrder({
    required this.id,
    required this.status,
    this.computerName = '',
    this.customerName = '',
    this.customerPhone = '',
    this.items = const [],
    this.amount = 0,
    this.createdAt,
    this.handledBy = '',
    this.handledAt,
  });

  factory ShopOrder.fromJson(Json json) {
    final customer = _map(json['customer']);
    final handledBy = json['handled_by'];
    return ShopOrder(
      id: toInt(json['id']),
      status: toStr(json['status']),
      computerName: toStr(json['computer_name']),
      customerName: toStr(customer['name']),
      customerPhone: toStr(customer['phone']),
      items: _list(json['items'], LineItem.fromJson),
      amount: toMoney(json['amount']),
      createdAt: toDate(json['created_at']),
      handledBy: handledBy is Map ? toStr(handledBy['name']) : toStr(handledBy),
      handledAt: toDate(json['handled_at']),
    );
  }

  final int id;

  /// new | done | cancelled
  final String status;
  final String computerName;
  final String customerName;
  final String customerPhone;
  final List<LineItem> items;
  final double amount;
  final DateTime? createdAt;
  final String handledBy;
  final DateTime? handledAt;

  bool get isNew => status == 'new';
}

// ── Чат ─────────────────────────────────────────────────────────────────

class Conversation {
  const Conversation({
    required this.customerId,
    required this.name,
    this.phone = '',
    this.unread = 0,
    this.lastBody = '',
    this.lastFromStaff = false,
    this.lastAt,
    this.lastAtRaw = '',
  });

  factory Conversation.fromJson(Json json) {
    final customer = _map(json['customer']);
    final last = _map(json['last']);
    return Conversation(
      customerId: toInt(customer['id']),
      name: toStr(customer['name']),
      phone: toStr(customer['phone']),
      unread: toInt(json['unread']),
      lastBody: toStr(last['body']),
      lastFromStaff: toBool(last['from_staff']),
      lastAt: toDate(last['at']),
      lastAtRaw: toStr(last['at']),
    );
  }

  final int customerId;
  final String name;
  final String phone;
  final int unread;
  final String lastBody;
  final bool lastFromStaff;
  final DateTime? lastAt;
  final String lastAtRaw;
}

class ChatList {
  const ChatList({required this.conversations, this.unread = 0});

  factory ChatList.fromJson(Json json) => ChatList(
    conversations: _list(json['conversations'], Conversation.fromJson),
    unread: toInt(json['unread']),
  );

  final List<Conversation> conversations;
  final int unread;
}

class ChatMessage {
  const ChatMessage({
    required this.id,
    required this.body,
    this.fromStaff = false,
    this.author = '',
    this.computer = '',
    this.at = '',
  });

  factory ChatMessage.fromJson(Json json) => ChatMessage(
    id: toInt(json['id']),
    body: toStr(json['body']),
    fromStaff: toBool(json['from_staff']),
    author: toStr(json['author']),
    computer: toStr(json['computer']),
    at: toStr(json['at']),
  );

  final int id;
  final String body;
  final bool fromStaff;
  final String author;
  final String computer;

  /// Уже в виде «dd.mm HH:MM».
  final String at;
}

class ChatPoll {
  const ChatPoll({required this.messages, this.unread = 0});

  factory ChatPoll.fromJson(Json json) => ChatPoll(
    messages: _list(json['messages'], ChatMessage.fromJson),
    unread: toInt(json['unread']),
  );

  final List<ChatMessage> messages;
  final int unread;
}

// ── Уведомления ─────────────────────────────────────────────────────────

class StaffNotification {
  const StaffNotification({
    required this.id,
    required this.title,
    this.icon = '',
    this.body = '',
    this.url = '',
    this.at,
    this.unread = false,
    this.atHuman = '',
  });

  factory StaffNotification.fromJson(Json json) => StaffNotification(
    id: toStr(json['id']),
    title: toStr(json['title']),
    icon: toStr(json['icon']),
    body: toStr(json['body']),
    url: toStr(json['url']),
    at: toDate(json['at']),
    unread: toBool(json['unread']),
    atHuman: toStr(json['at_human']),
  );

  final String id;
  final String title;
  final String icon;
  final String body;
  final String url;
  final DateTime? at;
  final bool unread;
  final String atHuman;
}

class NotificationList {
  const NotificationList({required this.items, this.unreadCount = 0});

  factory NotificationList.fromJson(Json json) => NotificationList(
    items: _list(json['items'], StaffNotification.fromJson),
    unreadCount: toInt(json['unread_count']),
  );

  final List<StaffNotification> items;
  final int unreadCount;
}

// ── Отчёт за день ───────────────────────────────────────────────────────

class DayPoint {
  const DayPoint({required this.date, this.income = 0, this.expense = 0});

  factory DayPoint.fromJson(Json json) => DayPoint(
    date: DateTime.tryParse(toStr(json['date'])),
    income: toMoney(json['income']),
    expense: toMoney(json['expense']),
  );

  final DateTime? date;
  final double income;
  final double expense;
}

class LowStock {
  const LowStock({required this.id, required this.name, this.quantity = 0});

  factory LowStock.fromJson(Json json) => LowStock(
    id: toInt(json['id']),
    name: toStr(json['name']),
    quantity: toInt(json['quantity']),
  );

  final int id;
  final String name;
  final int quantity;
}

class DayReport {
  const DayReport({
    required this.currency,
    this.income = 0,
    this.expense = 0,
    this.transactions = 0,
    this.week = const [],
    this.computersTotal = 0,
    this.computersOnline = 0,
    this.computersBusy = 0,
    this.computersReserved = 0,
    this.lowStock = const [],
    this.recent = const [],
  });

  factory DayReport.fromJson(Json json) {
    final today = _map(json['today']);
    final pcs = _map(json['computers']);
    return DayReport(
      currency: json['currency'] as String? ?? 'KZT',
      income: toMoney(today['income']),
      expense: toMoney(today['expense']),
      transactions: toInt(today['transactions']),
      week: _list(json['week'], DayPoint.fromJson),
      computersTotal: toInt(pcs['total']),
      computersOnline: toInt(pcs['online']),
      computersBusy: toInt(pcs['busy']),
      computersReserved: toInt(pcs['reserved']),
      lowStock: _list(json['low_stock'], LowStock.fromJson),
      recent: _list(json['recent_transactions'], CashTransaction.fromJson),
    );
  }

  final String currency;
  final double income;
  final double expense;
  final int transactions;
  final List<DayPoint> week;
  final int computersTotal;
  final int computersOnline;
  final int computersBusy;
  final int computersReserved;
  final List<LowStock> lowStock;
  final List<CashTransaction> recent;
}
