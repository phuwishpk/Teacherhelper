import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import '../theme/breakpoints.dart';

/// The app's mark and name, on the sign-in pages and in the sidebar.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 32, this.showName = true});

  final double size;
  final bool showName;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            color: scheme.primary,
            borderRadius: BorderRadius.circular(size * 0.3),
          ),
          child: Icon(
            Icons.auto_awesome_mosaic_outlined,
            size: size * 0.56,
            color: scheme.onPrimary,
          ),
        ),
        if (showName) ...[
          SizedBox(width: size * 0.32),
          Text(
            'Krucheck',
            style: size >= 40
                ? theme.textTheme.headlineSmall
                : theme.textTheme.titleMedium,
          ),
        ],
      ],
    );
  }
}

/// The page of the sign-in screens (DESIGN §27.4): the brand, then the
/// form on one card, centred in the window when there is room and at the
/// top of a phone. Scrolls when the form is taller than the window.
class AuthLayout extends StatelessWidget {
  const AuthLayout({
    super.key,
    required this.child,
    this.maxWidth = 440,
    this.showBrand = true,
  });

  final Widget child;
  final double maxWidth;
  final bool showBrand;

  @override
  Widget build(BuildContext context) {
    final compact = context.windowSize == WindowSize.compact;
    final scheme = Theme.of(context).colorScheme;
    return LayoutBuilder(
      builder: (context, box) => SingleChildScrollView(
        padding: EdgeInsets.symmetric(
          horizontal: compact ? 16 : 24,
          vertical: compact ? 24 : 40,
        ),
        child: ConstrainedBox(
          constraints: BoxConstraints(
            minHeight: compact ? 0 : box.maxHeight - 80,
          ),
          child: Center(
            child: ConstrainedBox(
              constraints: BoxConstraints(maxWidth: maxWidth),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (showBrand) ...[
                    const Center(child: BrandMark(size: 44)),
                    const SizedBox(height: 8),
                    Text(
                      'ผู้ช่วยตรวจการบ้านและข้อสอบสำหรับครู',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(height: 24),
                  ],
                  Material(
                    color: scheme.surfaceContainerLow,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(AppTheme.radiusLarge),
                      side: BorderSide(color: scheme.outlineVariant),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: Padding(
                      padding: EdgeInsets.all(compact ? 20 : 32),
                      child: child,
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
