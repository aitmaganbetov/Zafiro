import 'dart:async';

import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';
import 'cash.dart';

class CustomersScreen extends StatefulWidget {
  const CustomersScreen({super.key, required this.session});

  final Session session;

  @override
  State<CustomersScreen> createState() => _CustomersScreenState();
}

class _CustomersScreenState extends State<CustomersScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  final List<Customer> _items = [];
  int _page = 1;
  bool _hasMore = false;
  bool _loading = false;
  bool _loaded = false;
  String? _error;
  Timer? _debounce;

  String get _currency => widget.session.currency;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_hasMore &&
          !_loading &&
          _scroll.position.pixels > _scroll.position.maxScrollExtent - 300) {
        _load();
      }
    });
    _load(reset: true);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load({bool reset = false}) async {
    if (_loading && !reset) return;
    final query = _search.text.trim();
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await widget.session.api.customers(
        search: query,
        page: reset ? 1 : _page + 1,
      );
      if (!mounted || query != _search.text.trim()) return;
      setState(() {
        if (reset) _items.clear();
        _items.addAll(page.items);
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _loaded = true;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _onSearch(String text) {
    _debounce?.cancel();
    final query = text.trim();
    // Сервер ищет от двух символов; один символ — ждём дальше.
    if (query.length == 1) return;
    _debounce = Timer(
      const Duration(milliseconds: 400),
      () => _load(reset: true),
    );
  }

  Future<void> _open(Customer customer) async {
    final topUp = await showSheet<bool>(
      context,
      (_) => _CustomerSheet(
        customer: customer,
        currency: _currency,
        canTopUp: widget.session.permissions.cash,
      ),
    );
    if (topUp != true || !mounted) return;
    final changed = await showSheet<bool>(
      context,
      (_) => TopUpSheet(session: widget.session, customer: customer),
    );
    if (changed == true) _load(reset: true);
  }

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: SearchField(
            controller: _search,
            hint: 'Поиск по имени или телефону',
            onChanged: _onSearch,
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => _load(reset: true),
            child: !_loaded && _items.isEmpty
                ? LoadView(
                    loading: _loading,
                    error: _error,
                    onRetry: () => _load(reset: true),
                    child: null,
                  )
                : ListView.separated(
                    controller: _scroll,
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
                    itemCount: _items.isEmpty
                        ? 1
                        : _items.length + (_hasMore ? 1 : 0),
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      if (_items.isEmpty) {
                        return EmptyState(
                          icon: FontAwesomeIcons.users,
                          text: _error ?? 'Пользователь не найден',
                        );
                      }
                      if (i >= _items.length) {
                        return const Padding(
                          padding: EdgeInsets.all(16),
                          child: Center(child: CircularProgressIndicator()),
                        );
                      }
                      final c = _items[i];
                      return Panel(
                        padding: const EdgeInsets.all(14),
                        onTap: () => _open(c),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        c.name,
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                      Text(
                                        formatPhone(c.phone),
                                        style: TextStyle(
                                          color: p.muted,
                                          fontSize: 13,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                Text(
                                  money(c.balance, _currency),
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w700,
                                    fontSize: 16,
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 8),
                            Wrap(
                              spacing: 6,
                              runSpacing: 6,
                              children: [
                                if (c.bonusBalance > 0)
                                  StatusPill(
                                    'Бонусы ${money(c.bonusBalance, _currency)}',
                                    color: Brand.info,
                                    icon: FontAwesomeIcons.gift,
                                  ),
                                if (c.discount > 0)
                                  StatusPill(
                                    discountLabel(c),
                                    color: Brand.success,
                                    icon: FontAwesomeIcons.percent,
                                  ),
                                StatusPill(
                                  'Потрачено ${money(c.totalSpent, _currency)}',
                                  color: p.muted,
                                ),
                              ],
                            ),
                          ],
                        ),
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }
}

String discountLabel(Customer c) =>
    'Скидка ${number(c.discount)}%${c.discountCumulative ? ' · накопительная' : ''}';

class _CustomerSheet extends StatelessWidget {
  const _CustomerSheet({
    required this.customer,
    required this.currency,
    required this.canTopUp,
  });

  final Customer customer;
  final String currency;
  final bool canTopUp;

  @override
  Widget build(BuildContext context) {
    return SheetBody(
      title: customer.name,
      subtitle: formatPhone(customer.phone),
      children: [
        InfoRow(
          'Основной баланс',
          money(customer.balance, currency),
          bold: true,
        ),
        InfoRow('Бонусный баланс', money(customer.bonusBalance, currency)),
        InfoRow(
          'Скидка',
          customer.discount > 0
              ? '${number(customer.discount)}%${customer.discountCumulative ? ' (накопительная)' : ''}'
              : 'нет',
        ),
        InfoRow('Всего потрачено', money(customer.totalSpent, currency)),
        const SizedBox(height: 16),
        if (canTopUp)
          FilledButton.icon(
            onPressed: () => Navigator.of(context).pop(true),
            icon: const FaIcon(FontAwesomeIcons.wallet, size: 15),
            label: const Text('Пополнить баланс'),
          ),
      ],
    );
  }
}
