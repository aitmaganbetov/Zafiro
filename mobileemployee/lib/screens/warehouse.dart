import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';

/// Остаток ниже этого числа подсвечивается как «заканчивается».
const lowStockThreshold = 5;

class WarehouseScreen extends StatefulWidget {
  const WarehouseScreen({super.key, required this.session});

  final Session session;

  @override
  State<WarehouseScreen> createState() => _WarehouseScreenState();
}

class _WarehouseScreenState extends State<WarehouseScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  final List<Product> _items = [];
  int _page = 1;
  bool _hasMore = false;
  bool _loading = false;
  bool _loaded = false;
  String? _error;
  Timer? _debounce;

  Permissions get _can => widget.session.permissions;

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
      final page = await widget.session.api.products(
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

  void _onSearch(String _) {
    _debounce?.cancel();
    _debounce = Timer(
      const Duration(milliseconds: 400),
      () => _load(reset: true),
    );
  }

  Future<void> _open(Product product) async {
    if (!_can.stockReceive && !_can.stockManage) return;
    final quantity = await showSheet<int>(
      context,
      (_) => _StockSheet(product: product, session: widget.session),
    );
    if (quantity == null || !mounted) return;
    setState(() {
      final i = _items.indexWhere((p) => p.id == product.id);
      if (i >= 0) _items[i] = _items[i].withQuantity(quantity);
    });
  }

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    final currency = widget.session.currency;
    final canChange = _can.stockReceive || _can.stockManage;
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: SearchField(
            controller: _search,
            hint: 'Поиск товара',
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
                          icon: FontAwesomeIcons.boxesStacked,
                          text: _error ?? 'Товары не найдены.',
                        );
                      }
                      if (i >= _items.length) {
                        return const Padding(
                          padding: EdgeInsets.all(16),
                          child: Center(child: CircularProgressIndicator()),
                        );
                      }
                      final product = _items[i];
                      final stockColor = product.quantity <= 0
                          ? Brand.danger
                          : product.quantity <= lowStockThreshold
                          ? Brand.warning
                          : Brand.success;
                      return Panel(
                        padding: const EdgeInsets.all(12),
                        onTap: canChange ? () => _open(product) : null,
                        child: Row(
                          children: [
                            ProductThumb(product),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    product.name,
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                  Text(
                                    [
                                      if (product.category.isNotEmpty)
                                        product.category,
                                      if (product.sku.isNotEmpty) product.sku,
                                      money(product.price, currency),
                                    ].join(' · '),
                                    style: TextStyle(
                                      color: p.muted,
                                      fontSize: 13,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(width: 8),
                            StatusPill(
                              '${product.quantity} шт.',
                              color: stockColor,
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

/// Приход товара (stock_receive) или приход/списание (stock_manage).
/// Возвращает новый остаток.
class _StockSheet extends StatefulWidget {
  const _StockSheet({required this.product, required this.session});

  final Product product;
  final Session session;

  @override
  State<_StockSheet> createState() => _StockSheetState();
}

class _StockSheetState extends State<_StockSheet> {
  final _quantity = TextEditingController();
  final _note = TextEditingController();
  String _type = 'receipt';
  bool _busy = false;
  String? _error;

  bool get _manage => widget.session.permissions.stockManage;

  @override
  void dispose() {
    _quantity.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final quantity = int.tryParse(_quantity.text.trim()) ?? 0;
    if (quantity <= 0) {
      setState(() => _error = 'Укажите количество');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final api = widget.session.api;
    try {
      final result = _manage
          ? await api.stockMovement(
              widget.product.id,
              _type,
              quantity,
              _note.text,
            )
          : await api.stockReceive(widget.product.id, quantity, _note.text);
      if (!mounted) return;
      showMessage(
        context,
        '${_type == 'write_off' ? 'Списано' : 'Принято'}: $quantity шт. Остаток: ${result.quantity} шт.',
      );
      Navigator.of(context).pop(result.quantity);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error = e.message;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final product = widget.product;
    return SheetBody(
      title: product.name,
      subtitle: 'На складе: ${product.quantity} шт.',
      children: [
        if (_manage) ...[
          ChoiceRow<String>(
            options: const {'receipt': 'Приход', 'write_off': 'Списание'},
            value: _type,
            onChanged: (v) => setState(() => _type = v),
          ),
          const SizedBox(height: 14),
        ],
        TextField(
          controller: _quantity,
          autofocus: true,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          decoration: const InputDecoration(
            labelText: 'Количество',
            suffixText: 'шт.',
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _note,
          maxLength: 255,
          decoration: const InputDecoration(
            labelText: 'Комментарий (необязательно)',
          ),
        ),
        if (_error != null) ...[const SizedBox(height: 4), ErrorBox(_error!)],
        const SizedBox(height: 12),
        BusyButton(
          label: _type == 'write_off' ? 'Списать' : 'Принять товар',
          icon: _type == 'write_off'
              ? FontAwesomeIcons.trashCan
              : FontAwesomeIcons.dolly,
          busy: _busy,
          onPressed: _submit,
        ),
      ],
    );
  }
}
