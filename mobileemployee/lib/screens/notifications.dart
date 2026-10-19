import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';

/// Иконка уведомления по классу Font Awesome с сайта («fa-bag-shopping» и т. п.).
FaIconData notificationIcon(String icon) {
  const known = <String, FaIconData>{
    'bag-shopping': FontAwesomeIcons.bagShopping,
    'cart': FontAwesomeIcons.cartShopping,
    'comment': FontAwesomeIcons.comments,
    'cash': FontAwesomeIcons.cashRegister,
    'wallet': FontAwesomeIcons.wallet,
    'money': FontAwesomeIcons.moneyBillWave,
    'box': FontAwesomeIcons.boxesStacked,
    'computer': FontAwesomeIcons.computer,
    'desktop': FontAwesomeIcons.desktop,
    'calendar': FontAwesomeIcons.calendarCheck,
    'user': FontAwesomeIcons.user,
    'credit-card': FontAwesomeIcons.creditCard,
    'triangle-exclamation': FontAwesomeIcons.triangleExclamation,
    'exclamation': FontAwesomeIcons.circleExclamation,
    'newspaper': FontAwesomeIcons.newspaper,
    'gift': FontAwesomeIcons.gift,
  };
  for (final e in known.entries) {
    if (icon.contains(e.key)) return e.value;
  }
  return FontAwesomeIcons.bell;
}

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.session});

  final Session session;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  NotificationList? _data;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await widget.session.api.notifications();
      if (!mounted) return;
      setState(() {
        _data = data;
        _error = null;
      });
      // Открыли список — непрочитанные считаются просмотренными (как колокольчик на сайте).
      if (data.unreadCount > 0) await _markSeen();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _markSeen() async {
    try {
      await widget.session.api.notificationsSeen();
      widget.session.updateCounters(notificationsUnread: 0);
    } on ApiException catch (e) {
      if (mounted) showError(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    final p = Palette.of(context);
    return LoadView(
      loading: data == null,
      error: _error,
      onRetry: _load,
      child: data == null
          ? null
          : RefreshIndicator(
              onRefresh: _load,
              child: data.items.isEmpty
                  ? ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      children: const [
                        EmptyState(
                          icon: FontAwesomeIcons.bellSlash,
                          text: 'Уведомлений нет.',
                        ),
                      ],
                    )
                  : ListView.separated(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.all(16),
                      itemCount: data.items.length,
                      separatorBuilder: (_, _) => const SizedBox(height: 8),
                      itemBuilder: (context, i) {
                        final n = data.items[i];
                        return Panel(
                          padding: const EdgeInsets.all(14),
                          color: n.unread
                              ? Brand.brand.withValues(
                                  alpha: p.dark ? 0.12 : 0.04,
                                )
                              : null,
                          borderColor: n.unread
                              ? Brand.brand.withValues(alpha: 0.4)
                              : null,
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              IconBox(
                                notificationIcon(n.icon),
                                size: 36,
                                color: n.unread ? Brand.brand : p.muted,
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      n.title,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                    if (n.body.isNotEmpty) ...[
                                      const SizedBox(height: 2),
                                      Text(
                                        n.body,
                                        style: TextStyle(
                                          color: p.muted,
                                          fontSize: 13,
                                        ),
                                      ),
                                    ],
                                    const SizedBox(height: 4),
                                    Text(
                                      n.atHuman.isNotEmpty
                                          ? n.atHuman
                                          : dateTime(n.at),
                                      style: TextStyle(
                                        color: p.faint,
                                        fontSize: 12,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        );
                      },
                    ),
            ),
    );
  }
}
