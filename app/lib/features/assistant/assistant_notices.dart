import 'package:flutter/material.dart';

/// 41230 -> "41,230".
String thousands(int n) =>
    n.toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');

class AssistantNoKey extends StatelessWidget {
  const AssistantNoKey({super.key, required this.onOpenSettings});

  final VoidCallback onOpenSettings;

  @override
  Widget build(BuildContext context) => AssistantCentered(
    icon: Icons.key_outlined,
    text:
        'ผู้ช่วยใช้ Gemini API key ของครูเอง ยังไม่ได้ใส่ key '
        'ใส่ที่หน้าตั้งค่าก่อน แล้วกลับมาถามได้เลย',
    action: FilledButton(
      key: const ValueKey('assistant_settings'),
      onPressed: onOpenSettings,
      child: const Text('ไปที่ตั้งค่า'),
    ),
  );
}

class AssistantCentered extends StatelessWidget {
  const AssistantCentered({
    super.key,
    required this.icon,
    required this.text,
    this.action,
  });

  final IconData icon;
  final String text;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 40, color: theme.colorScheme.onSurfaceVariant),
            const SizedBox(height: 12),
            Text(text, textAlign: TextAlign.center),
            if (action case final a?) ...[const SizedBox(height: 16), a],
          ],
        ),
      ),
    );
  }
}
