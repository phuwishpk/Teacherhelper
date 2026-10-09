import 'package:flutter/material.dart';

/// The look of the app (DESIGN §27): quiet neutral surfaces, one blue
/// accent, flat cards with a hairline border, and one Thai typeface.
/// Screens take colours, text styles and shapes from here through
/// `Theme.of(context)`; they do not set their own.
abstract final class AppTheme {
  /// IBM Plex Sans Thai Looped, bundled in assets/fonts (pubspec.yaml).
  static const fontFamily = 'IBM Plex Sans Thai Looped';

  /// The accent colour.
  static const seed = Color(0xFF2563EB);

  /// Corner radii: controls and small surfaces, cards, sheets and dialogs.
  static const radiusSmall = 10.0;
  static const radius = 14.0;
  static const radiusLarge = 20.0;

  static ThemeData light() => _build(_lightScheme);

  static ThemeData dark() => _build(_darkScheme);

  static const _lightScheme = ColorScheme(
    brightness: Brightness.light,
    primary: Color(0xFF2563EB),
    onPrimary: Color(0xFFFFFFFF),
    primaryContainer: Color(0xFFE7EEFE),
    onPrimaryContainer: Color(0xFF173A8C),
    secondary: Color(0xFF4B5565),
    onSecondary: Color(0xFFFFFFFF),
    secondaryContainer: Color(0xFFEDF0F4),
    onSecondaryContainer: Color(0xFF1F2733),
    tertiary: Color(0xFF0E7C72),
    onTertiary: Color(0xFFFFFFFF),
    tertiaryContainer: Color(0xFFDCF3EF),
    onTertiaryContainer: Color(0xFF0B4F49),
    error: Color(0xFFD23B2E),
    onError: Color(0xFFFFFFFF),
    errorContainer: Color(0xFFFDECEA),
    onErrorContainer: Color(0xFF7D1C14),
    surface: Color(0xFFF6F7F9),
    onSurface: Color(0xFF151A22),
    onSurfaceVariant: Color(0xFF5A6474),
    surfaceContainerLowest: Color(0xFFFFFFFF),
    surfaceContainerLow: Color(0xFFFFFFFF),
    surfaceContainer: Color(0xFFF1F3F6),
    surfaceContainerHigh: Color(0xFFEBEEF2),
    surfaceContainerHighest: Color(0xFFE4E8ED),
    outline: Color(0xFF98A2B0),
    outlineVariant: Color(0xFFE2E6EB),
    shadow: Color(0xFF0F172A),
    scrim: Color(0xFF0F172A),
    inverseSurface: Color(0xFF1F2733),
    onInverseSurface: Color(0xFFF3F5F8),
    inversePrimary: Color(0xFF9DBBFF),
    surfaceTint: Colors.transparent,
  );

  static const _darkScheme = ColorScheme(
    brightness: Brightness.dark,
    primary: Color(0xFF8FB1FF),
    onPrimary: Color(0xFF0C2A6B),
    primaryContainer: Color(0xFF1B3676),
    onPrimaryContainer: Color(0xFFDCE7FF),
    secondary: Color(0xFFB7C1CF),
    onSecondary: Color(0xFF1F2733),
    secondaryContainer: Color(0xFF2A323D),
    onSecondaryContainer: Color(0xFFDDE3EB),
    tertiary: Color(0xFF6CD3C5),
    onTertiary: Color(0xFF05332E),
    tertiaryContainer: Color(0xFF144942),
    onTertiaryContainer: Color(0xFFC9F1EA),
    error: Color(0xFFFF9A8F),
    onError: Color(0xFF5A130D),
    errorContainer: Color(0xFF4A1F1B),
    onErrorContainer: Color(0xFFFFDAD5),
    surface: Color(0xFF0F1216),
    onSurface: Color(0xFFE7EAEE),
    onSurfaceVariant: Color(0xFFA2ABB8),
    surfaceContainerLowest: Color(0xFF0B0D10),
    surfaceContainerLow: Color(0xFF171B21),
    surfaceContainer: Color(0xFF1B2027),
    surfaceContainerHigh: Color(0xFF222831),
    surfaceContainerHighest: Color(0xFF2B323C),
    outline: Color(0xFF6B7584),
    outlineVariant: Color(0xFF2A313B),
    shadow: Color(0xFF000000),
    scrim: Color(0xFF000000),
    inverseSurface: Color(0xFFE7EAEE),
    onInverseSurface: Color(0xFF1F2733),
    inversePrimary: Color(0xFF2563EB),
    surfaceTint: Colors.transparent,
  );

  /// Thai has tall marks above and below the line, so every style gets
  /// more line height than the Latin defaults, and no letter spacing.
  static TextTheme _textTheme(ColorScheme scheme) {
    TextStyle style(double size, FontWeight weight, double height) => TextStyle(
      fontFamily: fontFamily,
      fontSize: size,
      fontWeight: weight,
      height: height,
      letterSpacing: 0,
      color: scheme.onSurface,
    );
    return TextTheme(
      displayLarge: style(48, FontWeight.w600, 1.2),
      displayMedium: style(40, FontWeight.w600, 1.2),
      displaySmall: style(34, FontWeight.w600, 1.25),
      headlineLarge: style(30, FontWeight.w600, 1.3),
      headlineMedium: style(26, FontWeight.w600, 1.3),
      headlineSmall: style(22, FontWeight.w600, 1.35),
      titleLarge: style(19, FontWeight.w600, 1.4),
      titleMedium: style(16, FontWeight.w600, 1.45),
      titleSmall: style(14.5, FontWeight.w600, 1.45),
      bodyLarge: style(16, FontWeight.w400, 1.55),
      bodyMedium: style(14.5, FontWeight.w400, 1.55),
      bodySmall: style(13, FontWeight.w400, 1.5),
      labelLarge: style(14.5, FontWeight.w600, 1.4),
      labelMedium: style(13, FontWeight.w500, 1.4),
      labelSmall: style(12, FontWeight.w500, 1.4),
    );
  }

  static ThemeData _build(ColorScheme scheme) {
    final text = _textTheme(scheme);
    final muted = scheme.onSurfaceVariant;
    final hairline = BorderSide(color: scheme.outlineVariant);
    final controlShape = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(radiusSmall),
    );
    final cardShape = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(radius),
      side: hairline,
    );
    // One height for every button, large enough for a finger.
    const buttonSize = Size(48, 44);
    const buttonPadding = EdgeInsets.symmetric(horizontal: 18, vertical: 10);

    OutlineInputBorder inputBorder(Color color, [double width = 1]) =>
        OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusSmall),
          borderSide: BorderSide(color: color, width: width),
        );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      fontFamily: fontFamily,
      textTheme: text,
      scaffoldBackgroundColor: scheme.surface,
      canvasColor: scheme.surface,
      dividerColor: scheme.outlineVariant,
      // The same comfortable control sizes on a phone and in a desktop browser.
      visualDensity: VisualDensity.standard,
      splashFactory: InkSparkle.splashFactory,
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        foregroundColor: scheme.onSurface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleSpacing: 16,
        titleTextStyle: text.titleLarge,
        shape: Border(bottom: hairline),
        iconTheme: IconThemeData(color: scheme.onSurface, size: 22),
        actionsIconTheme: IconThemeData(color: muted, size: 22),
      ),
      cardTheme: CardThemeData(
        color: scheme.surfaceContainerLow,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        shape: cardShape,
        clipBehavior: Clip.antiAlias,
      ),
      dividerTheme: DividerThemeData(
        color: scheme.outlineVariant,
        thickness: 1,
        space: 1,
      ),
      iconTheme: IconThemeData(color: muted, size: 22),
      listTileTheme: ListTileThemeData(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16),
        minVerticalPadding: 10,
        iconColor: muted,
        titleTextStyle: text.titleSmall?.copyWith(fontWeight: FontWeight.w500),
        subtitleTextStyle: text.bodySmall?.copyWith(color: muted),
        leadingAndTrailingTextStyle: text.labelMedium?.copyWith(color: muted),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: buttonSize,
          padding: buttonPadding,
          shape: controlShape,
          textStyle: text.labelLarge,
          elevation: 0,
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          minimumSize: buttonSize,
          padding: buttonPadding,
          shape: controlShape,
          textStyle: text.labelLarge,
          elevation: 0,
          backgroundColor: scheme.surfaceContainerLow,
          foregroundColor: scheme.primary,
          side: hairline,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: buttonSize,
          padding: buttonPadding,
          shape: controlShape,
          textStyle: text.labelLarge,
          side: BorderSide(color: scheme.outlineVariant, width: 1.2),
          foregroundColor: scheme.onSurface,
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          minimumSize: const Size(44, 40),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          shape: controlShape,
          textStyle: text.labelLarge,
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(shape: controlShape),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: scheme.primary,
        foregroundColor: scheme.onPrimary,
        elevation: 2,
        focusElevation: 2,
        hoverElevation: 3,
        highlightElevation: 2,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radius),
        ),
        extendedTextStyle: text.labelLarge,
        extendedPadding: const EdgeInsets.symmetric(horizontal: 18),
      ),
      segmentedButtonTheme: SegmentedButtonThemeData(
        style: ButtonStyle(
          shape: WidgetStatePropertyAll(controlShape),
          side: WidgetStatePropertyAll(hairline),
          textStyle: WidgetStatePropertyAll(text.labelLarge),
          padding: const WidgetStatePropertyAll(
            EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          ),
          backgroundColor: WidgetStateProperty.resolveWith(
            (states) => states.contains(WidgetState.selected)
                ? scheme.primaryContainer
                : scheme.surfaceContainerLow,
          ),
          foregroundColor: WidgetStateProperty.resolveWith(
            (states) => states.contains(WidgetState.disabled)
                ? scheme.onSurface.withValues(alpha: 0.38)
                : states.contains(WidgetState.selected)
                ? scheme.onPrimaryContainer
                : scheme.onSurface,
          ),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: scheme.surfaceContainerLow,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 14,
          vertical: 14,
        ),
        border: inputBorder(scheme.outlineVariant),
        enabledBorder: inputBorder(scheme.outlineVariant),
        disabledBorder: inputBorder(
          scheme.outlineVariant.withValues(alpha: 0.6),
        ),
        focusedBorder: inputBorder(scheme.primary, 1.6),
        errorBorder: inputBorder(scheme.error),
        focusedErrorBorder: inputBorder(scheme.error, 1.6),
        labelStyle: text.bodyMedium?.copyWith(color: muted),
        floatingLabelStyle: WidgetStateTextStyle.resolveWith(
          (states) => (text.bodyMedium ?? const TextStyle()).copyWith(
            color: states.contains(WidgetState.error)
                ? scheme.error
                : states.contains(WidgetState.focused)
                ? scheme.primary
                : muted,
          ),
        ),
        hintStyle: text.bodyMedium?.copyWith(color: scheme.outline),
        helperStyle: text.bodySmall?.copyWith(color: muted),
        errorStyle: text.bodySmall?.copyWith(color: scheme.error),
        helperMaxLines: 3,
        errorMaxLines: 3,
      ),
      chipTheme: ChipThemeData(
        backgroundColor: scheme.surfaceContainerLow,
        selectedColor: scheme.primaryContainer,
        side: hairline,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        labelStyle: text.labelMedium,
        secondaryLabelStyle: text.labelMedium?.copyWith(
          color: scheme.onPrimaryContainer,
        ),
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
        showCheckmark: false,
      ),
      navigationBarTheme: NavigationBarThemeData(
        height: 68,
        elevation: 0,
        backgroundColor: scheme.surfaceContainerLow,
        surfaceTintColor: Colors.transparent,
        indicatorColor: scheme.primaryContainer,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
            size: 22,
            color: states.contains(WidgetState.selected)
                ? scheme.primary
                : muted,
          ),
        ),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => text.labelSmall?.copyWith(
            fontWeight: states.contains(WidgetState.selected)
                ? FontWeight.w600
                : FontWeight.w500,
            color: states.contains(WidgetState.selected)
                ? scheme.primary
                : muted,
          ),
        ),
      ),
      navigationRailTheme: NavigationRailThemeData(
        backgroundColor: scheme.surfaceContainerLow,
        elevation: 0,
        indicatorColor: scheme.primaryContainer,
        selectedIconTheme: IconThemeData(color: scheme.primary, size: 22),
        unselectedIconTheme: IconThemeData(color: muted, size: 22),
        selectedLabelTextStyle: text.labelMedium?.copyWith(
          color: scheme.primary,
          fontWeight: FontWeight.w600,
        ),
        unselectedLabelTextStyle: text.labelMedium?.copyWith(color: muted),
      ),
      tabBarTheme: TabBarThemeData(
        labelStyle: text.labelLarge,
        unselectedLabelStyle: text.labelLarge?.copyWith(
          fontWeight: FontWeight.w500,
        ),
        labelColor: scheme.primary,
        unselectedLabelColor: muted,
        indicatorColor: scheme.primary,
        indicatorSize: TabBarIndicatorSize.label,
        dividerColor: scheme.outlineVariant,
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: scheme.surfaceContainerLow,
        surfaceTintColor: Colors.transparent,
        elevation: 6,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusLarge),
        ),
        titleTextStyle: text.titleLarge,
        contentTextStyle: text.bodyMedium?.copyWith(color: muted),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: scheme.surfaceContainerLow,
        modalBackgroundColor: scheme.surfaceContainerLow,
        surfaceTintColor: Colors.transparent,
        elevation: 6,
        showDragHandle: false,
        dragHandleColor: scheme.outlineVariant,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(
            top: Radius.circular(radiusLarge),
          ),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: scheme.inverseSurface,
        contentTextStyle: text.bodyMedium?.copyWith(
          color: scheme.onInverseSurface,
        ),
        actionTextColor: scheme.inversePrimary,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusSmall),
        ),
        insetPadding: const EdgeInsets.all(16),
      ),
      popupMenuTheme: PopupMenuThemeData(
        color: scheme.surfaceContainerLow,
        surfaceTintColor: Colors.transparent,
        elevation: 4,
        shape: cardShape,
        textStyle: text.bodyMedium,
      ),
      menuTheme: MenuThemeData(
        style: MenuStyle(
          backgroundColor: WidgetStatePropertyAll(scheme.surfaceContainerLow),
          surfaceTintColor: const WidgetStatePropertyAll(Colors.transparent),
          elevation: const WidgetStatePropertyAll(4),
          shape: WidgetStatePropertyAll(cardShape),
        ),
      ),
      tooltipTheme: TooltipThemeData(
        decoration: BoxDecoration(
          color: scheme.inverseSurface,
          borderRadius: BorderRadius.circular(8),
        ),
        textStyle: text.labelSmall?.copyWith(color: scheme.onInverseSurface),
        waitDuration: const Duration(milliseconds: 400),
      ),
      badgeTheme: BadgeThemeData(
        backgroundColor: scheme.error,
        textColor: scheme.onError,
        textStyle: text.labelSmall?.copyWith(fontWeight: FontWeight.w600),
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: scheme.primary,
        linearTrackColor: scheme.surfaceContainerHigh,
        circularTrackColor: Colors.transparent,
      ),
      expansionTileTheme: ExpansionTileThemeData(
        shape: const Border(),
        collapsedShape: const Border(),
        iconColor: muted,
        collapsedIconColor: muted,
        tilePadding: const EdgeInsets.symmetric(horizontal: 16),
        childrenPadding: const EdgeInsets.only(bottom: 8),
      ),
      dataTableTheme: DataTableThemeData(
        headingTextStyle: text.labelMedium?.copyWith(
          color: muted,
          fontWeight: FontWeight.w600,
        ),
        dataTextStyle: text.bodyMedium,
        dividerThickness: 1,
        headingRowHeight: 44,
        horizontalMargin: 16,
        columnSpacing: 20,
      ),
      checkboxTheme: CheckboxThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(5)),
        side: BorderSide(color: scheme.outline, width: 1.5),
      ),
      switchTheme: SwitchThemeData(
        trackOutlineColor: WidgetStateProperty.resolveWith(
          (states) => states.contains(WidgetState.selected)
              ? Colors.transparent
              : scheme.outline,
        ),
      ),
      datePickerTheme: DatePickerThemeData(
        backgroundColor: scheme.surfaceContainerLow,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusLarge),
        ),
      ),
    );
  }
}
