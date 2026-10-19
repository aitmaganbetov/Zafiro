import 'package:flutter/material.dart';

/// Цвета панели сотрудника на сайте (resources/css/app.css, layouts/admin).
abstract final class Brand {
  /// --brand и --brand-dark панели; #FF0169 — акцент сайта (свечение, фокус).
  static const brand = Color(0xFFD90058);
  static const brandDark = Color(0xFFB0004A);
  static const accent = Color(0xFFFF0169);

  /// Сайдбар панели (.sidebar): тёмный в обеих темах.
  static const sidebar = Color(0xFF101713);
  static const sidebarLine = Color(0xFF263029);
  static const sidebarHover = Color(0xFF19231C);
  static const sidebarText = Color(0xFFD9E3DC);
  static const sidebarIcon = Color(0xFF819287);
  static const sidebarMuted = Color(0xFF7F9185);

  // Tailwind: green-500, red-600, amber-500, blue-400, slate-500.
  static const success = Color(0xFF16A34A);
  static const danger = Color(0xFFDC2626);
  static const warning = Color(0xFFF59E0B);
  static const info = Color(0xFF3B82F6);

  // Карта клуба (.cm-computer).
  static const pcFree = Color(0xFF22C55E);
  static const pcBusy = Color(0xFFD90058);
  static const pcReserved = Color(0xFFF4C35E);
  static const pcRestarting = Color(0xFFF59E0B);
  static const pcOffline = Color(0xFF64748B);
  static const pcStaff = Color(0xFF60A5FA);

  static const radius = 16.0;
}

/// Цвета, зависящие от темы: светлая — белые карточки и gray-200,
/// тёмная — gray-900 и gray-800, как dark: в шаблонах панели.
class Palette {
  const Palette._({
    required this.dark,
    required this.canvas,
    required this.card,
    required this.border,
    required this.text,
    required this.muted,
    required this.faint,
    required this.subtle,
  });

  static const light = Palette._(
    dark: false,
    canvas: Color(0xFFF3F6F4),
    card: Colors.white,
    border: Color(0xFFE5E7EB),
    text: Color(0xFF111827),
    muted: Color(0xFF6B7280),
    faint: Color(0xFF9CA3AF),
    subtle: Color(0xFFF9FAFB),
  );

  static const night = Palette._(
    dark: true,
    canvas: Color(0xFF0C110E),
    card: Color(0xFF111827),
    border: Color(0xFF1F2937),
    text: Color(0xFFF3F4F6),
    muted: Color(0xFF9CA3AF),
    faint: Color(0xFF6B7280),
    subtle: Color(0xFF1A2230),
  );

  static Palette of(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark ? night : light;

  final bool dark;
  final Color canvas;
  final Color card;
  final Color border;
  final Color text;
  final Color muted;
  final Color faint;

  /// Фон строк и полей (bg-gray-50 / gray-800/50).
  final Color subtle;
}

ThemeData buildTheme(Brightness brightness) {
  final p = brightness == Brightness.dark ? Palette.night : Palette.light;
  final scheme =
      ColorScheme.fromSeed(
        seedColor: Brand.brand,
        brightness: brightness,
      ).copyWith(
        primary: Brand.brand,
        onPrimary: Colors.white,
        secondary: Brand.accent,
        onSecondary: Colors.white,
        surface: p.card,
        onSurface: p.text,
        onSurfaceVariant: p.muted,
        outline: p.border,
        outlineVariant: p.border,
        error: Brand.danger,
        surfaceContainerHighest: p.subtle,
      );

  final base = ThemeData(
    useMaterial3: true,
    brightness: brightness,
    colorScheme: scheme,
    fontFamily: 'Inter',
    scaffoldBackgroundColor: p.canvas,
    canvasColor: p.canvas,
  );

  OutlineInputBorder field(Color color, [double width = 1]) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: BorderSide(color: color, width: width),
      );

  final buttonShape = WidgetStatePropertyAll(
    RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
  );

  return base.copyWith(
    textTheme: base.textTheme.apply(bodyColor: p.text, displayColor: p.text),
    appBarTheme: AppBarTheme(
      backgroundColor: p.card,
      foregroundColor: p.text,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      shape: Border(bottom: BorderSide(color: p.border)),
      titleTextStyle: TextStyle(
        fontFamily: 'Inter',
        fontSize: 18,
        fontWeight: FontWeight.w600,
        color: p.text,
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: p.dark ? p.subtle : Colors.white,
      labelStyle: TextStyle(color: p.muted),
      floatingLabelStyle: const TextStyle(color: Brand.brand),
      hintStyle: TextStyle(color: p.faint),
      prefixIconColor: p.faint,
      suffixIconColor: p.faint,
      isDense: true,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      border: field(p.border),
      enabledBorder: field(p.border),
      focusedBorder: field(Brand.accent, 1.5),
      errorBorder: field(Brand.danger),
      focusedErrorBorder: field(Brand.danger, 1.5),
    ),
    // .brand-button: розовая кнопка с тёмной рамкой.
    filledButtonTheme: FilledButtonThemeData(
      style: ButtonStyle(
        minimumSize: const WidgetStatePropertyAll(Size(64, 48)),
        shape: buttonShape,
        textStyle: const WidgetStatePropertyAll(
          TextStyle(
            fontFamily: 'Inter',
            fontSize: 15,
            fontWeight: FontWeight.w700,
          ),
        ),
        foregroundColor: const WidgetStatePropertyAll(Colors.white),
        backgroundColor: WidgetStateProperty.resolveWith(
          (s) => s.contains(WidgetState.disabled)
              ? Brand.brand.withValues(alpha: 0.4)
              : s.contains(WidgetState.pressed)
              ? Brand.brandDark
              : Brand.brand,
        ),
        elevation: const WidgetStatePropertyAll(0),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: p.text,
        minimumSize: const Size(64, 48),
        side: BorderSide(color: p.border),
        backgroundColor: p.card,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        textStyle: const TextStyle(
          fontFamily: 'Inter',
          fontSize: 15,
          fontWeight: FontWeight.w600,
        ),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(foregroundColor: Brand.brand),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: p.card,
      selectedColor: Brand.brand,
      side: BorderSide(color: p.border),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      labelStyle: TextStyle(
        fontFamily: 'Inter',
        fontSize: 14,
        fontWeight: FontWeight.w500,
        color: p.text,
      ),
      secondaryLabelStyle: const TextStyle(
        fontFamily: 'Inter',
        fontSize: 14,
        fontWeight: FontWeight.w600,
        color: Colors.white,
      ),
      showCheckmark: false,
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: p.card,
      surfaceTintColor: Colors.transparent,
      indicatorColor: Brand.brand.withValues(alpha: p.dark ? 0.25 : 0.12),
      height: 66,
      iconTheme: WidgetStateProperty.resolveWith(
        (s) => IconThemeData(
          size: 20,
          color: s.contains(WidgetState.selected) ? Brand.brand : p.faint,
        ),
      ),
      labelTextStyle: WidgetStateProperty.resolveWith(
        (s) => TextStyle(
          fontFamily: 'Inter',
          fontSize: 12,
          fontWeight: s.contains(WidgetState.selected)
              ? FontWeight.w600
              : FontWeight.w500,
          color: s.contains(WidgetState.selected) ? p.text : p.muted,
        ),
      ),
    ),
    drawerTheme: const DrawerThemeData(
      backgroundColor: Brand.sidebar,
      surfaceTintColor: Colors.transparent,
    ),
    bottomSheetTheme: BottomSheetThemeData(
      backgroundColor: p.card,
      surfaceTintColor: Colors.transparent,
      showDragHandle: true,
      dragHandleColor: p.border,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: p.card,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Brand.radius),
        side: BorderSide(color: p.border),
      ),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: p.dark
          ? const Color(0xFF1F2937)
          : const Color(0xFF101713),
      contentTextStyle: const TextStyle(
        fontFamily: 'Inter',
        color: Colors.white,
      ),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    ),
    dividerTheme: DividerThemeData(color: p.border, space: 1, thickness: 1),
    progressIndicatorTheme: const ProgressIndicatorThemeData(
      color: Brand.brand,
    ),
    listTileTheme: ListTileThemeData(iconColor: p.muted, textColor: p.text),
  );
}
