import 'package:flutter/material.dart';

import '../session.dart';
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

  static const _titles = ['Баланс', 'Новости', 'История', 'Профиль'];

  @override
  Widget build(BuildContext context) {
    final session = widget.session;
    final tabs = [
      BalanceTab(session: session),
      NewsTab(api: session.api),
      HistoryTab(api: session.api),
      ProfileTab(session: session),
    ];
    return Scaffold(
      appBar: AppBar(
        title: Text(_titles[_index]),
        bottom: session.clubName.isEmpty
            ? null
            : PreferredSize(
                preferredSize: const Size.fromHeight(20),
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Text(
                    session.clubName,
                    style: Theme.of(context).textTheme.labelMedium,
                  ),
                ),
              ),
      ),
      body: IndexedStack(index: _index, children: tabs),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.account_balance_wallet_outlined),
            selectedIcon: Icon(Icons.account_balance_wallet),
            label: 'Баланс',
          ),
          NavigationDestination(
            icon: Icon(Icons.newspaper_outlined),
            selectedIcon: Icon(Icons.newspaper),
            label: 'Новости',
          ),
          NavigationDestination(icon: Icon(Icons.history), label: 'История'),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            selectedIcon: Icon(Icons.person),
            label: 'Профиль',
          ),
        ],
      ),
    );
  }
}
