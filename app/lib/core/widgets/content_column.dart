import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import '../theme/breakpoints.dart';

/// Centers page content and caps its width so forms and lists stay readable
/// on a tablet and a desktop (DESIGN §6.1, §27.2). Without [padding] the
/// space around the content follows the window: 16, 24 or 32 px.
class ContentColumn extends StatelessWidget {
  const ContentColumn({
    super.key,
    required this.child,
    this.maxWidth = 840,
    this.padding,
  });

  final Widget child;
  final double maxWidth;
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.topCenter,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: maxWidth),
        child: Padding(
          padding: padding ?? EdgeInsets.all(context.pageGutter),
          child: child,
        ),
      ),
    );
  }
}

/// A scrollable form column with the same width cap. From tablet width
/// the form sits on a card (DESIGN §27.4); on a phone it fills the page.
class FormColumn extends StatelessWidget {
  const FormColumn({super.key, required this.children, this.maxWidth = 560});

  final List<Widget> children;
  final double maxWidth;

  @override
  Widget build(BuildContext context) {
    final compact = context.windowSize == WindowSize.compact;
    final scheme = Theme.of(context).colorScheme;
    final form = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: children,
    );
    return SingleChildScrollView(
      padding: EdgeInsets.symmetric(
        horizontal: context.pageGutter,
        vertical: 24,
      ),
      child: Center(
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxWidth: compact ? maxWidth : maxWidth + 64,
          ),
          child: compact
              ? form
              : Material(
                  color: scheme.surfaceContainerLow,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(AppTheme.radiusLarge),
                    side: BorderSide(color: scheme.outlineVariant),
                  ),
                  clipBehavior: Clip.antiAlias,
                  child: Padding(
                    padding: const EdgeInsets.all(32),
                    child: form,
                  ),
                ),
        ),
      ),
    );
  }
}

/// Shows a message at the bottom of the screen, replacing any current one.
void showMessage(BuildContext context, String text) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(text)));
}

/// Yes/no dialog; resolves to true when the user confirms.
Future<bool> confirm(
  BuildContext context, {
  required String title,
  required String message,
  String confirmLabel = 'ตกลง',
  bool destructive = false,
}) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          style: destructive
              ? FilledButton.styleFrom(
                  backgroundColor: Theme.of(context).colorScheme.error,
                  foregroundColor: Theme.of(context).colorScheme.onError,
                )
              : null,
          onPressed: () => Navigator.of(context).pop(true),
          child: Text(confirmLabel),
        ),
      ],
    ),
  );
  return result ?? false;
}

/// Small colored label for a status value.
class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.label, this.color});

  final String label;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final c = color ?? scheme.secondary;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: c.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelMedium?.copyWith(
          color: c,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}
