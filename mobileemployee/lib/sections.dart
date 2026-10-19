import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import 'api/models.dart';

/// Разделы приложения; названия и иконки — как в сайдбаре панели.
enum Section {
  report('Отчёт за день', FontAwesomeIcons.chartPie),
  cash('Касса', FontAwesomeIcons.cashRegister),
  map('Карта клуба', FontAwesomeIcons.mapLocationDot),
  chat('Чат', FontAwesomeIcons.comments),
  orders('Заказы', FontAwesomeIcons.bagShopping),
  customers('Пользователи', FontAwesomeIcons.users),
  notifications('Уведомления', FontAwesomeIcons.bell),
  warehouse('Склад', FontAwesomeIcons.boxesStacked);

  const Section(this.title, this.icon);

  final String title;
  final FaIconData icon;

  /// Короткая подпись для нижней панели.
  String get shortTitle => switch (this) {
    Section.map => 'Карта',
    Section.customers => 'Клиенты',
    Section.report => 'Отчёт',
    _ => title,
  };

  bool allowedBy(Permissions p) => switch (this) {
    Section.cash => p.cash,
    Section.report => p.reports,
    _ => true,
  };

  int badge(Counters c) => switch (this) {
    Section.orders => c.ordersNew,
    Section.chat => c.chatUnread,
    Section.notifications => c.notificationsUnread,
    _ => 0,
  };
}

/// Разделы для меню, в порядке сайдбара панели.
List<Section> visibleSections(Permissions p) =>
    Section.values.where((s) => s.allowedBy(p)).toList();

/// Главные разделы для нижней панели (не больше четырёх; остальное — в меню).
List<Section> bottomSections(Permissions p) => const [
  Section.map,
  Section.cash,
  Section.orders,
  Section.chat,
  Section.customers,
].where((s) => s.allowedBy(p)).take(4).toList();

/// С чего начинать: карта клуба есть у всех сотрудников.
const Section startSection = Section.map;
