import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.session});

  final Session session;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _phone = TextEditingController(text: '+7');
  final _password = TextEditingController();
  bool _busy = false;
  bool _obscure = true;
  String? _error;
  String? _notice;

  @override
  void initState() {
    super.initState();
    _error = widget.session.signInNotice;
  }

  @override
  void dispose() {
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    try {
      await widget.session.login(normalizePhone(_phone.text), _password.text);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _forgot() async {
    final message = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => ForgotPasswordScreen(
          api: widget.session.api,
          phone: isValidPhone(_phone.text) ? _phone.text : '',
        ),
      ),
    );
    if (message != null && mounted) {
      setState(() {
        _error = null;
        _notice = message;
        _password.clear();
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 32),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SvgPicture.asset('assets/images/logo.svg', height: 80),
                  const SizedBox(height: 14),
                  const Text(
                    'Zafiro',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 26,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.5,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Управление клубом',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: p.muted),
                  ),
                  const SizedBox(height: 28),
                  Panel(
                    padding: const EdgeInsets.all(20),
                    child: Form(
                      key: _form,
                      child: AutofillGroup(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const Text(
                              'Вход для сотрудников',
                              style: TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Номер и пароль — как на сайте панели клуба',
                              style: TextStyle(color: p.muted, fontSize: 13),
                            ),
                            const SizedBox(height: 18),
                            PhoneField(
                              controller: _phone,
                              textInputAction: TextInputAction.next,
                            ),
                            const SizedBox(height: 14),
                            TextFormField(
                              controller: _password,
                              obscureText: _obscure,
                              autofillHints: const [AutofillHints.password],
                              onFieldSubmitted: (_) => _submit(),
                              decoration: InputDecoration(
                                labelText: 'Пароль',
                                prefixIcon: const FieldIcon(
                                  FontAwesomeIcons.lock,
                                ),
                                suffixIcon: IconButton(
                                  tooltip: _obscure
                                      ? 'Показать пароль'
                                      : 'Скрыть пароль',
                                  icon: FaIcon(
                                    _obscure
                                        ? FontAwesomeIcons.eye
                                        : FontAwesomeIcons.eyeSlash,
                                    size: 16,
                                  ),
                                  onPressed: () =>
                                      setState(() => _obscure = !_obscure),
                                ),
                              ),
                              validator: (v) =>
                                  (v ?? '').isEmpty ? 'Введите пароль' : null,
                            ),
                            if (_error != null) ...[
                              const SizedBox(height: 14),
                              ErrorBox(_error!),
                            ],
                            if (_notice != null) ...[
                              const SizedBox(height: 14),
                              ErrorBox(_notice!, color: Brand.success),
                            ],
                            const SizedBox(height: 20),
                            BusyButton(
                              label: 'Войти',
                              busy: _busy,
                              onPressed: _submit,
                            ),
                            const SizedBox(height: 6),
                            TextButton(
                              onPressed: _busy ? null : _forgot,
                              child: const Text('Забыли пароль?'),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Поле номера с маской +7 (700) 123-45-67.
class PhoneField extends StatelessWidget {
  const PhoneField({
    super.key,
    required this.controller,
    this.textInputAction,
    this.enabled = true,
  });

  final TextEditingController controller;
  final TextInputAction? textInputAction;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      controller: controller,
      enabled: enabled,
      keyboardType: TextInputType.phone,
      textInputAction: textInputAction,
      autofillHints: const [AutofillHints.telephoneNumber],
      inputFormatters: [PhoneMaskFormatter()],
      decoration: const InputDecoration(
        labelText: 'Номер телефона',
        hintText: '+7 (700) 123-45-67',
        prefixIcon: FieldIcon(FontAwesomeIcons.phone),
      ),
      validator: (v) =>
          isValidPhone(v ?? '') ? null : 'Введите номер: +7 и 10 цифр',
    );
  }
}

class FieldIcon extends StatelessWidget {
  const FieldIcon(this.icon, {super.key});

  final FaIconData icon;

  @override
  Widget build(BuildContext context) =>
      SizedBox(width: 44, child: Center(child: FaIcon(icon, size: 15)));
}

/// «Забыли пароль?»: код в Telegram на номер, затем код и новый пароль.
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key, required this.api, this.phone = ''});

  final ApiClient api;
  final String phone;

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _form = GlobalKey<FormState>();
  late final _phone = TextEditingController(
    text: widget.phone.isEmpty
        ? '+7'
        : formatPhone(normalizePhone(widget.phone)),
  );
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  bool _codeSent = false;
  bool _busy = false;
  String? _error;
  String? _info;
  int _resendIn = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _phone.dispose();
    _code.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _sendCode() async {
    if (!isValidPhone(_phone.text)) {
      setState(() => _error = 'Введите номер: +7 и 10 цифр');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final json = await widget.api.passwordCode(normalizePhone(_phone.text));
      if (!mounted) return;
      setState(() {
        _codeSent = true;
        _info = toStr(json['message']);
        _resendIn = json['resend_seconds'] == null
            ? 60
            : toInt(json['resend_seconds']);
      });
      _timer?.cancel();
      _timer = Timer.periodic(const Duration(seconds: 1), (t) {
        if (!mounted || _resendIn <= 1) {
          t.cancel();
          if (mounted) setState(() => _resendIn = 0);
          return;
        }
        setState(() => _resendIn--);
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reset() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final message = await widget.api.passwordReset(
        phone: normalizePhone(_phone.text),
        code: _code.text.trim(),
        password: _password.text,
      );
      if (!mounted) return;
      Navigator.of(context).pop(
        message.isEmpty ? 'Пароль изменён. Войдите с новым паролем.' : message,
      );
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Восстановление пароля')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Form(
            key: _form,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'Код придёт в Telegram на этот номер. Telegram должен быть '
                  'зарегистрирован на тот же номер.',
                  style: TextStyle(color: p.muted, height: 1.4),
                ),
                const SizedBox(height: 18),
                PhoneField(controller: _phone, enabled: !_codeSent),
                const SizedBox(height: 14),
                if (!_codeSent)
                  BusyButton(
                    label: 'Получить код',
                    icon: FontAwesomeIcons.paperPlane,
                    busy: _busy,
                    onPressed: _sendCode,
                  ),
                if (_codeSent) ...[
                  if (_info != null && _info!.isNotEmpty) ...[
                    ErrorBox(_info!, color: Brand.info),
                    const SizedBox(height: 14),
                  ],
                  TextFormField(
                    controller: _code,
                    keyboardType: TextInputType.number,
                    autofillHints: const [AutofillHints.oneTimeCode],
                    inputFormatters: [
                      FilteringTextInputFormatter.digitsOnly,
                      LengthLimitingTextInputFormatter(6),
                    ],
                    decoration: const InputDecoration(
                      labelText: 'Код из Telegram',
                      prefixIcon: FieldIcon(FontAwesomeIcons.key),
                    ),
                    validator: (v) => RegExp(r'^\d{6}$').hasMatch(v ?? '')
                        ? null
                        : 'Код — 6 цифр из сообщения в Telegram.',
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: _password,
                    obscureText: true,
                    autofillHints: const [AutofillHints.newPassword],
                    decoration: const InputDecoration(
                      labelText: 'Новый пароль',
                      prefixIcon: FieldIcon(FontAwesomeIcons.lock),
                    ),
                    validator: (v) => (v ?? '').length < 6
                        ? 'Пароль должен содержать минимум 6 символов.'
                        : null,
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: _confirm,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Повторите пароль',
                      prefixIcon: FieldIcon(FontAwesomeIcons.lock),
                    ),
                    validator: (v) =>
                        v == _password.text ? null : 'Пароли не совпадают.',
                  ),
                  const SizedBox(height: 18),
                  BusyButton(
                    label: 'Сменить пароль',
                    busy: _busy,
                    onPressed: _reset,
                  ),
                  const SizedBox(height: 6),
                  TextButton(
                    onPressed: _busy || _resendIn > 0 ? null : _sendCode,
                    child: Text(
                      _resendIn > 0
                          ? 'Отправить код ещё раз через $_resendIn с'
                          : 'Отправить код ещё раз',
                    ),
                  ),
                  TextButton(
                    onPressed: _busy
                        ? null
                        : () => setState(() {
                            _codeSent = false;
                            _info = null;
                          }),
                    child: const Text('Изменить номер'),
                  ),
                ],
                if (_error != null) ...[
                  const SizedBox(height: 14),
                  ErrorBox(_error!),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
