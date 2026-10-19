import 'package:flutter/material.dart';

import '../session.dart';

class ProfileTab extends StatelessWidget {
  const ProfileTab({super.key, required this.session});

  final Session session;

  Future<void> _logout(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Выйти из аккаунта?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Отмена'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Выйти'),
          ),
        ],
      ),
    );
    if (confirmed == true) await session.logout();
  }

  @override
  Widget build(BuildContext context) {
    final profile = session.profile;
    return ListView(
      children: [
        ListTile(
          leading: const Icon(Icons.person),
          title: Text(profile?.name ?? '—'),
          subtitle: const Text('Имя'),
        ),
        ListTile(
          leading: const Icon(Icons.phone),
          title: Text(profile?.phone ?? '—'),
          subtitle: const Text('Телефон'),
        ),
        if (session.clubName.isNotEmpty)
          ListTile(
            leading: const Icon(Icons.store),
            title: Text(session.clubName),
            subtitle: const Text('Клуб'),
          ),
        const Divider(),
        ListTile(
          leading: const Icon(Icons.logout),
          title: const Text('Выйти'),
          onTap: () => _logout(context),
        ),
      ],
    );
  }
}
