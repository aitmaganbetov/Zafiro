import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';

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
      setState(() {
        if (reset) _entries.clear();
        _entries.addAll(page.entries);
        _nextBefore = page.nextBefore;
        _loaded = true;
      });
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hasMore = _nextBefore != null;
    return RefreshIndicator(
      onRefresh: () => _load(reset: true),
      child: ListView.builder(
        itemCount: _entries.length + 1,
        itemBuilder: (context, i) {
          if (i < _entries.length) {
            final e = _entries[i];
            final amount = e.amount ?? e.bonusAmount;
            return ListTile(
              title: Text(e.title),
              subtitle: Text([dateTime(e.at), ?e.note].join(' · ')),
              trailing: amount == null
                  ? null
                  : Text(
                      '${amount > 0 ? '+' : ''}${amount.toStringAsFixed(0)}${e.amount == null ? ' б.' : ''}',
                      style: TextStyle(
                        color: amount >= 0
                            ? Colors.green
                            : theme.colorScheme.error,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
            );
          }
          if (_error != null) {
            return Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                children: [
                  Text(_error!, textAlign: TextAlign.center),
                  TextButton(
                    onPressed: () => _load(reset: !_loaded),
                    child: const Text('Повторить'),
                  ),
                ],
              ),
            );
          }
          if (_loading) {
            return const Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          if (_entries.isEmpty) {
            return const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: Text('Операций пока нет')),
            );
          }
          return hasMore
              ? Padding(
                  padding: const EdgeInsets.all(16),
                  child: OutlinedButton(
                    onPressed: _load,
                    child: const Text('Показать ещё'),
                  ),
                )
              : const SizedBox(height: 16);
        },
      ),
    );
  }
}
