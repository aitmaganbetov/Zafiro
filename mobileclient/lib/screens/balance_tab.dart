import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../session.dart';
import '../format.dart';

class BalanceTab extends StatelessWidget {
  const BalanceTab({super.key, required this.session});

  final Session session;

  Future<void> _refresh(BuildContext context) async {
    try {
      await session.refreshProfile();
    } on ApiException catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final profile = session.profile;
    final theme = Theme.of(context);
    return RefreshIndicator(
      onRefresh: () => _refresh(context),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (profile == null)
            const Padding(
              padding: EdgeInsets.all(32),
              child: Center(
                child: Text(
                  'Не удалось загрузить баланс. Потяните вниз, чтобы обновить.',
                ),
              ),
            )
          else ...[
            Text(
              'Здравствуйте, ${profile.name}',
              style: theme.textTheme.titleLarge,
            ),
            const SizedBox(height: 16),
            _BalanceCard(
              label: 'Основной баланс',
              value: money(profile.balance, profile.currency),
              icon: Icons.account_balance_wallet,
              color: theme.colorScheme.primaryContainer,
            ),
            const SizedBox(height: 12),
            _BalanceCard(
              label: 'Бонусы',
              value: money(profile.bonusBalance, profile.currency),
              icon: Icons.card_giftcard,
              color: theme.colorScheme.tertiaryContainer,
            ),
            if (profile.discount > 0) ...[
              const SizedBox(height: 12),
              _BalanceCard(
                label: 'Ваша скидка',
                value:
                    '${profile.discount.toStringAsFixed(profile.discount % 1 == 0 ? 0 : 1)}%',
                icon: Icons.percent,
                color: theme.colorScheme.secondaryContainer,
              ),
            ],
          ],
        ],
      ),
    );
  }
}

class _BalanceCard extends StatelessWidget {
  const _BalanceCard({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      color: color,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Row(
          children: [
            Icon(icon, size: 36),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: theme.textTheme.labelLarge),
                  Text(value, style: theme.textTheme.headlineSmall),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
