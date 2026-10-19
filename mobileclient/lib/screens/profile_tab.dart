import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../session.dart';
import '../theme.dart';
import '../widgets.dart';

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
            style: const ButtonStyle(
              minimumSize: WidgetStatePropertyAll(Size(96, 44)),
            ),
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
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
      children: [
        SectionHeading(kicker: 'Профиль', title: profile?.name ?? '—'),
        const SizedBox(height: 20),
        GlassCard(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Column(
            children: [
              _Row(
                icon: FontAwesomeIcons.user,
                label: 'Имя',
                value: profile?.name ?? '—',
              ),
              const Divider(indent: 72),
              _Row(
                icon: FontAwesomeIcons.phone,
                label: 'Телефон',
                value: profile?.phone ?? '—',
              ),
              if (session.clubName.isNotEmpty) ...[
                const Divider(indent: 72),
                _Row(
                  icon: FontAwesomeIcons.store,
                  label: 'Клуб',
                  value: session.clubName,
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 20),
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(
            foregroundColor: Brand.danger,
            side: BorderSide(color: Brand.danger.withValues(alpha: 0.4)),
            minimumSize: const Size.fromHeight(52),
          ),
          onPressed: () => _logout(context),
          icon: const FaIcon(FontAwesomeIcons.rightFromBracket, size: 16),
          label: const Text(
            'Выйти',
            style: TextStyle(fontWeight: FontWeight.w600),
          ),
        ),
      ],
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.icon, required this.label, required this.value});

  final FaIconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          IconBadge(icon, size: 40),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: TextStyle(color: Brand.faint, fontSize: 12)),
                const SizedBox(height: 2),
                Text(
                  value,
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
