import 'package:flutter/material.dart';
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
                      [booking.computer, ?booking.zone].join(' · '),
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

/// Новая бронь: день, время, длительность, затем зона и свободный ПК.
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
  late DateTime _day;
  late TimeOfDay _time;
  int _minutes = 60;
  Future<List<BookingZone>>? _zones;
  BookingZone? _zone;
  BookingPc? _pc;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    // Ближайшие полчаса, не раньше чем через 15 минут.
    final soon = DateTime.now().add(const Duration(minutes: 15));
    final start = DateTime(
      soon.year,
      soon.month,
      soon.day,
      soon.hour,
    ).add(Duration(minutes: soon.minute <= 30 ? 30 : 60));
    _day = DateTime(start.year, start.month, start.day);
    _time = TimeOfDay(hour: start.hour, minute: start.minute);
    _load();
  }

  DateTime get _start =>
      DateTime(_day.year, _day.month, _day.day, _time.hour, _time.minute);

  List<int> get _durations => [
    for (final m in const [60, 120, 180, 240, 300, 360])
      if (m >= widget.overview.minMinutes && m <= widget.overview.maxMinutes) m,
  ];

  void _load() {
    setState(() {
      _zone = null;
      _pc = null;
      _zones = _start.isBefore(DateTime.now())
          ? null
          : widget.session.api.bookingOptions(_start, _minutes);
    });
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(context: context, initialTime: _time);
    if (picked == null) return;
    _time = picked;
    _load();
  }

  Future<void> _confirm(String currency) async {
    final zone = _zone, pc = _pc;
    if (zone == null || pc == null) return;
    final end = _start.add(Duration(minutes: _minutes));
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Забронировать ${pc.name}?'),
        content: Text(
          '${zone.name}, ${_period(_start, end)}.\n'
          'С баланса спишется ${money(zone.price, currency)}.'
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
      final message = await widget.session.api.book(pc.id, _start, _minutes);
      if (mounted) Navigator.of(context).pop(message);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _saving = false);
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.message)));
      _load(); // ПК могли занять
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
      appBar: AppBar(title: const Text('Новая бронь')),
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
              label: Text(_time.format(context)),
            ),
          ),
          const SizedBox(height: 20),
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
          const SizedBox(height: 28),
          if (_zones == null)
            const _Note(
              icon: FontAwesomeIcons.clock,
              text: 'Это время уже прошло — выберите другое.',
            )
          else
            FutureBuilder<List<BookingZone>>(
              future: _zones,
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
                        : 'Не удалось загрузить свободные ПК',
                    onRetry: _load,
                  );
                }
                final zones = snapshot.data ?? const <BookingZone>[];
                if (zones.isEmpty) {
                  return const EmptyState(
                    icon: FontAwesomeIcons.desktop,
                    text: 'В клубе нет зон с ценой за час — бронь недоступна',
                  );
                }
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const _Label('Зона и ПК'),
                    for (final zone in zones) ...[
                      _ZonePicker(
                        zone: zone,
                        currency: currency,
                        selected: _zone?.id == zone.id ? _pc : null,
                        onSelected: (pc) => setState(() {
                          _zone = zone;
                          _pc = pc;
                        }),
                      ),
                      const SizedBox(height: 12),
                    ],
                  ],
                );
              },
            ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
          child: FilledButton(
            onPressed: _pc == null || _saving ? null : () => _confirm(currency),
            child: _saving
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(
                    _pc == null
                        ? 'Выберите ПК'
                        : 'Забронировать ${_pc!.name} за ${money(_zone!.price, currency)}',
                  ),
          ),
        ),
      ),
    );
  }
}

class _ZonePicker extends StatelessWidget {
  const _ZonePicker({
    required this.zone,
    required this.currency,
    required this.selected,
    required this.onSelected,
  });

  final BookingZone zone;
  final String currency;
  final BookingPc? selected;
  final ValueChanged<BookingPc> onSelected;

  @override
  Widget build(BuildContext context) {
    final free = zone.freeCount;
    return GlassCard(
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
              Text(
                money(zone.price, currency),
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ],
          ),
          const SizedBox(height: 2),
          Text(
            '${money(zone.pricePerHour, currency)}/час · '
            '${free == 0 ? 'свободных ПК нет' : 'свободно $free из ${zone.computers.length}'}',
            style: TextStyle(color: Brand.faint, fontSize: 13),
          ),
          if (zone.computers.isNotEmpty) ...[
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final pc in zone.computers)
                  ChoiceChip(
                    label: Text(pc.name),
                    selected: selected?.id == pc.id,
                    onSelected: pc.available ? (_) => onSelected(pc) : null,
                  ),
              ],
            ),
          ],
        ],
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
