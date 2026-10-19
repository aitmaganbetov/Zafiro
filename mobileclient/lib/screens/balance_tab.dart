import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';
import 'scan_screen.dart';

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
    return RefreshIndicator(
      onRefresh: () => _refresh(context),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
        children: [
          if (profile == null)
            EmptyState(
              icon: FontAwesomeIcons.wallet,
              text: 'Не удалось загрузить баланс',
              onRetry: () => _refresh(context),
            )
          else ...[
            SectionHeading(
              kicker: 'Баланс',
              title: 'Здравствуйте, ${profile.name}',
            ),
            const SizedBox(height: 20),
            _MainBalance(amount: money(profile.balance, profile.currency)),
            const SizedBox(height: 14),
            GlassCard(
              padding: const EdgeInsets.all(16),
              onTap: () => openScanner(context, session),
              child: Row(
                children: [
                  const IconBadge(FontAwesomeIcons.qrcode, size: 44),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Войти на ПК',
                          style: TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Отсканируйте QR-код на экране ПК',
                          style: TextStyle(color: Brand.faint, fontSize: 13),
                        ),
                      ],
                    ),
                  ),
                  FaIcon(
                    FontAwesomeIcons.chevronRight,
                    size: 14,
                    color: Brand.faint,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                Expanded(
                  child: _Stat(
                    icon: FontAwesomeIcons.gift,
                    label: 'Бонусы',
                    value: money(profile.bonusBalance, profile.currency),
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: _Stat(
                    icon: FontAwesomeIcons.percent,
                    label: 'Скидка',
                    value:
                        '${profile.discount.toStringAsFixed(profile.discount % 1 == 0 ? 0 : 1)}%',
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),
            Center(
              child: TextButton.icon(
                onPressed: () => _refresh(context),
                icon: const FaIcon(FontAwesomeIcons.arrowsRotate, size: 14),
                label: const Text('Обновить баланс'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Основной баланс в карточке со свечением — как блок «Карта клуба» на сайте.
class _MainBalance extends StatelessWidget {
  const _MainBalance({required this.amount});

  final String amount;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: Brand.border),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            // Непрозрачные цвета: иначе сквозь карточку видна её тень.
            Color.alphaBlend(
              Brand.accent.withValues(alpha: 0.28),
              Brand.surface,
            ),
            Brand.surface,
          ],
          stops: const [0, 0.7],
        ),
        boxShadow: [
          BoxShadow(
            color: Brand.accent.withValues(alpha: 0.18),
            blurRadius: 40,
            spreadRadius: -10,
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const IconBadge(
                FontAwesomeIcons.wallet,
                size: 40,
                color: Colors.white,
              ),
              const SizedBox(width: 12),
              Text(
                'Основной баланс',
                style: TextStyle(
                  color: Brand.muted,
                  fontWeight: FontWeight.w500,
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              amount,
              style: const TextStyle(
                fontSize: 40,
                fontWeight: FontWeight.w800,
                fontFeatures: [FontFeature.tabularFigures()],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.icon, required this.label, required this.value});

  final FaIconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconBadge(icon, size: 38),
          const SizedBox(height: 14),
          Text(label, style: TextStyle(color: Brand.faint, fontSize: 13)),
          const SizedBox(height: 2),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              value,
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}

/// Сканер входа на ПК; после входа баланс мог измениться — обновляем.
Future<void> openScanner(BuildContext context, Session session) async {
  await Navigator.of(
    context,
  ).push(MaterialPageRoute<void>(builder: (_) => ScanScreen(api: session.api)));
  try {
    await session.refreshProfile();
  } on ApiException {
    // Баланс обновится при следующем открытии.
  }
}
