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

Color pcColor(PcState state) => switch (state) {
  PcState.free => Brand.pcFree,
  PcState.busy => Brand.pcBusy,
  PcState.reserved => Brand.pcReserved,
  PcState.restarting => Brand.pcRestarting,
  PcState.offline => Brand.pcOffline,
  PcState.staff => Brand.pcStaff,
};

String pcStateLabel(PcState state) => switch (state) {
  PcState.free => 'Свободен',
  PcState.busy => 'Занят',
  PcState.reserved => 'Забронирован',
  PcState.restarting => 'Перезапуск',
  PcState.offline => 'Офлайн',
  PcState.staff => 'Сотрудник',
};

const _legendLabels = {
  PcState.free: 'Свободно',
  PcState.busy: 'Занято',
  PcState.reserved: 'Бронь',
  PcState.staff: 'Сотрудник',
  PcState.restarting: 'Перезапуск',
  PcState.offline: 'Офлайн',
};

const _actionLabels = {
  'reserve': 'Бронь',
  'power_on': 'Включить',
  'restart': 'Перезагрузить',
  'power_off': 'Выключить',
};

const _agentStates = {
  'idle': 'Ожидает',
  'in_session': 'В сессии',
  'locked': 'Заблокирован',
  'shutting_down': 'Выключается',
};

class ClubMapScreen extends StatefulWidget {
  const ClubMapScreen({super.key, required this.session});

  final Session session;

  @override
  State<ClubMapScreen> createState() => _ClubMapScreenState();
}

class _ClubMapScreenState extends State<ClubMapScreen> {
  ClubMap? _map;
  String? _error;
  bool _loading = false;
  final Set<int> _selected = {};
  Timer? _timer;

  ApiClient get _api => widget.session.api;
  Permissions get _can => widget.session.permissions;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(
      const Duration(seconds: 10),
      (_) => _load(silent: true),
    );
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    if (_loading) return;
    _loading = true;
    if (!silent) setState(() => _error = null);
    try {
      final map = await _api.computers();
      if (!mounted) return;
      setState(() {
        _map = map;
        _error = null;
        final ids = map.computers.map((c) => c.id).toSet();
        _selected.removeWhere((id) => !ids.contains(id));
      });
    } on ApiException catch (e) {
      if (mounted && (!silent || _map == null)) {
        setState(() => _error = e.message);
      }
    } finally {
      _loading = false;
    }
  }

  bool get _canSelect =>
      _can.control || (_can.guestSession && (_map?.guestMode ?? false));

  void _tap(Computer pc) {
    if (_selected.isNotEmpty) {
      _toggle(pc);
      return;
    }
    _openSheet(pc);
  }

  void _toggle(Computer pc) {
    if (!_canSelect) return;
    HapticFeedback.selectionClick();
    setState(() {
      if (!_selected.remove(pc.id)) _selected.add(pc.id);
    });
  }

  Future<void> _openSheet(Computer pc) async {
    final map = _map;
    if (map == null) return;
    final message = await showSheet<String>(
      context,
      (_) => _PcSheet(computer: pc, map: map, session: widget.session),
    );
    if (!mounted) return;
    if (message != null) {
      showMessage(context, message);
      _load(silent: true);
    }
  }

  Future<void> _bulk(String action) async {
    final ids = _selected.toList();
    if (action == 'power_off' &&
        !await confirm(
          context,
          title: 'Выключить выбранные ПК?',
          text: 'Выбрано: ${ids.length}. Сессии на этих ПК прервутся.',
          action: 'Выключить',
          destructive: true,
        )) {
      return;
    }
    try {
      final result = await _api.control(ids, action);
      if (!mounted) return;
      showMessage(context, controlMessage(action, result));
      setState(_selected.clear);
      _load(silent: true);
    } on ApiException catch (e) {
      if (mounted) showError(context, e);
    }
  }

  Future<void> _bulkGuest() async {
    final map = _map;
    if (map == null) return;
    final pcs = map.computers.where((c) => _selected.contains(c.id)).toList();
    final message = await showSheet<String>(
      context,
      (_) =>
          GuestSessionSheet(computers: pcs, map: map, session: widget.session),
    );
    if (!mounted || message == null) return;
    showMessage(context, message);
    setState(_selected.clear);
    _load(silent: true);
  }

  @override
  Widget build(BuildContext context) {
    final map = _map;
    return LoadView(
      loading: map == null,
      error: _error,
      onRetry: _load,
      child: map == null
          ? null
          : Column(
              children: [
                Expanded(
                  child: RefreshIndicator(
                    onRefresh: _load,
                    child: _MapList(
                      map: map,
                      selected: _selected,
                      onTap: _tap,
                      onLongPress: _canSelect ? _toggle : null,
                      currency: widget.session.currency,
                    ),
                  ),
                ),
                if (_selected.isNotEmpty) _selectionBar(map),
              ],
            ),
    );
  }

  Widget _selectionBar(ClubMap map) {
    final p = Palette.of(context);
    final pcs = map.computers.where((c) => _selected.contains(c.id)).toList();
    final guestAllowed =
        _can.guestSession &&
        map.guestMode &&
        pcs.every((c) => c.session == null);
    return Material(
      color: p.card,
      elevation: 8,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 8, 10),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'Выбрано ПК: ${pcs.length}',
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                  ),
                  TextButton(
                    onPressed: () => setState(_selected.clear),
                    child: const Text('Снять выбор'),
                  ),
                ],
              ),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    if (_can.control)
                      for (final e in _actionLabels.entries)
                        Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: OutlinedButton.icon(
                            onPressed: () => _bulk(e.key),
                            icon: FaIcon(actionIcon(e.key), size: 14),
                            label: Text(e.value),
                          ),
                        ),
                    if (guestAllowed)
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: FilledButton.icon(
                          onPressed: _bulkGuest,
                          icon: const FaIcon(
                            FontAwesomeIcons.userClock,
                            size: 14,
                          ),
                          label: const Text('Гостевая сессия'),
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

FaIconData actionIcon(String action) => switch (action) {
  'reserve' => FontAwesomeIcons.bookmark,
  'power_on' => FontAwesomeIcons.plug,
  'restart' => FontAwesomeIcons.rotateRight,
  _ => FontAwesomeIcons.powerOff,
};

/// Текст после команды ПК из ответа {updated, status} / {updated, reserved}.
String controlMessage(String action, Json result) {
  final count = toInt(result['updated']);
  if (action == 'reserve') {
    return toBool(result['reserved']) ? 'Бронь включена' : 'Бронь снята';
  }
  final what = switch (action) {
    'power_on' => 'Команда «Включить» отправлена',
    'restart' => 'Команда «Перезагрузить» отправлена',
    _ => 'Команда «Выключить» отправлена',
  };
  return count > 0 ? '$what: ПК $count' : what;
}

class _MapList extends StatelessWidget {
  const _MapList({
    required this.map,
    required this.selected,
    required this.onTap,
    required this.onLongPress,
    required this.currency,
  });

  final ClubMap map;
  final String currency;
  final Set<int> selected;
  final ValueChanged<Computer> onTap;
  final ValueChanged<Computer>? onLongPress;

  @override
  Widget build(BuildContext context) {
    final groups = <String, List<Computer>>{};
    final zoneIds = map.zones.map((z) => z.id).toSet();
    for (final zone in map.zones) {
      groups[zone.name] = [];
    }
    for (final pc in map.computers) {
      final zone = map.zone(pc.zoneId);
      final key = zone != null && zoneIds.contains(zone.id)
          ? zone.name
          : 'Без зоны';
      groups.putIfAbsent(key, () => []).add(pc);
    }
    groups.removeWhere((_, list) => list.isEmpty);
    for (final list in groups.values) {
      list.sort((a, b) => a.number.compareTo(b.number));
    }

    final counts = <PcState, int>{};
    for (final pc in map.computers) {
      counts[pc.state] = (counts[pc.state] ?? 0) + 1;
    }

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final e in _legendLabels.entries)
              if ((counts[e.key] ?? 0) > 0 || e.key == PcState.free)
                StatusPill(
                  '${e.value}: ${counts[e.key] ?? 0}',
                  color: pcColor(e.key),
                ),
          ],
        ),
        const SizedBox(height: 16),
        if (groups.isEmpty)
          const EmptyState(
            icon: FontAwesomeIcons.computer,
            text: 'На карте пока нет компьютеров.',
          ),
        for (final entry in groups.entries) ...[
          Panel(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                BlockTitle(
                  entry.key,
                  subtitle: _zoneSubtitle(entry.key, entry.value),
                ),
                GridView.builder(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                    maxCrossAxisExtent: 96,
                    mainAxisSpacing: 10,
                    crossAxisSpacing: 10,
                    childAspectRatio: 0.95,
                  ),
                  itemCount: entry.value.length,
                  itemBuilder: (context, i) {
                    final pc = entry.value[i];
                    return PcTile(
                      computer: pc,
                      selected: selected.contains(pc.id),
                      onTap: () => onTap(pc),
                      onLongPress: onLongPress == null
                          ? null
                          : () => onLongPress!(pc),
                    );
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
        ],
        if (onLongPress != null && selected.isEmpty && groups.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              'Удерживайте ПК, чтобы выбрать несколько.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Palette.of(context).faint, fontSize: 13),
            ),
          ),
      ],
    );
  }

  String _zoneSubtitle(String name, List<Computer> pcs) {
    final busy = pcs.where((c) => c.state == PcState.busy).length;
    Zone? zone;
    for (final z in map.zones) {
      if (z.name == name) zone = z;
    }
    final price = zone != null && zone.pricePerHour > 0
        ? ' · ${money(zone.pricePerHour, currency)}/час'
        : '';
    return 'Занято $busy из ${pcs.length}$price';
  }
}

/// Карточка ПК: цвет по статусу, номер, остаток времени.
class PcTile extends StatelessWidget {
  const PcTile({
    super.key,
    required this.computer,
    this.selected = false,
    this.onTap,
    this.onLongPress,
  });

  final Computer computer;
  final bool selected;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    final state = computer.state;
    final color = pcColor(state);
    final caption = switch (state) {
      PcState.busy =>
        computer.session?.endsAt == null
            ? 'Занят'
            : remaining(computer.session!.endsAt),
      PcState.staff => 'Сотрудник',
      PcState.reserved => 'Бронь',
      PcState.restarting => 'Перезапуск',
      PcState.offline => 'Офлайн',
      PcState.free => 'Свободен',
    };
    return Material(
      color: color.withValues(alpha: p.dark ? 0.18 : 0.10),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(
          color: selected ? Brand.brand : color.withValues(alpha: 0.55),
          width: selected ? 2.5 : (state == PcState.staff ? 2 : 1.2),
        ),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        onLongPress: onLongPress,
        child: Stack(
          children: [
            Positioned(
              top: 8,
              right: 8,
              child: Container(
                width: 8,
                height: 8,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: computer.isOnline ? Brand.pcFree : Brand.pcOffline,
                ),
              ),
            ),
            if (selected)
              const Positioned(
                top: 6,
                left: 6,
                child: FaIcon(
                  FontAwesomeIcons.circleCheck,
                  size: 14,
                  color: Brand.brand,
                ),
              ),
            if (computer.bookedNow && !selected)
              Positioned(
                top: 6,
                left: 6,
                child: FaIcon(
                  FontAwesomeIcons.calendarCheck,
                  size: 12,
                  color: p.muted,
                ),
              ),
            Center(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      '${computer.number}',
                      style: TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.w800,
                        color: p.text,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      caption,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: state == PcState.reserved && !p.dark
                            ? const Color(0xFF80551A)
                            : color,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Лист ПК: сведения и действия по правам сотрудника.
class _PcSheet extends StatefulWidget {
  const _PcSheet({
    required this.computer,
    required this.map,
    required this.session,
  });

  final Computer computer;
  final ClubMap map;
  final Session session;

  @override
  State<_PcSheet> createState() => _PcSheetState();
}

class _PcSheetState extends State<_PcSheet> {
  String? _busy;

  Computer get pc => widget.computer;
  Permissions get _can => widget.session.permissions;
  String get _currency => widget.session.currency;

  Future<void> _control(String action) async {
    if (action == 'power_off' &&
        !await confirm(
          context,
          title: 'Выключить ${pc.title}?',
          text: pc.session != null
              ? 'На ПК идёт сессия — она прервётся.'
              : null,
          action: 'Выключить',
          destructive: true,
        )) {
      return;
    }
    setState(() => _busy = action);
    try {
      final result = await widget.session.api.control([pc.id], action);
      if (mounted) Navigator.of(context).pop(controlMessage(action, result));
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _busy = null);
        showError(context, e);
      }
    }
  }

  Future<void> _endSession() async {
    final session = pc.session;
    if (session == null) return;
    final who = session.guest ? 'гостя' : session.userName;
    if (!await confirm(
      context,
      title: 'Завершить сессию?',
      text:
          '${pc.title}${who.isEmpty ? '' : ' · $who'}. Неизрасходованное время '
          'вернётся по правилам клуба.',
      action: 'Завершить',
      destructive: true,
    )) {
      return;
    }
    setState(() => _busy = 'end');
    try {
      final r = await widget.session.api.endSession(pc.id, session.id);
      final parts = <String>[
        if (r.refunded > 0) 'возвращено ${money(r.refunded, _currency)}',
        if (r.refundedBonus > 0) 'бонусов ${money(r.refundedBonus, _currency)}',
        if (r.keptMinutes > 0) 'минут сохранено: ${r.keptMinutes}',
      ];
      if (mounted) {
        Navigator.of(context).pop(
          parts.isEmpty
              ? 'Сессия завершена'
              : 'Сессия завершена: ${parts.join(', ')}',
        );
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _busy = null);
        showError(context, e);
      }
    }
  }

  Future<void> _guest() async {
    final message = await showSheet<String>(
      context,
      (_) => GuestSessionSheet(
        computers: [pc],
        map: widget.map,
        session: widget.session,
      ),
    );
    if (message != null && mounted) Navigator.of(context).pop(message);
  }

  @override
  Widget build(BuildContext context) {
    final state = pc.state;
    final zone = widget.map.zone(pc.zoneId);
    final session = pc.session;
    final visit = pc.staffVisit;
    final reserved = pc.reserved || pc.status == 'reserved';
    return SheetBody(
      title: pc.title,
      subtitle: zone?.name ?? 'Без зоны',
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            StatusPill(pcStateLabel(state), color: pcColor(state)),
            StatusPill(
              pc.isOnline ? 'Онлайн' : 'Офлайн',
              color: pc.isOnline ? Brand.pcFree : Brand.pcOffline,
            ),
            if (pc.bookedNow)
              const StatusPill(
                'Бронь активна',
                color: Brand.warning,
                icon: FontAwesomeIcons.calendarCheck,
              ),
          ],
        ),
        const SizedBox(height: 12),
        if (pc.agentState != null && _agentStates[pc.agentState] != null)
          InfoRow('Состояние сессии', _agentStates[pc.agentState]!),
        if (zone != null && zone.pricePerHour > 0)
          InfoRow('Цена за час', money(zone.pricePerHour, _currency)),
        if (session != null) ...[
          InfoRow('Клиент', session.guest ? 'Гость' : session.userName),
          if (session.endsAt != null) ...[
            InfoRow('Сессия закончится', time(session.endsAt)),
            InfoRow('Осталось', remaining(session.endsAt), bold: true),
          ],
        ],
        if (visit != null) ...[
          InfoRow('Сотрудник вошёл на ПК', visit.name),
          if (visit.until != null) InfoRow('До', time(visit.until)),
        ],
        const SizedBox(height: 16),
        if (_can.control) ...[
          const FieldLabel('Управление'),
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 8,
            crossAxisSpacing: 8,
            childAspectRatio: 3.2,
            children: [
              _ActionButton(
                label: reserved ? 'Снять бронь' : 'Забронировать',
                icon: FontAwesomeIcons.bookmark,
                busy: _busy == 'reserve',
                onPressed: _busy == null ? () => _control('reserve') : null,
              ),
              _ActionButton(
                label: 'Включить',
                icon: FontAwesomeIcons.plug,
                busy: _busy == 'power_on',
                onPressed: _busy == null ? () => _control('power_on') : null,
              ),
              _ActionButton(
                label: 'Перезагрузить',
                icon: FontAwesomeIcons.rotateRight,
                busy: _busy == 'restart',
                onPressed: _busy == null ? () => _control('restart') : null,
              ),
              _ActionButton(
                label: 'Выключить',
                icon: FontAwesomeIcons.powerOff,
                color: Brand.danger,
                busy: _busy == 'power_off',
                onPressed: _busy == null ? () => _control('power_off') : null,
              ),
            ],
          ),
          const SizedBox(height: 12),
        ],
        if (session != null && _can.endSession)
          OutlinedButton.icon(
            style: OutlinedButton.styleFrom(foregroundColor: Brand.danger),
            onPressed: _busy == null ? _endSession : null,
            icon: const FaIcon(FontAwesomeIcons.circleStop, size: 15),
            label: const Text('Завершить сессию'),
          ),
        if (session == null && _can.guestSession && widget.map.guestMode)
          FilledButton.icon(
            onPressed: _busy == null ? _guest : null,
            icon: const FaIcon(FontAwesomeIcons.userClock, size: 15),
            label: const Text('Гостевая сессия'),
          ),
      ],
    );
  }
}

class _ActionButton extends StatelessWidget {
  const _ActionButton({
    required this.label,
    required this.icon,
    required this.onPressed,
    this.busy = false,
    this.color,
  });

  final String label;
  final FaIconData icon;
  final VoidCallback? onPressed;
  final bool busy;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton(
      style: OutlinedButton.styleFrom(
        foregroundColor: color,
        padding: const EdgeInsets.symmetric(horizontal: 10),
      ),
      onPressed: onPressed,
      child: busy
          ? const SizedBox.square(
              dimension: 18,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                FaIcon(icon, size: 14),
                const SizedBox(width: 8),
                Flexible(child: Text(label, overflow: TextOverflow.ellipsis)),
              ],
            ),
    );
  }
}

/// Время гостю без аккаунта: тариф или минуты и способ оплаты. Нужна открытая смена.
class GuestSessionSheet extends StatefulWidget {
  const GuestSessionSheet({
    super.key,
    required this.computers,
    required this.map,
    required this.session,
  });

  final List<Computer> computers;
  final ClubMap map;
  final Session session;

  @override
  State<GuestSessionSheet> createState() => _GuestSessionSheetState();
}

class _GuestSessionSheetState extends State<GuestSessionSheet> {
  final _minutes = TextEditingController(text: '60');
  late final List<Tariff> _tariffs = widget.map.tariffs
      .where((t) => t.fits(widget.computers.map((c) => c.zoneId)))
      .toList();
  late bool _byTariff = _tariffs.isNotEmpty;
  Tariff? _tariff;
  String _payment = 'cash';
  bool _busy = false;
  String? _error;

  String get _currency => widget.session.currency;

  @override
  void initState() {
    super.initState();
    if (_tariffs.isNotEmpty) _tariff = _tariffs.first;
  }

  @override
  void dispose() {
    _minutes.dispose();
    super.dispose();
  }

  int get _minutesValue => int.tryParse(_minutes.text.trim()) ?? 0;

  /// Поминутно: цена часа зоны каждого ПК / 60.
  double get _estimate {
    if (_byTariff) return (_tariff?.price ?? 0) * widget.computers.length;
    var sum = 0.0;
    for (final pc in widget.computers) {
      sum +=
          (widget.map.zone(pc.zoneId)?.pricePerHour ?? 0) * _minutesValue / 60;
    }
    return sum;
  }

  Future<void> _submit() async {
    if (_byTariff && _tariff == null) {
      setState(() => _error = 'Выберите тариф');
      return;
    }
    if (!_byTariff && _minutesValue <= 0) {
      setState(() => _error = 'Укажите число минут');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await widget.session.api.guestSession(
        computerIds: widget.computers.map((c) => c.id).toList(),
        tariffId: _byTariff ? _tariff!.id : null,
        minutes: _byTariff ? null : _minutesValue,
        paymentMethod: _payment,
      );
      if (!mounted) return;
      final until = result.endsAt.toSet().join(', ');
      final text = [
        if (result.message.isNotEmpty) result.message,
        if (result.amount > 0) 'Оплата: ${money(result.amount, _currency)}',
        if (until.isNotEmpty) 'До $until',
      ].join(' · ');
      Navigator.of(context).pop(text.isEmpty ? 'Гостевая сессия начата' : text);
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
    final names = widget.computers.map((c) => c.title).join(', ');
    final noShift = widget.session.me?.shift == null;
    return SheetBody(
      title: 'Гостевая сессия',
      subtitle: names,
      children: [
        if (noShift) ...[
          const ErrorBox(
            'Касса закрыта: откройте смену, чтобы принять оплату.',
            color: Brand.warning,
          ),
          const SizedBox(height: 12),
        ],
        const FieldLabel('Что подключить'),
        ChoiceRow<bool>(
          options: const {true: 'Тариф', false: 'Поминутно'},
          value: _byTariff,
          onChanged: (v) => setState(() => _byTariff = v),
        ),
        const SizedBox(height: 12),
        if (_byTariff) ...[
          if (_tariffs.isEmpty)
            Text(
              'Нет тарифов для зоны выбранных ПК.',
              style: TextStyle(color: Palette.of(context).muted),
            ),
          for (final t in _tariffs)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Panel(
                padding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 12,
                ),
                borderColor: _tariff?.id == t.id ? Brand.brand : null,
                onTap: () => setState(() => _tariff = t),
                child: Row(
                  children: [
                    FaIcon(
                      _tariff?.id == t.id
                          ? FontAwesomeIcons.circleCheck
                          : FontAwesomeIcons.circle,
                      size: 16,
                      color: _tariff?.id == t.id
                          ? Brand.brand
                          : Palette.of(context).faint,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            t.name,
                            style: const TextStyle(fontWeight: FontWeight.w600),
                          ),
                          if (t.minutes > 0)
                            Text(
                              '${t.minutes} мин.',
                              style: TextStyle(
                                color: Palette.of(context).muted,
                                fontSize: 13,
                              ),
                            ),
                        ],
                      ),
                    ),
                    Text(
                      money(t.price, _currency),
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ],
                ),
              ),
            ),
        ] else
          TextField(
            controller: _minutes,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              labelText: 'Минут',
              suffixText: 'мин.',
            ),
          ),
        const SizedBox(height: 12),
        const FieldLabel('Оплата'),
        ChoiceRow<String>(
          options: paymentLabels,
          value: _payment,
          onChanged: (v) => setState(() => _payment = v),
        ),
        const SizedBox(height: 16),
        InfoRow(
          widget.computers.length > 1
              ? 'К оплате за ${widget.computers.length} ПК'
              : 'К оплате',
          '${_byTariff ? '' : '≈ '}${money(_estimate, _currency)}',
          bold: true,
        ),
        if (_error != null) ...[const SizedBox(height: 10), ErrorBox(_error!)],
        const SizedBox(height: 16),
        BusyButton(
          label: 'Подключить и принять оплату',
          busy: _busy,
          onPressed: _submit,
        ),
      ],
    );
  }
}
