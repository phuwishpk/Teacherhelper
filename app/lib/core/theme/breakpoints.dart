import 'package:flutter/widgets.dart';

/// The three window sizes every screen is laid out for (DESIGN §27.2).
enum WindowSize {
  /// A phone: one column, bottom navigation.
  compact,

  /// A tablet or a small browser window: navigation rail.
  medium,

  /// A desktop browser or a landscape tablet: sidebar with labels.
  expanded,
}

abstract final class Breakpoints {
  /// From this width up the window is [WindowSize.medium].
  static const medium = 600.0;

  /// From this width up the window is [WindowSize.expanded].
  static const expanded = 1200.0;

  static WindowSize of(double width) => width >= expanded
      ? WindowSize.expanded
      : width >= medium
      ? WindowSize.medium
      : WindowSize.compact;
}

extension WindowSizeContext on BuildContext {
  WindowSize get windowSize => Breakpoints.of(MediaQuery.sizeOf(this).width);

  /// Space between the window edge and the page content.
  double get pageGutter => switch (windowSize) {
    WindowSize.compact => 16,
    WindowSize.medium => 24,
    WindowSize.expanded => 32,
  };
}
