import 'dart:async';

import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/models.dart';
import '../format.dart';
import '../sections.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';
import 'cash.dart';
import 'chat.dart';
import 'club_map.dart';
import 'customers.dart';
import 'notifications.dart';
import 'orders.dart';
import 'report.dart';
import 'warehouse.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.session});

  final Session session;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final _scaffold = GlobalKey<ScaffoldState>();
  Section _section = startSection;
  Timer? _poll;

  Session get session => widget.session;

  @override
  void initState() {
    super.initState();
    // Счётчики заказов, чата и уведомлений — раз в 30 секунд.
    _poll = Timer.periodic(
      const Duration(seconds: 30),
      (_) => session.refresh(),
    );
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  void _open(Section section) {
    if (_scaffold.currentState?.isDrawerOpen ?? false) {
      Navigator.of(context).pop();
    }
    if (section != _section) setState(() => _section = section);
  }

  Widget _body(Section section) {
    final key = ValueKey(section);
    return switch (section) {
      Section.map => ClubMapScreen(key: key, session: session),
      Section.cash => CashScreen(key: key, session: session),
      Section.customers => CustomersScreen(key: key, session: session),
      Section.orders => OrdersScreen(key: key, session: session),
      Section.chat => ChatListScreen(key: key, session: session),
      Section.notifications => NotificationsScreen(key: key, session: session),
      Section.warehouse => WarehouseScreen(key: key, session: session),
      Section.report => ReportScreen(key: key, session: session),
    };
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: session,
      builder: (context, _) {
        final permissions = session.permissions;
        final counters = session.counters;
        final visible = visibleSections(permissions);
        final section = visible.contains(_section) ? _section : visible.first;
        final bottom = bottomSections(permissions);
        final bottomIndex = bottom.indexOf(section);
        final overflowBadge = visible
            .where((s) => !bottom.contains(s))
            .fold<int>(0, (sum, s) => sum + s.badge(counters));

        return Scaffold(
          key: _scaffold,
          appBar: AppBar(
            title: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(section.title),
                if (session.clubName.isNotEmpty)
                  Text(
                    session.clubName,
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                      color: Palette.of(context).muted,
                    ),
                  ),
              ],
            ),
            actions: [
              IconButton(
                tooltip: 'Уведомления',
                onPressed: () => _open(Section.notifications),
                icon: BadgedIcon(
                  icon: FontAwesomeIcons.bell,
                  count: counters.notificationsUnread,
                ),
              ),
              const SizedBox(width: 6),
            ],
          ),
          drawer: _Sidebar(
            session: session,
            sections: visible,
            current: section,
            onOpen: _open,
          ),
          body: _body(section),
          bottomNavigationBar: NavigationBar(
            selectedIndex: bottomIndex < 0 ? bottom.length : bottomIndex,
            onDestinationSelected: (i) {
              if (i < bottom.length) {
                _open(bottom[i]);
              } else {
                _scaffold.currentState?.openDrawer();
              }
            },
            destinations: [
              for (final s in bottom)
                NavigationDestination(
                  icon: BadgedIcon(icon: s.icon, count: s.badge(counters)),
                  label: s.shortTitle,
                ),
              NavigationDestination(
                icon: BadgedIcon(
                  icon: FontAwesomeIcons.bars,
                  count: overflowBadge,
                ),
                label: 'Ещё',
              ),
            ],
          ),
        );
      },
    );
  }
}

/// Меню как сайдбар панели: тёмный, активный пункт — розовый.
class _Sidebar extends StatelessWidget {
  const _Sidebar({
    required this.session,
    required this.sections,
    required this.current,
    required this.onOpen,
  });

  final Session session;
  final List<Section> sections;
  final Section current;
  final ValueChanged<Section> onOpen;

  Future<void> _switchClub(BuildContext context) async {
    final clubs = session.staffClubs;
    final chosen = await showDialog<ClubInfo>(
      context: context,
      builder: (context) => SimpleDialog(
        title: const Text('Сменить клуб'),
        children: [
          for (final club in clubs)
            SimpleDialogOption(
              onPressed: () => Navigator.pop(context, club),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          club.name,
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                        Text(
                          roleLabel(club.role),
                          style: TextStyle(
                            fontSize: 13,
                            color: Palette.of(context).muted,
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (club.current)
                    const FaIcon(
                      FontAwesomeIcons.check,
                      size: 14,
                      color: Brand.brand,
                    ),
                ],
              ),
            ),
        ],
      ),
    );
    if (chosen == null || chosen.current || !context.mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    Navigator.of(context).pop();
    try {
      await session.switchClub(chosen);
    } catch (e) {
      messenger.showSnackBar(
        SnackBar(backgroundColor: Brand.danger, content: Text(errorText(e))),
      );
    }
  }

  Future<void> _logout(BuildContext context) async {
    final ok = await confirm(
      context,
      title: 'Выйти из аккаунта?',
      action: 'Выйти',
      destructive: true,
    );
    if (ok) await session.logout();
  }

  @override
  Widget build(BuildContext context) {
    final me = session.me;
    final counters = session.counters;
    final workspace = sections.where((s) => s != Section.warehouse).toList();
    final name = me?.userName ?? '';
    return Drawer(
      width: 290,
      shape: const RoundedRectangleBorder(),
      child: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
              decoration: const BoxDecoration(
                border: Border(bottom: BorderSide(color: Brand.sidebarLine)),
              ),
              child: Row(
                children: [
                  const Expanded(
                    child: BrandMark(
                      height: 40,
                      subtitle: 'Управление клубом',
                      color: Colors.white,
                    ),
                  ),
                  IconButton(
                    tooltip: 'Закрыть меню',
                    onPressed: () => Navigator.of(context).pop(),
                    icon: const FaIcon(
                      FontAwesomeIcons.xmark,
                      size: 18,
                      color: Brand.sidebarIcon,
                    ),
                  ),
                ],
              ),
            ),
            if (session.clubName.isNotEmpty)
              _ClubCard(
                name: session.clubName,
                onSwitch: session.staffClubs.length > 1
                    ? () => _switchClub(context)
                    : null,
              ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 4,
                ),
                children: [
                  const _SidebarLabel('Рабочее пространство'),
                  for (final s in workspace)
                    _SidebarLink(
                      section: s,
                      active: s == current,
                      badge: s.badge(counters),
                      onTap: () => onOpen(s),
                    ),
                  if (sections.contains(Section.warehouse)) ...[
                    const _SidebarLabel('Учёт'),
                    _SidebarLink(
                      section: Section.warehouse,
                      active: current == Section.warehouse,
                      onTap: () => onOpen(Section.warehouse),
                    ),
                  ],
                ],
              ),
            ),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: const BoxDecoration(
                border: Border(top: BorderSide(color: Brand.sidebarLine)),
              ),
              child: Row(
                children: [
                  Container(
                    width: 34,
                    height: 34,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: const Color(0xFF243029),
                      borderRadius: BorderRadius.circular(9),
                    ),
                    child: Text(
                      name.isEmpty ? '?' : name.characters.first.toUpperCase(),
                      style: const TextStyle(
                        color: Color(0xFF74DF99),
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Color(0xFFEDF3EF),
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        Text(
                          roleLabel(me?.role),
                          style: const TextStyle(
                            color: Color(0xFF73847A),
                            fontSize: 13,
                          ),
                        ),
                      ],
                    ),
                  ),
                  Material(
                    color: const Color(0xFF251B1B),
                    borderRadius: BorderRadius.circular(9),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(9),
                      onTap: () => _logout(context),
                      child: const SizedBox(
                        width: 36,
                        height: 36,
                        child: Center(
                          child: FaIcon(
                            FontAwesomeIcons.arrowRightFromBracket,
                            size: 15,
                            color: Color(0xFFDF7770),
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ClubCard extends StatelessWidget {
  const _ClubCard({required this.name, this.onSwitch});

  final String name;
  final VoidCallback? onSwitch;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
      child: Material(
        color: Brand.sidebarHover,
        borderRadius: BorderRadius.circular(11),
        child: InkWell(
          borderRadius: BorderRadius.circular(11),
          onTap: onSwitch,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              children: [
                const FaIcon(
                  FontAwesomeIcons.building,
                  size: 14,
                  color: Brand.sidebarIcon,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
                if (onSwitch != null)
                  const Text(
                    'Сменить',
                    style: TextStyle(
                      color: Brand.accent,
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SidebarLabel extends StatelessWidget {
  const _SidebarLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 14, 12, 6),
      child: Text(
        text.toUpperCase(),
        style: const TextStyle(
          color: Brand.sidebarMuted,
          fontSize: 12,
          fontWeight: FontWeight.w700,
          letterSpacing: 1.6,
        ),
      ),
    );
  }
}

/// .sidebar-link: иконка в квадрате, активный пункт — розовый с тенью.
class _SidebarLink extends StatelessWidget {
  const _SidebarLink({
    required this.section,
    required this.active,
    required this.onTap,
    this.badge = 0,
  });

  final Section section;
  final bool active;
  final VoidCallback onTap;
  final int badge;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: DecoratedBox(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(11),
          boxShadow: active
              ? [
                  BoxShadow(
                    color: Brand.accent.withValues(alpha: 0.18),
                    blurRadius: 20,
                    offset: const Offset(0, 8),
                  ),
                ]
              : null,
        ),
        child: Material(
          color: active ? Brand.brand : Colors.transparent,
          borderRadius: BorderRadius.circular(11),
          child: InkWell(
            borderRadius: BorderRadius.circular(11),
            hoverColor: Brand.sidebarHover,
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
              child: Row(
                children: [
                  Container(
                    width: 28,
                    height: 28,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: active
                          ? Colors.white.withValues(alpha: 0.12)
                          : Colors.transparent,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: FaIcon(
                      section.icon,
                      size: 14,
                      color: active ? Colors.white : Brand.sidebarIcon,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      section.title,
                      style: TextStyle(
                        color: active ? Colors.white : Brand.sidebarText,
                        fontSize: 15,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ),
                  CountBadge(badge),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
