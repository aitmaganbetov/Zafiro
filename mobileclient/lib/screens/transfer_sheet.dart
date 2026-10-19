import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../theme.dart';
import '../widgets.dart';

/// Перевод другу: сумма → код в Telegram → перевод. Возвращает сообщение сервера или null.
Future<String?> showTransferSheet(
  BuildContext context, {
  required ApiClient api,
  required Friend friend,
  Profile? profile,
}) {
  return showModalBottomSheet<String>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Brand.surface,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
    ),
    builder: (_) => _TransferSheet(api: api, friend: friend, profile: profile),
  );
}

class _TransferSheet extends StatefulWidget {
  const _TransferSheet({required this.api, required this.friend, this.profile});

  final ApiClient api;
  final Friend friend;
  final Profile? profile;

  @override
  State<_TransferSheet> createState() => _TransferSheetState();
}

class _TransferSheetState extends State<_TransferSheet> {
  final _amount = TextEditingController();
  final _code = TextEditingController();
  bool _busy = false;
  bool _codeSent = false;
  String? _info;
  String? _error;
  int _resendIn = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _amount.dispose();
    _code.dispose();
    super.dispose();
  }

  String get _amountText => _amount.text.trim().replaceAll(' ', '');

  Future<void> _sendCode() async {
    if (_amountText.isEmpty) {
      setState(() => _error = 'Введите сумму перевода');
      return;
    }
    await _busyCall(() async {
      final sent = await widget.api.transferCode(widget.friend.id, _amountText);
      setState(() {
        _codeSent = true;
        _info = sent.message;
      });
      _startTimer(sent.resendSeconds);
    });
  }

  Future<void> _confirm() async {
    await _busyCall(() async {
      final message = await widget.api.transferConfirm(
        widget.friend.id,
        _amountText,
        _code.text.trim(),
      );
      if (mounted) Navigator.pop(context, message);
    });
  }

  Future<void> _busyCall(Future<void> Function() call) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await call();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _startTimer(int seconds) {
    _timer?.cancel();
    setState(() => _resendIn = seconds);
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted || _resendIn <= 1) {
        t.cancel();
        if (mounted) setState(() => _resendIn = 0);
        return;
      }
      setState(() => _resendIn--);
    });
  }

  /// Сумму после отправки кода меняем — код к ней уже не подходит, нужен новый.
  void _editAmount() {
    _timer?.cancel();
    setState(() {
      _codeSent = false;
      _info = null;
      _error = null;
      _code.clear();
    });
  }

  @override
  Widget build(BuildContext context) {
    final profile = widget.profile;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 24, 20, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SectionHeading(kicker: 'Перевод', title: widget.friend.name),
              if (profile != null) ...[
                const SizedBox(height: 6),
                Text(
                  'Доступно: ${money(profile.balance, profile.currency)} · только основной счёт',
                  style: TextStyle(color: Brand.faint, fontSize: 13),
                ),
              ],
              const SizedBox(height: 20),
              TextField(
                controller: _amount,
                enabled: !_codeSent,
                autofocus: true,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[\d.,]')),
                ],
                decoration: InputDecoration(
                  labelText: 'Сумма',
                  prefixIcon: const SizedBox(
                    width: 44,
                    child: Center(
                      child: FaIcon(FontAwesomeIcons.wallet, size: 16),
                    ),
                  ),
                  suffixIcon: _codeSent
                      ? IconButton(
                          tooltip: 'Изменить сумму',
                          icon: const FaIcon(FontAwesomeIcons.pen, size: 14),
                          onPressed: _busy ? null : _editAmount,
                        )
                      : null,
                ),
              ),
              if (_codeSent) ...[
                const SizedBox(height: 14),
                if (_info != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Padding(
                          padding: EdgeInsets.only(top: 2),
                          child: FaIcon(
                            FontAwesomeIcons.telegram,
                            size: 16,
                            color: Color(0xFF29B6F6),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            _info!,
                            style: TextStyle(color: Brand.muted, height: 1.4),
                          ),
                        ),
                      ],
                    ),
                  ),
                TextField(
                  controller: _code,
                  autofocus: true,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  autofillHints: const [AutofillHints.oneTimeCode],
                  onSubmitted: (_) => _confirm(),
                  style: const TextStyle(
                    fontSize: 22,
                    letterSpacing: 8,
                    fontWeight: FontWeight.w600,
                  ),
                  decoration: const InputDecoration(
                    labelText: 'Код из Telegram',
                    counterText: '',
                  ),
                ),
              ],
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(_error!, style: const TextStyle(color: Brand.danger)),
              ],
              const SizedBox(height: 20),
              if (!_codeSent)
                FilledButton.icon(
                  onPressed: _busy ? null : _sendCode,
                  icon: _busy
                      ? const _Spinner()
                      : const FaIcon(FontAwesomeIcons.telegram, size: 18),
                  label: const Text('Получить код в Telegram'),
                )
              else ...[
                FilledButton.icon(
                  onPressed: _busy ? null : _confirm,
                  icon: _busy
                      ? const _Spinner()
                      : const FaIcon(
                          FontAwesomeIcons.moneyBillTransfer,
                          size: 16,
                        ),
                  label: const Text('Перевести'),
                ),
                const SizedBox(height: 6),
                TextButton(
                  onPressed: _busy || _resendIn > 0 ? null : _sendCode,
                  child: Text(
                    _resendIn > 0
                        ? 'Новый код через $_resendIn с'
                        : 'Отправить код ещё раз',
                  ),
                ),
              ],
              const SizedBox(height: 8),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  FaIcon(
                    FontAwesomeIcons.shieldHalved,
                    size: 12,
                    color: Brand.faint,
                  ),
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text(
                      'Перевод подтверждается кодом, который придёт в Telegram на ваш номер',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: Brand.faint, fontSize: 12),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Spinner extends StatelessWidget {
  const _Spinner();

  @override
  Widget build(BuildContext context) => const SizedBox.square(
    dimension: 18,
    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
  );
}
