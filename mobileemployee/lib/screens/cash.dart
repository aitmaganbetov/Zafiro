import 'dart:async';

import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';

class CashScreen extends StatefulWidget {
  const CashScreen({super.key, required this.session});

  final Session session;

  @override
  State<CashScreen> createState() => _CashScreenState();
}

class _CashScreenState extends State<CashScreen> {
  CashOverview? _data;
  String? _error;
  final _opening = TextEditingController(text: '0');
  bool _busy = false;

  ApiClient get _api => widget.session.api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _opening.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final data = await _api.cash();
      if (mounted) {
        setState(() {
          _data = data;
          _error = null;
        });
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  /// После операции: касса и смена в профиле (гостевые сессии её проверяют).
  Future<void> _reload() async {
    await _load();
    await widget.session.refresh();
  }

  Future<void> _open() async {
    final amount = parseAmount(_opening.text);
    if (amount == null || amount < 0) {
      showError(context, ApiException(422, 'Укажите начальный остаток'));
      return;
    }
    setState(() => _busy = true);
    try {
      await _api.openShift(amount);
      if (mounted) showMessage(context, 'Смена открыта');
      await _reload();
    } on ApiException catch (e) {
      if (mounted) showError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _close(CashOverview data) async {
    final shift = data.shift;
    if (shift == null) return;
    final ok = await confirm(
      context,
      title: 'Закрыть смену?',
      text:
          'В кассе: ${money(data.totals?.balance ?? 0, data.currency)}. '
          'После закрытия будет показан Z-отчёт.',
      action: 'Закрыть смену',
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      final report = await _api.closeShift(shift.id);
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) =>
              ZReportScreen(report: report, currency: data.currency),
        ),
      );
      await _reload();
    } on ApiException catch (e) {
      if (mounted) showError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _topUp() async {
    final changed = await showSheet<bool>(
      context,
      (_) => TopUpSheet(session: widget.session),
    );
    if (changed == true) await _reload();
  }

  Future<void> _sale() async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => SaleScreen(session: widget.session)),
    );
    if (changed == true) await _reload();
  }

  Future<void> _move() async {
    final changed = await showSheet<bool>(
      context,
      (_) => CashMoveSheet(session: widget.session),
    );
    if (changed == true) await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    return LoadView(
      loading: data == null,
      error: _error,
      onRetry: _load,
      child: data == null
          ? null
          : RefreshIndicator(
              onRefresh: _reload,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                children: data.shift == null ? _closed(data) : _opened(data),
              ),
            ),
    );
  }

  List<Widget> _closed(CashOverview data) {
    final p = Palette.of(context);
    return [
      Panel(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Row(
              children: [
                IconBox(FontAwesomeIcons.cashRegister),
                SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'Смена закрыта',
                    style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              'Откройте смену, чтобы принимать оплату: пополнения, продажи и гостевые сессии.',
              style: TextStyle(color: p.muted, height: 1.4),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _opening,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              decoration: InputDecoration(
                labelText: 'Начальный остаток',
                suffixText: currencySymbol(data.currency),
              ),
            ),
            const SizedBox(height: 14),
            BusyButton(
              label: 'Открыть смену',
              icon: FontAwesomeIcons.lockOpen,
              busy: _busy,
              onPressed: _open,
            ),
          ],
        ),
      ),
    ];
  }

  List<Widget> _opened(CashOverview data) {
    final shift = data.shift!;
    final totals = data.totals ?? const CashTotals();
    final c = data.currency;
    final p = Palette.of(context);
    return [
      Panel(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const StatusPill('Смена открыта', color: Brand.success),
                const Spacer(),
                Text(
                  'Смена №${shift.id} · с ${dateTime(shift.openedAt)}',
                  style: TextStyle(color: p.muted, fontSize: 13),
                ),
              ],
            ),
            const SizedBox(height: 14),
            Text('В кассе', style: TextStyle(color: p.muted)),
            const SizedBox(height: 2),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                money(totals.balance, c),
                style: const TextStyle(
                  fontSize: 32,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            const SizedBox(height: 4),
            Text(
              'Начальный остаток: ${money(shift.openingBalance, c)}',
              style: TextStyle(color: p.muted, fontSize: 13),
            ),
          ],
        ),
      ),
      const SizedBox(height: 12),
      TileRow(
        children: [
          StatTile(
            label: 'Приход',
            value: money(totals.income, c),
            icon: FontAwesomeIcons.arrowDown,
            color: Brand.success,
          ),
          StatTile(
            label: 'Расход',
            value: money(totals.expense, c),
            icon: FontAwesomeIcons.arrowUp,
            color: Brand.danger,
          ),
        ],
      ),
      const SizedBox(height: 12),
      GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10,
        crossAxisSpacing: 10,
        childAspectRatio: 2.6,
        children: [
          _CashAction(
            label: 'Пополнить баланс',
            icon: FontAwesomeIcons.wallet,
            onTap: _busy ? null : _topUp,
          ),
          _CashAction(
            label: 'Продажа товаров',
            icon: FontAwesomeIcons.cartShopping,
            onTap: _busy ? null : _sale,
          ),
          _CashAction(
            label: 'Приход / расход',
            icon: FontAwesomeIcons.rightLeft,
            onTap: _busy ? null : _move,
          ),
          _CashAction(
            label: 'Закрыть смену · Z-отчёт',
            icon: FontAwesomeIcons.fileInvoice,
            color: Brand.danger,
            onTap: _busy ? null : () => _close(data),
          ),
        ],
      ),
      const SizedBox(height: 16),
      if (data.report != null) ...[
        ShiftReportView(report: data.report!, currency: c, title: 'X-отчёт'),
        const SizedBox(height: 16),
      ],
      Panel(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const BlockTitle('Последние операции'),
            if (data.transactions.isEmpty)
              Text(
                'Кассовых операций пока нет.',
                style: TextStyle(color: p.muted),
              ),
            for (final t in data.transactions) TransactionRow(t, currency: c),
          ],
        ),
      ),
    ];
  }
}

class _CashAction extends StatelessWidget {
  const _CashAction({
    required this.label,
    required this.icon,
    required this.onTap,
    this.color = Brand.brand,
  });

  final String label;
  final FaIconData icon;
  final VoidCallback? onTap;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Panel(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      onTap: onTap,
      child: Row(
        children: [
          IconBox(icon, color: color, size: 34),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              label,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
            ),
          ),
        ],
      ),
    );
  }
}

/// Строка кассовой операции: приход зелёным, расход красным.
class TransactionRow extends StatelessWidget {
  const TransactionRow(this.transaction, {super.key, required this.currency});

  final CashTransaction transaction;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    final income = transaction.isIncome;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        children: [
          IconBox(
            income ? FontAwesomeIcons.arrowDown : FontAwesomeIcons.arrowUp,
            color: income ? Brand.success : Brand.danger,
            size: 32,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  transaction.description.isEmpty
                      ? (income ? 'Приход' : 'Расход')
                      : transaction.description,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 14),
                ),
                Text(
                  dateTime(transaction.at),
                  style: TextStyle(color: p.faint, fontSize: 12),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text(
            signedMoney(
              income ? transaction.amount : -transaction.amount,
              currency,
            ),
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: income ? Brand.success : Brand.danger,
            ),
          ),
        ],
      ),
    );
  }
}

/// X- или Z-отчёт: операции по видам, товары, сотрудники.
class ShiftReportView extends StatelessWidget {
  const ShiftReportView({
    super.key,
    required this.report,
    required this.currency,
    required this.title,
  });

  final ShiftReport report;
  final String currency;
  final String title;

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    final muted = TextStyle(color: p.muted);
    return Panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          BlockTitle(title),
          const FieldLabel('Операции'),
          if (report.kinds.isEmpty) Text('Операций нет.', style: muted),
          for (final k in report.kinds)
            InfoRow('${k.label} · ${k.count}', money(k.amount, currency)),
          const SizedBox(height: 8),
          const Divider(),
          const SizedBox(height: 8),
          const FieldLabel('Товары'),
          if (report.items.isEmpty) Text('Продаж товаров нет.', style: muted),
          for (final i in report.items)
            InfoRow('${i.name} × ${i.quantity}', money(i.amount, currency)),
          if (report.items.isNotEmpty) ...[
            if (report.discount > 0)
              InfoRow('Скидка', '−${money(report.discount, currency)}'),
            InfoRow(
              'Итого по товарам',
              money(report.itemsTotal, currency),
              bold: true,
            ),
          ],
          if (report.staff.isNotEmpty) ...[
            const SizedBox(height: 8),
            const Divider(),
            const SizedBox(height: 8),
            const FieldLabel('Сотрудники'),
            for (final s in report.staff)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 5),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${s.name} · ${s.count}',
                        style: const TextStyle(fontSize: 14),
                      ),
                    ),
                    Text(
                      signedMoney(s.income, currency),
                      style: const TextStyle(
                        color: Brand.success,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    if (s.expense > 0) ...[
                      const SizedBox(width: 10),
                      Text(
                        signedMoney(-s.expense, currency),
                        style: const TextStyle(
                          color: Brand.danger,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
          ],
        ],
      ),
    );
  }
}

/// Z-отчёт из ответа на закрытие смены.
class ZReportScreen extends StatelessWidget {
  const ZReportScreen({
    super.key,
    required this.report,
    required this.currency,
  });

  final ZReport report;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final shift = report.shift;
    final totals = report.totals;
    return Scaffold(
      appBar: AppBar(title: Text('Z-отчёт · смена №${shift.id}')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Panel(
            child: Column(
              children: [
                if (shift.openedAt != null)
                  InfoRow('Открыта', dateTime(shift.openedAt)),
                if (shift.closedAt != null)
                  InfoRow('Закрыта', dateTime(shift.closedAt)),
                InfoRow(
                  'Приход',
                  money(totals.income, currency),
                  valueColor: Brand.success,
                ),
                InfoRow(
                  'Расход',
                  money(totals.expense, currency),
                  valueColor: Brand.danger,
                ),
                InfoRow(
                  'В кассе на закрытии',
                  money(shift.closingBalance ?? totals.balance, currency),
                  bold: true,
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          if (report.report != null)
            ShiftReportView(
              report: report.report!,
              currency: currency,
              title: 'Итоги смены',
            ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Готово'),
          ),
        ],
      ),
    );
  }
}

/// Пополнение баланса клиента. Возвращает true, если пополнили.
class TopUpSheet extends StatefulWidget {
  const TopUpSheet({super.key, required this.session, this.customer});

  final Session session;
  final Customer? customer;

  @override
  State<TopUpSheet> createState() => _TopUpSheetState();
}

class _TopUpSheetState extends State<TopUpSheet> {
  final _search = TextEditingController();
  final _amount = TextEditingController();
  late Customer? _customer = widget.customer;
  List<Customer> _found = const [];
  bool _searching = false;
  Timer? _debounce;
  String _balance = 'balance';
  String _payment = 'cash';
  bool _busy = false;
  String? _error;
  TopUpResult? _result;

  String get _currency => widget.session.currency;

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _amount.dispose();
    super.dispose();
  }

  void _onSearch(String text) {
    _debounce?.cancel();
    if (text.trim().length < 2) {
      setState(() => _found = const []);
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 400), () => _find(text));
  }

  Future<void> _find(String text) async {
    setState(() => _searching = true);
    try {
      final page = await widget.session.api.customers(search: text);
      if (mounted && _search.text == text) setState(() => _found = page.items);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _searching = false);
    }
  }

  Future<void> _submit() async {
    final customer = _customer;
    final amount = parseAmount(_amount.text);
    if (customer == null) {
      setState(() => _error = 'Выберите пользователя из списка.');
      return;
    }
    if (amount == null || amount <= 0) {
      setState(() => _error = 'Введите сумму');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await widget.session.api.topUp(
        userId: customer.userId,
        amount: amount,
        balanceType: _balance,
        paymentMethod: _payment,
      );
      if (mounted) setState(() => _result = result);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final result = _result;
    if (result != null) return _done(result);
    final customer = _customer;
    final p = Palette.of(context);
    return SheetBody(
      title: 'Пополнение баланса',
      children: [
        if (customer == null) ...[
          TextField(
            controller: _search,
            autofocus: widget.customer == null,
            onChanged: _onSearch,
            decoration: InputDecoration(
              labelText: 'Пользователь',
              hintText: 'Поиск по имени или телефону',
              prefixIcon: const Padding(
                padding: EdgeInsets.all(14),
                child: FaIcon(FontAwesomeIcons.magnifyingGlass, size: 15),
              ),
              suffixIcon: _searching
                  ? const Padding(
                      padding: EdgeInsets.all(14),
                      child: SizedBox.square(
                        dimension: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    )
                  : null,
            ),
          ),
          const SizedBox(height: 8),
          if (_search.text.trim().length >= 2 && !_searching && _found.isEmpty)
            Padding(
              padding: const EdgeInsets.all(8),
              child: Text(
                'Пользователь не найден',
                style: TextStyle(color: p.muted),
              ),
            ),
          for (final c in _found.take(8))
            ListTile(
              dense: true,
              contentPadding: const EdgeInsets.symmetric(horizontal: 4),
              title: Text(
                c.name,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              subtitle: Text(formatPhone(c.phone)),
              trailing: Text(money(c.balance, _currency)),
              onTap: () => setState(() {
                _customer = c;
                _error = null;
              }),
            ),
        ] else
          Panel(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            color: p.subtle,
            child: Row(
              children: [
                const IconBox(FontAwesomeIcons.user, size: 34),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        customer.name,
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                      Text(
                        '${formatPhone(customer.phone)} · ${money(customer.balance, _currency)}',
                        style: TextStyle(color: p.muted, fontSize: 13),
                      ),
                    ],
                  ),
                ),
                if (widget.customer == null)
                  IconButton(
                    tooltip: 'Другой пользователь',
                    onPressed: () => setState(() => _customer = null),
                    icon: const FaIcon(FontAwesomeIcons.xmark, size: 16),
                  ),
              ],
            ),
          ),
        const SizedBox(height: 14),
        TextField(
          controller: _amount,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: 'Сумма',
            suffixText: currencySymbol(_currency),
          ),
        ),
        const SizedBox(height: 12),
        const FieldLabel('Зачислить на'),
        ChoiceRow<String>(
          options: const {
            'balance': 'Основной баланс',
            'bonus_balance': 'Бонусный баланс',
          },
          value: _balance,
          onChanged: (v) => setState(() => _balance = v),
        ),
        const SizedBox(height: 12),
        const FieldLabel('Способ оплаты'),
        ChoiceRow<String>(
          options: paymentLabels,
          value: _payment,
          onChanged: (v) => setState(() => _payment = v),
        ),
        if (_error != null) ...[const SizedBox(height: 12), ErrorBox(_error!)],
        const SizedBox(height: 16),
        BusyButton(label: 'Пополнить баланс', busy: _busy, onPressed: _submit),
      ],
    );
  }

  Widget _done(TopUpResult result) {
    return SheetBody(
      title: 'Баланс пополнен',
      subtitle: _customer?.name,
      children: [
        if (result.message.isNotEmpty) ...[
          ErrorBox(result.message, color: Brand.success),
          const SizedBox(height: 12),
        ],
        if (result.bonus > 0)
          InfoRow(
            'Бонус за пополнение',
            signedMoney(result.bonus, _currency),
            valueColor: Brand.success,
            bold: true,
          ),
        InfoRow('Основной баланс', money(result.balance, _currency)),
        InfoRow('Бонусный баланс', money(result.bonusBalance, _currency)),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('Готово'),
        ),
      ],
    );
  }
}

/// Приход или расход наличных с описанием.
class CashMoveSheet extends StatefulWidget {
  const CashMoveSheet({super.key, required this.session});

  final Session session;

  @override
  State<CashMoveSheet> createState() => _CashMoveSheetState();
}

class _CashMoveSheetState extends State<CashMoveSheet> {
  final _amount = TextEditingController();
  final _description = TextEditingController();
  String _type = 'income';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _amount.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final amount = parseAmount(_amount.text);
    if (amount == null || amount <= 0) {
      setState(() => _error = 'Введите сумму');
      return;
    }
    if (_description.text.trim().isEmpty) {
      setState(() => _error = 'Укажите описание');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.session.api.cashTransaction(
        type: _type,
        amount: amount,
        description: _description.text.trim(),
      );
      if (!mounted) return;
      showMessage(
        context,
        _type == 'income' ? 'Приход записан' : 'Расход записан',
      );
      Navigator.of(context).pop(true);
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
    final currency = widget.session.currency;
    return SheetBody(
      title: 'Приход / расход',
      children: [
        ChoiceRow<String>(
          options: const {'income': 'Приход', 'expense': 'Расход'},
          value: _type,
          onChanged: (v) => setState(() => _type = v),
        ),
        const SizedBox(height: 14),
        TextField(
          controller: _amount,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: 'Сумма',
            suffixText: currencySymbol(currency),
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _description,
          maxLength: 255,
          decoration: const InputDecoration(
            labelText: 'Описание',
            hintText: 'Например: размен, закупка воды',
          ),
        ),
        if (_error != null) ...[const SizedBox(height: 4), ErrorBox(_error!)],
        const SizedBox(height: 12),
        BusyButton(
          label: _type == 'income' ? 'Записать приход' : 'Записать расход',
          busy: _busy,
          onPressed: _submit,
        ),
      ],
    );
  }
}

/// Продажа товаров на кассе: каталог, корзина, оплата.
class SaleScreen extends StatefulWidget {
  const SaleScreen({super.key, required this.session});

  final Session session;

  @override
  State<SaleScreen> createState() => _SaleScreenState();
}

class _SaleScreenState extends State<SaleScreen> {
  final _search = TextEditingController();
  final List<Product> _products = [];
  final Map<int, int> _cart = {};
  final Map<int, Product> _known = {};
  int _page = 1;
  bool _hasMore = false;
  bool _loading = false;
  bool _busy = false;
  String? _error;
  Timer? _debounce;

  String get _currency => widget.session.currency;

  @override
  void initState() {
    super.initState();
    _load(reset: true);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({bool reset = false}) async {
    if (_loading) return;
    final query = _search.text;
    setState(() {
      _loading = true;
      if (reset) _error = null;
    });
    try {
      final page = await widget.session.api.products(
        search: query,
        page: reset ? 1 : _page + 1,
      );
      if (!mounted || query != _search.text) return;
      setState(() {
        if (reset) _products.clear();
        _products.addAll(page.items);
        for (final p in page.items) {
          _known[p.id] = p;
        }
        _page = page.currentPage;
        _hasMore = page.hasMore;
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

  void _add(Product p, [int delta = 1]) {
    setState(() {
      final next = (_cart[p.id] ?? 0) + delta;
      if (next <= 0) {
        _cart.remove(p.id);
      } else {
        _cart[p.id] = next;
      }
    });
  }

  double get _total => _cart.entries.fold(
    0,
    (sum, e) => sum + (_known[e.key]?.price ?? 0) * e.value,
  );

  int get _count => _cart.values.fold(0, (a, b) => a + b);

  Future<void> _pay() async {
    if (_cart.isEmpty) return;
    final ok = await confirm(
      context,
      title: 'Оформить продажу?',
      text: 'Товаров: $_count на ${money(_total, _currency)}.',
      action: 'Оплатить',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      final sale = await widget.session.api.sale(Map.of(_cart));
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text('Продажа №${sale.id}'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              for (final i in sale.items)
                InfoRow('${i.name} × ${i.quantity}', money(i.total, _currency)),
              const Divider(),
              InfoRow('Итого', money(sale.amount, _currency), bold: true),
            ],
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Готово'),
            ),
          ],
        ),
      );
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        showError(context, e);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Продажа товаров')),
      body: Column(
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
            child: _products.isEmpty
                ? (_loading
                      ? const Center(child: CircularProgressIndicator())
                      : EmptyState(
                          icon: FontAwesomeIcons.boxOpen,
                          text: _error ?? 'Товары не найдены.',
                          onRetry: () => _load(reset: true),
                        ))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
                    itemCount: _products.length + (_hasMore ? 1 : 0),
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      if (i >= _products.length) {
                        return Center(
                          child: TextButton(
                            onPressed: _loading ? null : _load,
                            child: const Text('Показать ещё'),
                          ),
                        );
                      }
                      final product = _products[i];
                      final inCart = _cart[product.id] ?? 0;
                      final out = product.quantity <= 0;
                      return Panel(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 10,
                        ),
                        borderColor: inCart > 0 ? Brand.brand : null,
                        onTap: out ? null : () => _add(product),
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
                                    out
                                        ? 'Нет в наличии · 0 шт.'
                                        : '${money(product.price, _currency)} · ${product.quantity} шт.',
                                    style: TextStyle(
                                      color: out ? Brand.danger : p.muted,
                                      fontSize: 13,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            if (inCart > 0)
                              _Stepper(
                                value: inCart,
                                onMinus: () => _add(product, -1),
                                onPlus: () => _add(product),
                              ),
                          ],
                        ),
                      );
                    },
                  ),
          ),
          if (_cart.isNotEmpty)
            Material(
              color: p.card,
              elevation: 8,
              child: SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Товаров: $_count',
                              style: TextStyle(color: p.muted),
                            ),
                            Text(
                              money(_total, _currency),
                              style: const TextStyle(
                                fontSize: 20,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ],
                        ),
                      ),
                      TextButton(
                        onPressed: _busy ? null : () => setState(_cart.clear),
                        child: const Text('Очистить'),
                      ),
                      const SizedBox(width: 8),
                      BusyButton(
                        label: 'Оплатить',
                        busy: _busy,
                        onPressed: _pay,
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _Stepper extends StatelessWidget {
  const _Stepper({
    required this.value,
    required this.onMinus,
    required this.onPlus,
  });

  final int value;
  final VoidCallback onMinus;
  final VoidCallback onPlus;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          visualDensity: VisualDensity.compact,
          onPressed: onMinus,
          icon: const FaIcon(FontAwesomeIcons.minus, size: 13),
        ),
        Text('$value', style: const TextStyle(fontWeight: FontWeight.w700)),
        IconButton(
          visualDensity: VisualDensity.compact,
          onPressed: onPlus,
          icon: const FaIcon(FontAwesomeIcons.plus, size: 13),
        ),
      ],
    );
  }
}
