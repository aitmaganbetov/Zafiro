import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../theme.dart';
import '../widgets.dart';

/// Иконки событий — те же, что у разделов на сайте (тарифы — секундомер, магазин — пакет…).
FaIconData historyIcon(String kind) => switch (kind) {
  'tariff.purchased' => FontAwesomeIcons.stopwatch,
  'time.purchased' => FontAwesomeIcons.hourglassHalf,
  'shop.purchased' => FontAwesomeIcons.bagShopping,
  'shop.order_cancelled' => FontAwesomeIcons.rotateLeft,
  'user.balance_topped_up' => FontAwesomeIcons.circlePlus,
  'balance.transferred' => FontAwesomeIcons.userGroup,
  'session.transferred' => FontAwesomeIcons.desktop,
  'auth.qr_login' => FontAwesomeIcons.qrcode,
  'auth.api_login' => FontAwesomeIcons.rightToBracket,
  _ => FontAwesomeIcons.receipt,
};

class HistoryTab extends StatefulWidget {
  const HistoryTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<HistoryTab> createState() => _HistoryTabState();
}

class _HistoryTabState extends State<HistoryTab> {
  final List<HistoryEntry> _entries = [];
  int? _nextBefore;
  bool _loading = false;
  bool _loaded = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load(reset: true);
  }

  Future<void> _load({bool reset = false}) async {
    if (_loading) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await widget.api.history(before: reset ? null : _nextBefore);
      if (!mounted) return;
      setState(() {
        if (reset) _entries.clear();
        _entries.addAll(page.entries);
        _nextBefore = page.nextBefore;
        _loaded = true;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () => _load(reset: true),
      child: ListView.builder(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
        itemCount: _entries.length + 2,
        itemBuilder: (context, i) {
          if (i == 0) {
            return const Padding(
              padding: EdgeInsets.only(bottom: 20),
              child: SectionHeading(kicker: 'История', title: 'Ваши операции'),
            );
          }
          if (i <= _entries.length) {
            return Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: _EntryCard(entry: _entries[i - 1]),
            );
          }
          if (_error != null) {
            return EmptyState(
              icon: FontAwesomeIcons.circleExclamation,
              text: _error!,
              onRetry: () => _load(reset: !_loaded),
            );
          }
          if (_loading) {
            return const Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          if (_entries.isEmpty) {
            return const EmptyState(
              icon: FontAwesomeIcons.clockRotateLeft,
              text: 'Операций пока нет',
            );
          }
          return _nextBefore != null
              ? Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: OutlinedButton(
                    onPressed: _load,
                    child: const Text('Показать ещё'),
                  ),
                )
              : const SizedBox.shrink();
        },
      ),
    );
  }
}

class _EntryCard extends StatelessWidget {
  const _EntryCard({required this.entry});

  final HistoryEntry entry;

  @override
  Widget build(BuildContext context) {
    final amount = entry.amount ?? entry.bonusAmount;
    final subtitle = [dateTime(entry.at), ?entry.note].join(' · ');
    return GlassCard(
      padding: const EdgeInsets.all(14),
      child: Row(
        children: [
          IconBadge(historyIcon(entry.kind), size: 42),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  entry.title,
                  style: const TextStyle(
                    fontWeight: FontWeight.w600,
                    height: 1.3,
                  ),
                ),
                if (subtitle.isNotEmpty) ...[
                  const SizedBox(height: 3),
                  Text(
                    subtitle,
                    style: TextStyle(color: Brand.faint, fontSize: 12),
                  ),
                ],
              ],
            ),
          ),
          if (amount != null) ...[
            const SizedBox(width: 10),
            Text(
              '${signedAmount(amount)}${entry.amount == null ? ' б.' : ''}',
              style: TextStyle(
                color: amount >= 0 ? Brand.success : Brand.accentLight,
                fontWeight: FontWeight.w700,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
          ],
        ],
      ),
    );
  }
}
