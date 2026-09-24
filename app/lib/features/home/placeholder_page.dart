import 'package:flutter/material.dart';

/// Tells the teacher that a feature is not wired up yet (M0 has no data flows).
void showNotYet(BuildContext context, String feature) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(
      SnackBar(content: Text('ส่วน "$feature" จะเปิดใช้ใน Phase ถัดไป')),
    );
}

/// Empty state for a teacher section that has no data yet.
class PlaceholderPage extends StatelessWidget {
  const PlaceholderPage({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
  });

  final IconData icon;
  final String title;
  final String message;

  /// Primary action shown under the message; null hides the button.
  final String? actionLabel;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 72, color: theme.colorScheme.primary),
              const SizedBox(height: 16),
              Text(
                title,
                style: theme.textTheme.headlineSmall,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(
                message,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                textAlign: TextAlign.center,
              ),
              if (actionLabel case final label?) ...[
                const SizedBox(height: 24),
                FilledButton.tonalIcon(
                  onPressed: () => showNotYet(context, label),
                  icon: const Icon(Icons.add),
                  label: Text(label),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
