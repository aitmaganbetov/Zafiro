import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../qr.dart';
import '../theme.dart';
import '../widgets.dart';

/// Вход на ПК в клубе: сканируем QR-код с экрана входа ПК и подтверждаем.
class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key, required this.api});

  final ApiClient api;

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

enum _Step { scanning, loading, confirm, approving, done }

class _ScanScreenState extends State<ScanScreen> {
  final _scanner = MobileScannerController(
    formats: const [BarcodeFormat.qrCode],
  );
  _Step _step = _Step.scanning;
  String? _code;
  QrLoginInfo? _info;
  String? _error;
  String _doneMessage = '';
  String? _lastWrongQr;

  @override
  void dispose() {
    _scanner.dispose();
    super.dispose();
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_step != _Step.scanning) return;
    final raw = capture.barcodes
        .map((b) => b.rawValue)
        .whereType<String>()
        .firstOrNull;
    final code = pcLoginCode(raw);
    if (code == null) {
      // Чужой QR: подсказываем один раз, пока камера смотрит на него.
      if (raw != null && raw != _lastWrongQr) {
        _lastWrongQr = raw;
        setState(
          () => _error = 'Это не QR-код входа Zafiro. Наведите камеру на QR-код на экране ПК.',
        );
      }
      return;
    }
    setState(() {
      _step = _Step.loading;
      _code = code;
      _error = null;
    });
    await _scanner.stop();
    try {
      final info = await widget.api.qrLoginInfo(code);
      if (mounted) {
        setState(() {
          _info = info;
          _step = _Step.confirm;
        });
      }
    } on ApiException catch (e) {
      _fail(e.message);
    }
  }

  Future<void> _approve() async {
    setState(() => _step = _Step.approving);
    try {
      final message = await widget.api.qrLoginApprove(_code!);
      if (mounted) {
        setState(() {
          _doneMessage = message;
          _step = _Step.done;
        });
      }
    } on ApiException catch (e) {
      _fail(e.message);
    }
  }

  /// Ошибка — показываем её и снова включаем камеру.
  void _fail(String message) {
    if (!mounted) return;
    setState(() {
      _error = message;
      _step = _Step.scanning;
      _code = null;
      _info = null;
      _lastWrongQr = null;
    });
    _scanner.start();
  }

  void _rescan() => _fail('');

  @override
  Widget build(BuildContext context) {
    if (_step == _Step.done) {
      return _Done(message: _doneMessage, computer: _info?.computer ?? '');
    }

    return Scaffold(
      backgroundColor: Colors.black,
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          'Вход на ПК',
          style: TextStyle(fontWeight: FontWeight.w700),
        ),
        leading: IconButton(
          tooltip: 'Закрыть',
          icon: const FaIcon(FontAwesomeIcons.xmark, size: 20),
          onPressed: () => Navigator.pop(context),
        ),
        actions: [
          ValueListenableBuilder(
            valueListenable: _scanner,
            builder: (context, state, _) =>
                state.torchState == TorchState.unavailable
                ? const SizedBox.shrink()
                : IconButton(
                    tooltip: 'Фонарик',
                    onPressed: _scanner.toggleTorch,
                    icon: FaIcon(
                      FontAwesomeIcons.bolt,
                      size: 18,
                      color: state.torchState == TorchState.on
                          ? Brand.warning
                          : Colors.white,
                    ),
                  ),
          ),
        ],
      ),
      body: Stack(
        fit: StackFit.expand,
        children: [
          MobileScanner(
            controller: _scanner,
            onDetect: _onDetect,
            errorBuilder: (context, error) => _CameraError(error),
          ),
          const IgnorePointer(child: CustomPaint(painter: _Frame())),
          Positioned(
            left: 20,
            right: 20,
            bottom: 24,
            child: SafeArea(top: false, child: _panel()),
          ),
        ],
      ),
    );
  }

  Widget _panel() {
    return switch (_step) {
      _Step.loading => const _Panel(
        child: Center(child: CircularProgressIndicator()),
      ),
      _Step.confirm || _Step.approving => _Panel(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const IconBadge(FontAwesomeIcons.desktop, size: 48),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Войти на ${_info!.computer}?',
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        _info!.clubName,
                        style: TextStyle(color: Brand.muted),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 18),
            FilledButton.icon(
              onPressed: _step == _Step.approving ? null : _approve,
              icon: _step == _Step.approving
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : const FaIcon(FontAwesomeIcons.rightToBracket, size: 16),
              label: const Text('Войти на ПК'),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: _step == _Step.approving ? null : _rescan,
              child: const Text('Сканировать другой код'),
            ),
          ],
        ),
      ),
      _ => _Panel(
        child: Row(
          children: [
            FaIcon(
              _error?.isNotEmpty == true
                  ? FontAwesomeIcons.circleExclamation
                  : FontAwesomeIcons.qrcode,
              size: 20,
              color: _error?.isNotEmpty == true
                  ? Brand.danger
                  : Brand.accentSoft,
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Text(
                _error?.isNotEmpty == true
                    ? _error!
                    : 'Наведите камеру на QR-код на экране входа ПК',
                style: TextStyle(
                  color: _error?.isNotEmpty == true
                      ? Brand.danger
                      : Colors.white,
                  height: 1.4,
                ),
              ),
            ),
          ],
        ),
      ),
    };
  }
}

class _Panel extends StatelessWidget {
  const _Panel({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Brand.surface.withValues(alpha: 0.96),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: Brand.border),
      ),
      child: child,
    );
  }
}

/// Затемнение вокруг квадрата и розовые уголки рамки.
class _Frame extends CustomPainter {
  const _Frame();

  @override
  void paint(Canvas canvas, Size size) {
    final side = (size.shortestSide * 0.68).clamp(180.0, 320.0);
    final rect = Rect.fromCenter(
      center: Offset(size.width / 2, size.height * 0.42),
      width: side,
      height: side,
    );
    final hole = RRect.fromRectAndRadius(rect, const Radius.circular(24));

    canvas.drawPath(
      Path.combine(
        PathOperation.difference,
        Path()..addRect(Offset.zero & size),
        Path()..addRRect(hole),
      ),
      Paint()..color = Brand.background.withValues(alpha: 0.72),
    );

    final corner = Paint()
      ..color = Brand.accent
      ..strokeWidth = 5
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;
    const len = 34.0;
    const r = 24.0;
    for (final (x, y, dx, dy) in [
      (rect.left, rect.top, 1.0, 1.0),
      (rect.right, rect.top, -1.0, 1.0),
      (rect.left, rect.bottom, 1.0, -1.0),
      (rect.right, rect.bottom, -1.0, -1.0),
    ]) {
      canvas.drawPath(
        Path()
          ..moveTo(x, y + dy * len)
          ..lineTo(x, y + dy * r)
          ..quadraticBezierTo(x, y, x + dx * r, y)
          ..lineTo(x + dx * len, y),
        corner,
      );
    }
  }

  @override
  bool shouldRepaint(_Frame oldDelegate) => false;
}

class _CameraError extends StatelessWidget {
  const _CameraError(this.error);

  final MobileScannerException error;

  @override
  Widget build(BuildContext context) {
    final text = switch (error.errorCode) {
      MobileScannerErrorCode.permissionDenied =>
        'Нет доступа к камере. Разрешите его в настройках телефона.',
      MobileScannerErrorCode.unsupported => 'На этом устройстве нет камеры.',
      _ => 'Не удалось включить камеру.',
    };
    return ColoredBox(
      color: Brand.background,
      child: Center(
        child: EmptyState(icon: FontAwesomeIcons.camera, text: text),
      ),
    );
  }
}

class _Done extends StatelessWidget {
  const _Done({required this.message, required this.computer});

  final String message;
  final String computer;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            children: [
              const Spacer(),
              const IconBadge(
                FontAwesomeIcons.check,
                size: 88,
                color: Brand.success,
              ),
              const SizedBox(height: 24),
              Text(
                computer.isEmpty ? 'Готово' : 'Вход на $computer',
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 26,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                message,
                textAlign: TextAlign.center,
                style: TextStyle(color: Brand.muted, height: 1.5),
              ),
              const Spacer(),
              FilledButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Готово'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
