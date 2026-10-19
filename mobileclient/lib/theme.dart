import 'package:flutter/material.dart';

/// Цвета сайта zafiro.kz (resources/views/welcome.blade.php).
abstract final class Brand {
  static const background = Color(0xFF0B0D10);
  static const surface = Color(0xFF11151A);
  static const accent = Color(0xFFFF0169);
  static const accentPressed = Color(0xFFE6005E);
  static const accentSoft = Color(0xFFFF4D8F);
  static const accentLight = Color(0xFFFF7AA8);
  static const violet = Color(0xFF7C3AED);
  static const success = Color(0xFF6EE7B7);
  static const warning = Color(0xFFFCD34D);
  static const danger = Color(0xFFFF6B6B);

  /// Карточки сайта: bg-white/[0.03], border-white/10, rounded-2xl.
  static final card = Colors.white.withValues(alpha: 0.03);
  static final border = Colors.white.withValues(alpha: 0.10);
  static final muted = Colors.white.withValues(alpha: 0.60);
  static final faint = Colors.white.withValues(alpha: 0.45);
  static const radius = 16.0;
}

ThemeData buildTheme() {
  final scheme = const ColorScheme.dark(
    primary: Brand.accent,
    onPrimary: Colors.white,
    secondary: Brand.accentSoft,
    onSecondary: Colors.white,
    tertiary: Brand.violet,
    surface: Brand.surface,
    onSurface: Colors.white,
    error: Brand.danger,
  ).copyWith(surfaceContainerHighest: const Color(0xFF1A1F26));

  final base = ThemeData(
    useMaterial3: true,
    brightness: Brightness.dark,
    colorScheme: scheme,
    fontFamily: 'Inter',
    scaffoldBackgroundColor: Colors.transparent,
    canvasColor: Brand.background,
  );

  OutlineInputBorder field(Color color, [double width = 1]) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: BorderSide(color: color, width: width),
      );

  return base.copyWith(
    textTheme: base.textTheme.apply(
      bodyColor: Colors.white,
      displayColor: Colors.white,
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: Colors.transparent,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      centerTitle: false,
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white.withValues(alpha: 0.04),
      labelStyle: TextStyle(color: Brand.muted),
      floatingLabelStyle: const TextStyle(color: Brand.accentSoft),
      prefixIconColor: Brand.faint,
      suffixIconColor: Brand.faint,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      border: field(Brand.border),
      enabledBorder: field(Brand.border),
      focusedBorder: field(Brand.accent, 1.5),
      errorBorder: field(Brand.danger),
      focusedErrorBorder: field(Brand.danger, 1.5),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: ButtonStyle(
        minimumSize: const WidgetStatePropertyAll(Size.fromHeight(52)),
        shape: WidgetStatePropertyAll(
          RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
        textStyle: const WidgetStatePropertyAll(
          TextStyle(
            fontFamily: 'Inter',
            fontSize: 16,
            fontWeight: FontWeight.w600,
          ),
        ),
        foregroundColor: const WidgetStatePropertyAll(Colors.white),
        backgroundColor: WidgetStateProperty.resolveWith(
          (s) => s.contains(WidgetState.disabled)
              ? Brand.accent.withValues(alpha: 0.4)
              : s.contains(WidgetState.pressed)
              ? Brand.accentPressed
              : Brand.accent,
        ),
        elevation: const WidgetStatePropertyAll(0),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: Colors.white,
        side: BorderSide(color: Brand.border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(foregroundColor: Brand.accentSoft),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: Brand.background.withValues(alpha: 0.92),
      surfaceTintColor: Colors.transparent,
      indicatorColor: Brand.accent.withValues(alpha: 0.15),
      height: 68,
      iconTheme: WidgetStateProperty.resolveWith(
        (s) => IconThemeData(
          size: 20,
          color: s.contains(WidgetState.selected)
              ? Brand.accentSoft
              : Brand.faint,
        ),
      ),
      labelTextStyle: WidgetStateProperty.resolveWith(
        (s) => TextStyle(
          fontFamily: 'Inter',
          fontSize: 12,
          fontWeight: s.contains(WidgetState.selected)
              ? FontWeight.w600
              : FontWeight.w500,
          color: s.contains(WidgetState.selected) ? Colors.white : Brand.faint,
        ),
      ),
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: Brand.surface,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(24),
        side: BorderSide(color: Brand.border),
      ),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: Brand.surface,
      contentTextStyle: const TextStyle(
        fontFamily: 'Inter',
        color: Colors.white,
      ),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: Brand.border),
      ),
    ),
    dividerTheme: DividerThemeData(
      color: Colors.white.withValues(alpha: 0.05),
      space: 1,
    ),
    progressIndicatorTheme: const ProgressIndicatorThemeData(
      color: Brand.accent,
    ),
  );
}
