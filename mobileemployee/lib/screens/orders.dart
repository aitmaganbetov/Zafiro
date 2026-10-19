import 'dart:async';

import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';

const _statusTabs = {
  'new': 'Новые',
  'done': 'Выданные',
  'cancelled': 'Отменённые',
  'all': 'Все',
};

(String, Color) orderStatus(String status) => switch (status) {
  'new' => ('Новый', Brand.warning),
  'done' => ('Выдан', Brand.success),
  'cancelled' => ('Отменён', Brand.pcOffline),
  _ => (status, Brand.pcOffline),
};

/// Заказы клиентов из агента на ПК: отнести к ПК и отметить «Выдан».
class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key, required this.session});

  final Session session;

  @override
  State<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends State<OrdersScreen> {
  String _status = 'new';
  final List<ShopOrder> _orders = [];
  int _page = 1;
  bool _hasMore = false;
  bool _loading = false;
  bool _loaded = false;
  String? _error;
  final Set<int> _busy = {};
  Timer? _timer;

  ApiClient get _api => widget.session.api;
  String get _currency => widget.session.currency;

  @override
  void initState() {
    super.initState();
    _load(reset: true);
    // Новые заказы приходят с ПК — обновляем первую страницу.
    _timer = Timer.periodic(const Duration(seconds: 20), (_) {
      if (_page == 1 && _busy.isEmpty) _load(reset: true, silent: true);
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load({bool reset = false, bool silent = false}) async {
    if (_loading) return;
    final status = _status;
    _loading = true;
    if (!silent) setState(() => _error = null);
    try {
      final page = await _api.orders(
        status: status,
        page: reset ? 1 : _page + 1,
      );
      if (!mounted || status != _status) return;
      setState(() {
        if (reset) _orders.clear();
        _orders.addAll(page.items);
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _loaded = true;
      });
      final newCount = page.extra['new_count'];
      if (newCount != null) {
        widget.session.updateCounters(ordersNew: toInt(newCount));
      }
    } on ApiException catch (e) {
      if (mounted && !silent) setState(() => _error = e.message);
    } finally {
      _loading = false;
    }
  }

  void _setStatus(String status) {
    if (status == _status) return;
    setState(() {
      _status = status;
      _orders.clear();
      _loaded = false;
      _page = 1;
      _hasMore = false;
    });
    _loading = false;
    _load(reset: true);
  }

  Future<void> _handle(ShopOrder order, bool done) async {
    if (!done &&
        !await confirm(
          context,
          title: 'Отменить заказ №${order.id}?',
          text: 'Деньги вернутся на счёт клиента, товар — на склад.',
          action: 'Отменить заказ',
          destructive: true,
        )) {
      return;
    }
    setState(() => _busy.add(order.id));
    try {
      final updated = done
          ? await _api.orderDone(order.id)
          : await _api.orderCancel(order.id);
      if (!mounted) return;
      showMessage(
        context,
        done ? 'Заказ №${order.id} выдан' : 'Заказ №${order.id} отменён',
      );
      setState(() {
        final i = _orders.indexWhere((o) => o.id == order.id);
        if (i >= 0) {
          if (_status == 'all') {
            _orders[i] = updated.id == order.id ? updated : _orders[i];
          } else {
            _orders.removeAt(i);
          }
        }
      });
      widget.session.refresh();
    } on ApiException catch (e) {
      if (mounted) {
        showError(context, e);
        _load(reset: true, silent: true);
      }
    } finally {
      if (mounted) setState(() => _busy.remove(order.id));
    }
  }

  @override
  Widget build(BuildContext context) {
    final newCount = widget.session.counters.ordersNew;
    return Column(
      children: [
        SizedBox(
          height: 56,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            children: [
              for (final e in _statusTabs.entries)
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ChoiceChip(
                    selected: _status == e.key,
                    onSelected: (_) => _setStatus(e.key),
                    labelStyle: TextStyle(
                      color: _status == e.key ? Colors.white : null,
                      fontWeight: FontWeight.w600,
                    ),
                    label: Text(
                      e.key == 'new' && newCount > 0
                          ? '${e.value} · $newCount'
                          : e.value,
                    ),
                  ),
                ),
            ],
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => _load(reset: true),
            child: !_loaded
                ? LoadView(
                    loading: true,
                    error: _error,
                    onRetry: () => _load(reset: true),
                    child: null,
                  )
                : ListView.separated(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                    itemCount: _orders.isEmpty
                        ? 1
                        : _orders.length + (_hasMore ? 1 : 0),
                    separatorBuilder: (_, _) => const SizedBox(height: 10),
                    itemBuilder: (context, i) {
                      if (_orders.isEmpty) {
                        return EmptyState(
                          icon: FontAwesomeIcons.bagShopping,
                          text: _status == 'new'
                              ? 'Новых заказов нет.'
                              : 'Заказов нет.',
                        );
                      }
                      if (i >= _orders.length) {
                        return Center(
                          child: TextButton(
                            onPressed: () => _load(),
                            child: const Text('Показать ещё'),
                          ),
                        );
                      }
                      final order = _orders[i];
                      return _OrderCard(
                        order: order,
                        currency: _currency,
                        busy: _busy.contains(order.id),
                        onDone: () => _handle(order, true),
                        onCancel: () => _handle(order, false),
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({
    required this.order,
    required this.currency,
    required this.busy,
    required this.onDone,
    required this.onCancel,
  });

  final ShopOrder order;
  final String currency;
  final bool busy;
  final VoidCallback onDone;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    final (label, color) = orderStatus(order.status);
    return Panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const IconBox(FontAwesomeIcons.desktop, size: 36),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      order.computerName.isEmpty
                          ? 'Заказ №${order.id}'
                          : order.computerName,
                      style: const TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 16,
                      ),
                    ),
                    Text(
                      '№${order.id} · ${dateTime(order.createdAt)}',
                      style: TextStyle(color: p.muted, fontSize: 13),
                    ),
                  ],
                ),
              ),
              StatusPill(label, color: color),
            ],
          ),
          if (order.customerName.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(
              [
                order.customerName,
                if (order.customerPhone.isNotEmpty)
                  formatPhone(order.customerPhone),
              ].join(' · '),
              style: TextStyle(color: p.muted, fontSize: 13),
            ),
          ],
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: BoxDecoration(
              color: p.subtle,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Column(
              children: [
                for (final item in order.items)
                  InfoRow(
                    '${item.name} × ${item.quantity}',
                    money(item.total, currency),
                  ),
                InfoRow(
                  'Итого, оплачено с баланса',
                  money(order.amount, currency),
                  bold: true,
                ),
              ],
            ),
          ),
          if (!order.isNew && order.handledBy.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(
              '${order.handledBy}${order.handledAt == null ? '' : ' · ${dateTime(order.handledAt)}'}',
              style: TextStyle(color: p.faint, fontSize: 12),
            ),
          ],
          if (order.isNew) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: busy ? null : onCancel,
                    child: const Text('Отменить'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: BusyButton(
                    label: 'Выдан',
                    icon: FontAwesomeIcons.check,
                    busy: busy,
                    onPressed: onDone,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
