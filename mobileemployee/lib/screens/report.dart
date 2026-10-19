import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:intl/intl.dart' show DateFormat;

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';
import 'cash.dart';

/// Сводка по кассе, залу и складу за сегодня (как админпанель на сайте).
class ReportScreen extends StatefulWidget {
  const ReportScreen({super.key, required this.session});

  final Session session;

  @override
  State<ReportScreen> createState() => _ReportScreenState();
}

class _ReportScreenState extends State<ReportScreen> {
  DayReport? _data;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await widget.session.api.reportToday();
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

  @override
  Widget build(BuildContext context) {
    final data = _data;
    return LoadView(
      loading: data == null,
      error: _error,
      onRetry: _load,
      child: data == null
          ? null
          : RefreshIndicator(onRefresh: _load, child: _content(data)),
    );
  }

  Widget _content(DayReport r) {
    final p = Palette.of(context);
    final c = r.currency;
    final net = r.income - r.expense;
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          'Сводка по кассе, залу и складу за сегодня',
          style: TextStyle(color: p.muted, fontSize: 13),
        ),
        const SizedBox(height: 12),
        TileRow(
          children: [
            StatTile(
              label: 'Доход сегодня',
              value: money(r.income, c),
              icon: FontAwesomeIcons.arrowDown,
              color: Brand.success,
            ),
            StatTile(
              label: 'Расходы сегодня',
              value: money(r.expense, c),
              icon: FontAwesomeIcons.arrowUp,
              color: Brand.danger,
            ),
          ],
        ),
        const SizedBox(height: 12),
        TileRow(
          children: [
            StatTile(
              label: 'Чистый итог',
              value: signedMoney(net, c),
              icon: FontAwesomeIcons.wallet,
              hint: 'Доходы минус расходы',
            ),
            StatTile(
              label: 'Операций',
              value: '${r.transactions}',
              icon: FontAwesomeIcons.receipt,
              color: Brand.info,
              hint: 'Кассовых за сегодня',
            ),
          ],
        ),
        const SizedBox(height: 16),
        Panel(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const BlockTitle('Доходы и расходы за 7 дней'),
              SizedBox(
                height: 170,
                child: WeekChart(
                  days: r.week,
                  textColor: p.muted,
                  gridColor: p.border,
                ),
              ),
              const SizedBox(height: 10),
              const Wrap(
                spacing: 16,
                children: [
                  _Legend(color: Brand.brand, label: 'Доход'),
                  _Legend(color: Brand.pcOffline, label: 'Расход'),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Panel(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const BlockTitle('Состояние клуба'),
              InfoRow(
                'ПК онлайн',
                '${r.computersOnline} из ${r.computersTotal}',
              ),
              InfoRow('ПК занято', '${r.computersBusy}'),
              InfoRow('ПК забронировано', '${r.computersReserved}'),
              const SizedBox(height: 8),
              ClipRRect(
                borderRadius: BorderRadius.circular(999),
                child: LinearProgressIndicator(
                  minHeight: 8,
                  value: r.computersTotal == 0
                      ? 0
                      : r.computersBusy / r.computersTotal,
                  backgroundColor: p.subtle,
                  color: Brand.brand,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Panel(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const BlockTitle('Товары с низким остатком'),
              if (r.lowStock.isEmpty)
                Text('Все товары в наличии.', style: TextStyle(color: p.muted)),
              for (final item in r.lowStock)
                InfoRow(
                  item.name,
                  '${item.quantity} шт.',
                  valueColor: item.quantity <= 0 ? Brand.danger : Brand.warning,
                  bold: true,
                ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Panel(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const BlockTitle('Последние операции'),
              if (r.recent.isEmpty)
                Text(
                  'Кассовых операций пока нет.',
                  style: TextStyle(color: p.muted),
                ),
              for (final t in r.recent) TransactionRow(t, currency: c),
            ],
          ),
        ),
      ],
    );
  }
}

class _Legend extends StatelessWidget {
  const _Legend({required this.color, required this.label});

  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 10,
          height: 10,
          decoration: BoxDecoration(
            color: color,
            borderRadius: BorderRadius.circular(3),
          ),
        ),
        const SizedBox(width: 6),
        Text(
          label,
          style: TextStyle(color: Palette.of(context).muted, fontSize: 13),
        ),
      ],
    );
  }
}

/// Столбики дохода и расхода по дням, без сторонних библиотек графиков.
class WeekChart extends StatelessWidget {
  const WeekChart({
    super.key,
    required this.days,
    required this.textColor,
    required this.gridColor,
  });

  final List<DayPoint> days;
  final Color textColor;
  final Color gridColor;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      size: Size.infinite,
      painter: _WeekPainter(
        days: days,
        textColor: textColor,
        gridColor: gridColor,
      ),
    );
  }
}

class _WeekPainter extends CustomPainter {
  _WeekPainter({
    required this.days,
    required this.textColor,
    required this.gridColor,
  });

  final List<DayPoint> days;
  final Color textColor;
  final Color gridColor;

  @override
  void paint(Canvas canvas, Size size) {
    const labelHeight = 20.0;
    final chartHeight = size.height - labelHeight;
    final grid = Paint()
      ..color = gridColor
      ..strokeWidth = 1;
    for (var i = 0; i <= 3; i++) {
      final y = chartHeight * i / 3;
      canvas.drawLine(Offset(0, y), Offset(size.width, y), grid);
    }
    if (days.isEmpty) return;

    final peak = days.fold<double>(
      0,
      (m, d) => math.max(m, math.max(d.income, d.expense)),
    );
    final slot = size.width / days.length;
    final barWidth = math.min(14.0, slot / 3);
    final income = Paint()..color = Brand.brand;
    final expense = Paint()..color = Brand.pcOffline;

    for (var i = 0; i < days.length; i++) {
      final d = days[i];
      final center = slot * i + slot / 2;
      void bar(double value, double left, Paint paint) {
        if (peak <= 0 || value <= 0) return;
        final h = math.max(2.0, chartHeight * value / peak);
        canvas.drawRRect(
          RRect.fromRectAndCorners(
            Rect.fromLTWH(left, chartHeight - h, barWidth, h),
            topLeft: const Radius.circular(4),
            topRight: const Radius.circular(4),
          ),
          paint,
        );
      }

      bar(d.income, center - barWidth - 1, income);
      bar(d.expense, center + 1, expense);

      final label = TextPainter(
        text: TextSpan(
          text: d.date == null ? '' : DateFormat('dd.MM').format(d.date!),
          style: TextStyle(color: textColor, fontSize: 11, fontFamily: 'Inter'),
        ),
        textDirection: TextDirection.ltr,
      )..layout(maxWidth: slot);
      label.paint(canvas, Offset(center - label.width / 2, chartHeight + 5));
    }
  }

  @override
  bool shouldRepaint(_WeekPainter oldDelegate) =>
      oldDelegate.days != days ||
      oldDelegate.textColor != textColor ||
      oldDelegate.gridColor != gridColor;
}
