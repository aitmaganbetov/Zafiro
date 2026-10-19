import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import 'theme.dart';

/// Фон сайта: тёмный с розовым свечением сверху и фиолетовым снизу справа.
class Backdrop extends StatelessWidget {
  const Backdrop({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(color: Brand.background),
      child: Stack(
        children: [
          const Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: RadialGradient(
                  center: Alignment(0, -1.15),
                  radius: 1.0,
                  colors: [Color(0x40FF0169), Color(0x00FF0169)],
                ),
              ),
            ),
          ),
          const Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: RadialGradient(
                  center: Alignment(1.2, 1.1),
                  radius: 0.9,
                  colors: [Color(0x1F7C3AED), Color(0x007C3AED)],
                ),
              ),
            ),
          ),
          child,
        ],
      ),
    );
  }
}

/// Логотип и надпись ZAFIRO, как в шапке сайта.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.height = 30});

  final double height;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        SvgPicture.asset('assets/images/logo.svg', height: height),
        const SizedBox(width: 8),
        Text(
          'ZAFIRO',
          style: TextStyle(
            fontSize: height * 0.6,
            fontWeight: FontWeight.w700,
            letterSpacing: 1,
          ),
        ),
      ],
    );
  }
}

/// Карточка сайта: bg-white/[0.03], border-white/10, rounded-2xl.
class GlassCard extends StatelessWidget {
  const GlassCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(20),
    this.onTap,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(Brand.radius);
    return Material(
      color: Brand.card,
      shape: RoundedRectangleBorder(
        borderRadius: radius,
        side: BorderSide(color: Brand.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        splashColor: Brand.accent.withValues(alpha: 0.08),
        highlightColor: Brand.accent.withValues(alpha: 0.04),
        child: Padding(padding: padding, child: child),
      ),
    );
  }
}

/// Иконка в розовом квадрате, как в блоке «Возможности» на сайте.
class IconBadge extends StatelessWidget {
  const IconBadge(
    this.icon, {
    super.key,
    this.size = 44,
    this.color = Brand.accentSoft,
  });

  final FaIconData icon;
  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(size * 0.27),
      ),
      child: FaIcon(icon, size: size * 0.4, color: color),
    );
  }
}

/// Заголовок раздела: розовый «кикер» капсом и крупный заголовок.
class SectionHeading extends StatelessWidget {
  const SectionHeading({super.key, required this.kicker, required this.title});

  final String kicker;
  final String title;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          kicker.toUpperCase(),
          style: const TextStyle(
            color: Brand.accentSoft,
            fontSize: 12,
            fontWeight: FontWeight.w600,
            letterSpacing: 2.4,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          title,
          style: const TextStyle(
            fontSize: 26,
            fontWeight: FontWeight.w700,
            height: 1.2,
          ),
        ),
      ],
    );
  }
}

/// Пустое состояние или ошибка с кнопкой «Обновить».
class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.text,
    this.onRetry,
  });

  final FaIconData icon;
  final String text;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 48, horizontal: 24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconBadge(icon, size: 64),
          const SizedBox(height: 16),
          Text(
            text,
            textAlign: TextAlign.center,
            style: TextStyle(color: Brand.muted),
          ),
          if (onRetry != null) ...[
            const SizedBox(height: 16),
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const FaIcon(FontAwesomeIcons.arrowsRotate, size: 14),
              label: const Text('Обновить'),
            ),
          ],
        ],
      ),
    );
  }
}
