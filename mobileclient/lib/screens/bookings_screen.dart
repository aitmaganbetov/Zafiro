import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:intl/intl.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';

/// Брони ПК в клубе: оплачиваются с баланса, время начинается, когда гость
/// входит на забронированном ПК.
class BookingsScreen extends StatefulWidget {
  const BookingsScreen({super.key, required this.session});

  final Session session;

  @override
  State<BookingsScreen> createState() => _BookingsScreenState();
}

class _BookingsScreenState extends State<BookingsScreen> {
  late Future<BookingsOverview> _future = widget.session.api.bookings();

  Future<void> _reload() async {
    final future = widget.session.api.bookings();
    setState(() => _future = future);
    await future.catchError((_) => BookingsOverview.fromJson(const {}));
  }

  void _show(String message) =>
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(message)));

  Future<void> _create(BookingsOverview overview) async {
    final message = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) =>
            NewBookingScreen(session: widget.session, overview: overview),
      ),
    );
    if (message == null || !mounted) return;
    _show(message);
    await _afterChange();
  }

  Future<void> _cancel(Booking booking) async {
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Отменить бронь?'),
        content: Text(
          '${booking.computer}, ${_period(booking.startsAt, booking.endsAt)}. '
          'Оплата вернётся на баланс.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Нет'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Отменить бронь'),
          ),
        ],
      ),
    );
    if (sure != true) return;
    try {
      _show(await widget.session.api.cancelBooking(booking.id));
    } on ApiException catch (e) {
      _show(e.message);
    }
    await _afterChange();
  }

  /// Бронь списала или вернула деньги: обновляем и список, и баланс.
  Future<void> _afterChange() async {
    await _reload();
    try {
      await widget.session.refreshProfile();
    } on ApiException {
      // Баланс обновится при следующем открытии.
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Бронь ПК')),
      body: FutureBuilder<BookingsOverview>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          final overview = snapshot.data;
          if (overview == null) {
            return EmptyState(
              icon: FontAwesomeIcons.calendarXmark,
              text: snapshot.error is ApiException
                  ? (snapshot.error as ApiException).message
                  : 'Не удалось загрузить брони',
              onRetry: _reload,
            );
          }
          final upcoming = overview.bookings
              .where((b) => b.isUpcoming)
              .toList();
          final past = overview.bookings.where((b) => !b.isUpcoming).toList();
          return RefreshIndicator(
            onRefresh: _reload,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
              children: [
                if (!overview.enabled)
                  const _Note(
                    icon: FontAwesomeIcons.circleInfo,
                    text:
                        'Клуб пока не принимает брони в приложении. '
                        'Забронировать ПК можно у администратора.',
                  )
                else ...[
                  FilledButton.icon(
                    onPressed: () => _create(overview),
                    icon: const FaIcon(FontAwesomeIcons.plus, size: 16),
                    label: const Text('Забронировать ПК'),
                  ),
                  const SizedBox(height: 12),
                  _Note(
                    icon: FontAwesomeIcons.circleInfo,
                    text: overview.canCancel
                        ? 'Бронь оплачивается с баланса. Войдите на забронированном '
                              'ПК в своё время — оплаченное время начнётся само. '
                              'Отменить бронь до начала можно здесь, деньги вернутся.'
                        : 'Бронь оплачивается с баланса. Войдите на забронированном '
                              'ПК в своё время — оплаченное время начнётся само. '
                              'Клуб не разрешает отменять брони в приложении.',
                  ),
                ],
                const SizedBox(height: 24),
                if (upcoming.isEmpty && past.isEmpty)
                  const EmptyState(
                    icon: FontAwesomeIcons.calendar,
                    text: 'Броней пока нет',
                  ),
                for (final booking in upcoming) ...[
                  _BookingCard(
                    booking: booking,
                    currency: overview.currency,
                    onCancel: booking.canCancel ? () => _cancel(booking) : null,
                  ),
                  const SizedBox(height: 12),
                ],
                if (past.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Text('Прошлые', style: TextStyle(color: Brand.faint)),
                  const SizedBox(height: 12),
                  for (final booking in past) ...[
                    _BookingCard(booking: booking, currency: overview.currency),
                    const SizedBox(height: 12),
                  ],
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

String _period(DateTime start, DateTime end) {
  final day = DateFormat('d MMMM, E', 'ru').format(start);
  final time = DateFormat('HH:mm', 'ru');
  return '$day, ${time.format(start)}–${time.format(end)}';
}

const _states = {
  'pending': 'Ожидает',
  'active': 'Идёт',
  'arrived': 'Вы пришли',
  'finished': 'Прошла',
  'cancelled': 'Отменена',
};

class _BookingCard extends StatelessWidget {
  const _BookingCard({
    required this.booking,
    required this.currency,
    this.onCancel,
  });

  final Booking booking;
  final String currency;
  final VoidCallback? onCancel;

  @override
  Widget build(BuildContext context) {
    final active = booking.isUpcoming;
    return GlassCard(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              IconBadge(
                FontAwesomeIcons.desktop,
                size: 40,
                color: active ? Brand.accentSoft : Brand.faint,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      [
                        booking.computer,
                        ?booking.zone,
                        ?booking.tariff,
                      ].join(' · '),
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      _period(booking.startsAt, booking.endsAt),
                      style: TextStyle(color: Brand.muted, fontSize: 13),
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (active && booking.code != null) ...[
            const SizedBox(height: 12),
            _CodeBox(code: booking.code!, many: booking.computers.length > 1),
          ],
          const SizedBox(height: 12),
          Row(
            children: [
              Text(
                _states[booking.state] ?? booking.state,
                style: TextStyle(
                  color: active ? Brand.accentSoft : Brand.faint,
                  fontWeight: FontWeight.w600,
                  fontSize: 13,
                ),
              ),
              if (booking.paid > 0) ...[
                Text(' · ', style: TextStyle(color: Brand.faint)),
                Text(
                  'оплачено ${money(booking.paid, currency)}',
                  style: TextStyle(color: Brand.faint, fontSize: 13),
                ),
              ],
              const Spacer(),
              if (onCancel != null)
                TextButton(onPressed: onCancel, child: const Text('Отменить')),
            ],
          ),
        ],
      ),
    );
  }
}

/// Код брони: его вводят на экране входа забронированного ПК, чтобы сесть без аккаунта.
class _CodeBox extends StatelessWidget {
  const _CodeBox({required this.code, required this.many});

  final String code;
  final bool many;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 10, 4, 10),
      decoration: BoxDecoration(
        color: Brand.accent.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Код брони',
                  style: TextStyle(color: Brand.muted, fontSize: 12),
                ),
                Text(
                  code,
                  style: const TextStyle(
                    fontSize: 24,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 4,
                    fontFeatures: [FontFeature.tabularFigures()],
                  ),
                ),
                Text(
                  many
                      ? 'Друзья вводят его на экране входа забронированных ПК'
                      : 'Введите его на экране входа ПК или войдите в аккаунт',
                  style: TextStyle(color: Brand.faint, fontSize: 12),
                ),
              ],
            ),
          ),
          IconButton(
            tooltip: 'Скопировать код',
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: code));
              if (context.mounted) {
                ScaffoldMessenger.of(
                  context,
                ).showSnackBar(const SnackBar(content: Text('Код скопирован')));
              }
            },
            icon: const FaIcon(FontAwesomeIcons.copy, size: 18),
          ),
        ],
      ),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.icon, required this.text});

  final FaIconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: FaIcon(icon, size: 14, color: Brand.faint),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            text,
            style: TextStyle(color: Brand.muted, fontSize: 13, height: 1.4),
          ),
        ),
      ],
    );
  }
}

/// Новая бронь, шаг 1: гость выбирает один или несколько ПК и жмёт «Далее».
class NewBookingScreen extends StatefulWidget {
  const NewBookingScreen({
    super.key,
    required this.session,
    required this.overview,
  });

  final Session session;
  final BookingsOverview overview;

  @override
  State<NewBookingScreen> createState() => _NewBookingScreenState();
}

class _NewBookingScreenState extends State<NewBookingScreen> {
  /// Больше ПК за раз сервер не бронирует.
  static const _maxPcs = 10;

  late final Future<List<PcZone>> _pcs = widget.session.api.bookingComputers();

  /// Выбранные ПК в порядке выбора и их зоны.
  final _selected = <int, (BookingPc, PcZone)>{};

  void _toggle(BookingPc pc, PcZone zone) {
    if (_selected.remove(pc.id) == null) {
      if (_selected.length >= _maxPcs) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('За раз — не больше $_maxPcs ПК')),
        );
        return;
      }
      _selected[pc.id] = (pc, zone);
    }
    setState(() {});
  }

  Future<void> _next() async {
    final message = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => BookingTimeScreen(
          session: widget.session,
          overview: widget.overview,
          pcs: [for (final (pc, _) in _selected.values) pc],
          zones: {for (final (_, zone) in _selected.values) zone.name}.toList(),
        ),
      ),
    );
    if (message != null && mounted) Navigator.of(context).pop(message);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Выберите ПК')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 120),
        children: [
          const _Note(
            icon: FontAwesomeIcons.circleInfo,
            text:
                'Можно выбрать несколько ПК — для компании. После оплаты '
                'появится код брони: по нему друзья сядут за эти ПК.',
          ),
          const SizedBox(height: 16),
          _pcPicker(widget.overview.currency),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
          child: FilledButton(
            onPressed: _selected.isEmpty ? null : _next,
            child: Text(switch (_selected.length) {
              0 => 'Выберите ПК',
              1 => 'Далее: ${_selected.values.first.$1.name}',
              final count => 'Далее: $count ПК',
            }),
          ),
        ),
      ),
    );
  }

  Widget _pcPicker(String currency) => FutureBuilder<List<PcZone>>(
    future: _pcs,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return const Padding(
          padding: EdgeInsets.all(24),
          child: Center(child: CircularProgressIndicator()),
        );
      }
      if (snapshot.hasError) {
        return EmptyState(
          icon: FontAwesomeIcons.triangleExclamation,
          text: snapshot.error is ApiException
              ? (snapshot.error as ApiException).message
              : 'Не удалось загрузить ПК клуба',
        );
      }
      final zones = snapshot.data ?? const <PcZone>[];
      if (zones.isEmpty) {
        return const EmptyState(
          icon: FontAwesomeIcons.desktop,
          text: 'В клубе нет ПК для брони',
        );
      }
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final zone in zones) ...[
            GlassCard(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          zone.name,
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                      if (zone.pricePerHour != null)
                        Text(
                          '${money(zone.pricePerHour!, currency)}/час',
                          style: TextStyle(color: Brand.faint, fontSize: 13),
                        ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final pc in zone.computers)
                        ChoiceChip(
                          label: Text(pc.name),
                          selected: _selected.containsKey(pc.id),
                          onSelected: (_) => _toggle(pc, zone),
                        ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
          ],
        ],
      );
    },
  );
}

/// Новая бронь, шаг 2: день и время, затем по часовой или тариф выбранных ПК.
class BookingTimeScreen extends StatefulWidget {
  const BookingTimeScreen({
    super.key,
    required this.session,
    required this.overview,
    required this.pcs,
    required this.zones,
  });

  final Session session;
  final BookingsOverview overview;
  final List<BookingPc> pcs;

  /// Зоны выбранных ПК, без повторов.
  final List<String> zones;

  @override
  State<BookingTimeScreen> createState() => _BookingTimeScreenState();
}

class _BookingTimeScreenState extends State<BookingTimeScreen> {
  /// «PC-01» или «PC-01, PC-02»: для подписей.
  String get _names => widget.pcs.map((pc) => pc.name).join(', ');
  // Время, вид брони и длительность гость выбирает сам: без них бронь не оформить.
  late DateTime _day;
  TimeOfDay? _time;
  bool? _byTariff;
  int? _minutes;
  Future<List<BookingTariff>>? _tariffs;
  BookingTariff? _tariff;

  /// Свободен ли выбранный ПК на это время и сколько стоит бронь.
  Future<BookingOptions>? _options;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _day = DateTime(now.year, now.month, now.day);
  }

  DateTime? get _start => switch (_time) {
    final time? => DateTime(
      _day.year,
      _day.month,
      _day.day,
      time.hour,
      time.minute,
    ),
    null => null,
  };

  bool get _past => _start?.isBefore(DateTime.now()) ?? false;

  List<int> get _durations => [
    for (final m in const [60, 120, 180, 240, 300, 360])
      if (m >= widget.overview.minMinutes && m <= widget.overview.maxMinutes) m,
  ];

  /// ПК, время или вид брони поменялись: тарифы зависят от ПК и времени.
  void _load() {
    setState(() {
      _tariff = null;
      _tariffs = _start != null && _byTariff == true && !_past
          ? widget.session.api.bookingTariffs(
              _start!,
              computerIds: [for (final pc in widget.pcs) pc.id],
            )
          : null;
      _loadQuote();
    });
  }

  void _loadQuote() {
    final ready = switch (_byTariff) {
      true => _tariff != null,
      false => _minutes != null,
      null => false,
    };
    _options = _start == null || _past || !ready
        ? null
        : widget.session.api.bookingOptions(
            _start!,
            minutes: _byTariff! ? null : _minutes,
            tariffId: _byTariff! ? _tariff?.id : null,
          );
  }

  /// Выбранные ПК в ответе сервера: общая цена, занятые и ПК без цены.
  _Quote? _quote(BookingOptions? options) {
    if (options == null) return null;
    final prices = <int, double>{};
    final busy = <String>[];
    for (final zone in options.zones) {
      for (final pc in zone.computers) {
        if (!widget.pcs.any((picked) => picked.id == pc.id)) continue;
        prices[pc.id] = zone.price;
        if (!pc.available) busy.add(pc.name);
      }
    }
    return _Quote(
      price: prices.values.fold(0, (sum, price) => sum + price),
      busy: busy,
      unpriced: [
        for (final pc in widget.pcs)
          if (!prices.containsKey(pc.id)) pc.name,
      ],
    );
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: _time ?? TimeOfDay.fromDateTime(DateTime.now()),
    );
    if (picked == null) return;
    _time = picked;
    _load();
  }

  Future<void> _confirm(_Quote quote, int bookedMinutes) async {
    final currency = widget.overview.currency;
    final start = _start!;
    final end = start.add(Duration(minutes: bookedMinutes));
    final what = _tariff == null ? '' : 'Тариф «${_tariff!.name}». ';
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Забронировать $_names?'),
        content: Text(
          '$what${widget.zones.join(', ')}, ${_period(start, end)}.\n'
          'С баланса спишется ${money(quote.price, currency)}.'
          '${widget.pcs.length > 1 ? '\nПосле оплаты появится код брони: по нему друзья сядут за эти ПК.' : ''}'
          '${widget.overview.canCancel ? '' : '\nОтменить бронь в приложении будет нельзя.'}',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Нет'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Оплатить'),
          ),
        ],
      ),
    );
    if (sure != true || !mounted) return;
    setState(() => _saving = true);
    try {
      final message = await widget.session.api.book(
        [for (final pc in widget.pcs) pc.id],
        start,
        minutes: _tariff == null ? _minutes : null,
        tariffId: _tariff?.id,
      );
      if (mounted) Navigator.of(context).pop(message);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _saving = false);
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.message)));
      _load(); // ПК или тариф могли занять
    }
  }

  @override
  Widget build(BuildContext context) {
    final currency = widget.overview.currency;
    final today = DateTime.now();
    final days = [
      for (var i = 0; i <= widget.overview.maxDaysAhead; i++)
        DateTime(today.year, today.month, today.day + i),
    ];
    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.pcs.length == 1
              ? '$_names · ${widget.zones.first}'
              : '${widget.pcs.length} ПК: $_names',
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 120),
        children: [
          const _Label('День'),
          SizedBox(
            height: 40,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: days.length,
              separatorBuilder: (_, _) => const SizedBox(width: 8),
              itemBuilder: (context, i) => ChoiceChip(
                label: Text(switch (i) {
                  0 => 'Сегодня',
                  1 => 'Завтра',
                  _ => DateFormat('E, d MMM', 'ru').format(days[i]),
                }),
                selected: days[i] == _day,
                onSelected: (_) {
                  _day = days[i];
                  _load();
                },
              ),
            ),
          ),
          const SizedBox(height: 20),
          const _Label('Начало'),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              onPressed: _pickTime,
              icon: const FaIcon(FontAwesomeIcons.clock, size: 14),
              label: Text(_time?.format(context) ?? 'Выбрать время'),
            ),
          ),
          const SizedBox(height: 24),
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(
                value: false,
                label: Text('По часовой'),
                icon: FaIcon(FontAwesomeIcons.hourglassHalf, size: 14),
              ),
              ButtonSegment(
                value: true,
                label: Text('Тариф'),
                icon: FaIcon(FontAwesomeIcons.tags, size: 14),
              ),
            ],
            emptySelectionAllowed: true,
            selected: {?_byTariff},
            onSelectionChanged: (value) {
              if (value.isEmpty) return; // выбор не снимается
              _byTariff = value.first;
              _load();
            },
          ),
          const SizedBox(height: 20),
          if (_past)
            const _Note(
              icon: FontAwesomeIcons.clock,
              text: 'Это время уже прошло — выберите другое.',
            )
          else if (_byTariff == false) ...[
            const _Label('Длительность'),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final m in _durations)
                  ChoiceChip(
                    label: Text('${m ~/ 60} ч'),
                    selected: m == _minutes,
                    onSelected: (_) {
                      _minutes = m;
                      _load();
                    },
                  ),
              ],
            ),
          ] else if (_tariffs != null)
            _tariffList(currency),
          const SizedBox(height: 24),
          if (_options != null) _quoteNote(currency),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
          child: FutureBuilder<BookingOptions>(
            future: _options,
            builder: (context, snapshot) {
              final ready = snapshot.connectionState == ConnectionState.done;
              final quote = ready ? _quote(snapshot.data) : null;
              final free = quote != null && quote.ok;
              return FilledButton(
                onPressed: free && !_saving
                    ? () => _confirm(quote, snapshot.data!.minutes)
                    : null,
                child: _saving
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(switch (()) {
                        _ when _time == null => 'Выберите время',
                        _ when _byTariff == null =>
                          'Выберите: по часовой или тариф',
                        _ when _byTariff == false && _minutes == null =>
                          'Выберите длительность',
                        _ when _byTariff == true && _tariff == null =>
                          'Выберите тариф',
                        _ when free =>
                          'Забронировать ${widget.pcs.length == 1 ? _names : '${widget.pcs.length} ПК'} за ${money(quote.price, currency)}',
                        _ => 'Забронировать',
                      }),
              );
            },
          ),
        ),
      ),
    );
  }

  Widget _tariffList(String currency) => FutureBuilder<List<BookingTariff>>(
    future: _tariffs,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return const Padding(
          padding: EdgeInsets.all(24),
          child: Center(child: CircularProgressIndicator()),
        );
      }
      if (snapshot.hasError) {
        return EmptyState(
          icon: FontAwesomeIcons.triangleExclamation,
          text: snapshot.error is ApiException
              ? (snapshot.error as ApiException).message
              : 'Не удалось загрузить тарифы',
          onRetry: _load,
        );
      }
      final tariffs = snapshot.data ?? const <BookingTariff>[];
      if (tariffs.isEmpty) {
        return EmptyState(
          icon: FontAwesomeIcons.tags,
          text:
              'Для $_names на это время тарифов нет — выберите другое время или по часовой',
        );
      }
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _Label('Тарифы для $_names'),
          for (final tariff in tariffs) ...[
            _TariffCard(
              tariff: tariff,
              currency: currency,
              selected: _tariff?.id == tariff.id,
              onTap: () => setState(() {
                _tariff = tariff;
                _loadQuote();
              }),
            ),
            const SizedBox(height: 10),
          ],
        ],
      );
    },
  );

  /// Итог: свободен ли ПК на это время, сколько стоит и до скольки.
  Widget _quoteNote(String currency) => FutureBuilder<BookingOptions>(
    future: _options,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return const Padding(
          padding: EdgeInsets.all(16),
          child: Center(child: CircularProgressIndicator()),
        );
      }
      if (snapshot.hasError) {
        return _Note(
          icon: FontAwesomeIcons.triangleExclamation,
          text: snapshot.error is ApiException
              ? (snapshot.error as ApiException).message
              : 'Не удалось проверить ПК',
        );
      }
      final quote = _quote(snapshot.data)!;
      if (quote.unpriced.isNotEmpty) {
        return _Note(
          icon: FontAwesomeIcons.circleInfo,
          text: _byTariff == true
              ? 'Этот тариф не действует для ${quote.unpriced.join(', ')}.'
              : 'У ${quote.unpriced.join(', ')} нет цены за час — выберите тариф.',
        );
      }
      if (quote.busy.isNotEmpty) {
        return _Note(
          icon: FontAwesomeIcons.calendarXmark,
          text: quote.busy.length == 1
              ? '${quote.busy.single} занят в это время — выберите другое время или другой ПК.'
              : '${quote.busy.join(', ')} заняты в это время — выберите другое время или другие ПК.',
        );
      }
      final end = _start!.add(Duration(minutes: snapshot.data!.minutes));
      return GlassCard(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            const IconBadge(FontAwesomeIcons.circleCheck, size: 40),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    widget.pcs.length == 1
                        ? '$_names свободен'
                        : 'Свободны: $_names',
                    style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    _period(_start!, end),
                    style: TextStyle(color: Brand.muted, fontSize: 13),
                  ),
                ],
              ),
            ),
            Text(
              money(quote.price, currency),
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ],
        ),
      );
    },
  );
}

/// Итог по выбранным ПК: общая цена, занятые на это время и ПК, к которым бронь не подходит.
class _Quote {
  _Quote({required this.price, required this.busy, required this.unpriced});

  final double price;
  final List<String> busy;
  final List<String> unpriced;

  bool get ok => busy.isEmpty && unpriced.isEmpty;
}

/// Тариф в списке: название, время, цена; выбранный подсвечен.
class _TariffCard extends StatelessWidget {
  const _TariffCard({
    required this.tariff,
    required this.currency,
    required this.selected,
    required this.onTap,
  });

  final BookingTariff tariff;
  final String currency;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final hours = tariff.minutes ~/ 60, minutes = tariff.minutes % 60;
    final time = [
      if (hours > 0) '$hours ч',
      if (minutes > 0) '$minutes мин',
    ].join(' ');
    return Material(
      color: selected ? Brand.accent.withValues(alpha: 0.12) : Brand.card,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Brand.radius),
        side: BorderSide(color: selected ? Brand.accent : Brand.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      tariff.name,
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      [time, ?tariff.description].join(' · '),
                      style: TextStyle(color: Brand.faint, fontSize: 13),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Text(
                money(tariff.price, currency),
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Label extends StatelessWidget {
  const _Label(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: Text(
      text,
      style: TextStyle(color: Brand.muted, fontWeight: FontWeight.w600),
    ),
  );
}
