import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../session.dart';
import '../theme.dart';
import '../widgets.dart';
import 'balance_tab.dart';
import 'history_tab.dart';
import 'news_tab.dart';
import 'profile_tab.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.session});

  final Session session;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final session = widget.session;
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 20,
        title: const BrandMark(),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1),
          child: Container(
            height: 1,
            color: Colors.white.withValues(alpha: 0.05),
          ),
        ),
        actions: [
          if (session.clubName.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(right: 16),
              child: _ClubChip(session.clubName),
            ),
        ],
      ),
      body: IndexedStack(
        index: _index,
        children: [
          BalanceTab(session: session),
          NewsTab(api: session.api),
          HistoryTab(api: session.api),
          ProfileTab(session: session),
        ],
      ),
      bottomNavigationBar: DecoratedBox(
        decoration: BoxDecoration(
          border: Border(
            top: BorderSide(color: Colors.white.withValues(alpha: 0.05)),
          ),
        ),
        child: NavigationBar(
          selectedIndex: _index,
          onDestinationSelected: (i) => setState(() => _index = i),
          destinations: const [
            NavigationDestination(
              icon: FaIcon(FontAwesomeIcons.wallet),
              label: 'Баланс',
            ),
            NavigationDestination(
              icon: FaIcon(FontAwesomeIcons.newspaper),
              label: 'Новости',
            ),
            NavigationDestination(
              icon: FaIcon(FontAwesomeIcons.clockRotateLeft),
              label: 'История',
            ),
            NavigationDestination(
              icon: FaIcon(FontAwesomeIcons.user),
              label: 'Профиль',
            ),
          ],
        ),
      ),
    );
  }
}

/// Плашка клуба, как «Сейчас занято…» на сайте: rounded-full bg-white/5.
class _ClubChip extends StatelessWidget {
  const _ClubChip(this.name);

  final String name;

  @override
  Widget build(BuildContext context) {
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 160),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.05),
          borderRadius: BorderRadius.circular(999),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 8,
              height: 8,
              decoration: const BoxDecoration(
                color: Brand.accent,
                shape: BoxShape.circle,
              ),
            ),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                name,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: 12,
                  color: Brand.muted,
                  fontWeight: FontWeight.w500,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
