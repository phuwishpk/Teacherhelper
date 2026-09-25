import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/util/thai_date.dart';
import '../../core/widgets/content_column.dart';
import 'ai_key.dart';

/// Gemini key form of the settings page (DESIGN §10.1, §9.1).
///
/// The key only lives in this widget's text field until the server has
/// verified and stored it; it is never written to the device or logged.
class AiKeySection extends ConsumerStatefulWidget {
  const AiKeySection({super.key});

  @override
  ConsumerState<AiKeySection> createState() => _AiKeySectionState();
}

class _AiKeySectionState extends ConsumerState<AiKeySection> {
  final _key = TextEditingController();
  bool _obscure = true;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _key.clear();
    _key.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final key = _key.text.trim();
    if (key.isEmpty) {
      setState(() => _error = 'วาง Gemini API key ก่อน');
      return;
    }
    if (key.contains(RegExp(r'\s'))) {
      setState(
        () => _error = 'key ต้องไม่มีช่องว่าง ตรวจว่าคัดลอกมาครบบรรทัดเดียว',
      );
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(aiKeyProvider.notifier).save(key);
      _key.clear();
      if (!mounted) return;
      setState(() => _obscure = true);
      showMessage(context, 'ทดสอบผ่านและบันทึก key แล้ว');
    } catch (e) {
      if (mounted) setState(() => _error = aiKeyErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete(AiKeyStatus status) async {
    final ok = await confirm(
      context,
      title: 'ลบ Gemini API key?',
      message: status.serverKeyAvailable
          ? 'หลังลบ ระบบจะใช้ key กลางของเซิร์ฟเวอร์ตรวจการบ้านแทน'
          : 'หลังลบ ข้อที่ต้องใช้ AI จะค้างเป็น "ตรวจเอง" จนกว่าจะใส่ key ใหม่',
      confirmLabel: 'ลบ key',
      destructive: true,
    );
    if (!ok) return;
    setState(() => _busy = true);
    try {
      await ref.read(aiKeyProvider.notifier).delete();
      if (mounted) showMessage(context, 'ลบ key แล้ว');
    } catch (e) {
      if (mounted) showMessage(context, aiKeyErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final status = ref.watch(aiKeyProvider);
    final s = status.value;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.key_outlined, color: theme.colorScheme.primary),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'Gemini API key',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                if (s != null)
                  StatusChip(
                    label: s.configured ? 'ใส่แล้ว' : 'ยังไม่ได้ใส่',
                    color: s.configured
                        ? Colors.green.shade700
                        : theme.colorScheme.error,
                  ),
              ],
            ),
            const SizedBox(height: 12),
            if (status.isLoading && s == null) const LinearProgressIndicator(),
            if (status.hasError && s == null)
              Row(
                children: [
                  const Expanded(child: Text('ตรวจสอบสถานะ key ไม่ได้')),
                  TextButton(
                    onPressed: () => ref.invalidate(aiKeyProvider),
                    child: const Text('ลองใหม่'),
                  ),
                ],
              ),
            if (s != null && s.configured) ...[
              Text('key ที่ใช้อยู่: ${s.masked}'),
              if (s.lastVerifiedAt != null)
                Text(
                  'ทดสอบใช้งานได้ล่าสุด ${formatThaiDateTime(s.lastVerifiedAt!)}',
                  style: muted,
                ),
              const SizedBox(height: 8),
            ],
            const Text(
              'ระบบใช้ key ของคุณตรวจการบ้านก่อน ถ้าไม่มีจึงใช้ key กลางของเซิร์ฟเวอร์ (ถ้ามี) '
              'key ถูกเก็บแบบเข้ารหัสบนเซิร์ฟเวอร์ ไม่เก็บไว้ในเครื่องนี้ '
              'และจะไม่แสดงให้เห็นอีกหลังบันทึก',
            ),
            const SizedBox(height: 4),
            Text(
              'ใช้ key ของบัญชีที่เปิด billing (paid tier) เท่านั้น เพราะ free tier '
              'อนุญาตให้ Google นำข้อมูลไปใช้ได้',
              style: muted,
            ),
            if (s != null && !s.configured) ...[
              const SizedBox(height: 8),
              Text(
                s.serverKeyAvailable
                    ? 'ตอนนี้ระบบใช้ key กลางของเซิร์ฟเวอร์ตรวจให้อยู่'
                    : 'ตอนนี้ยังไม่มี key ให้ใช้ ข้อที่ต้องใช้ AI จะค้างเป็น "ตรวจเอง" จนกว่าจะใส่ key',
                style: TextStyle(
                  color: s.serverKeyAvailable
                      ? theme.colorScheme.onSurfaceVariant
                      : theme.colorScheme.error,
                ),
              ),
            ],
            const SizedBox(height: 16),
            TextField(
              key: const ValueKey('ai_key_field'),
              controller: _key,
              enabled: !_busy,
              obscureText: _obscure,
              autocorrect: false,
              enableSuggestions: false,
              enableIMEPersonalizedLearning: false,
              keyboardType: TextInputType.visiblePassword,
              autofillHints: const [],
              onSubmitted: (_) => _save(),
              decoration: InputDecoration(
                border: const OutlineInputBorder(),
                labelText: s?.configured == true
                    ? 'key ใหม่ (แทนที่ key เดิม)'
                    : 'วาง Gemini API key',
                errorText: _error,
                errorMaxLines: 3,
                suffixIcon: IconButton(
                  tooltip: _obscure ? 'แสดง key' : 'ซ่อน key',
                  onPressed: () => setState(() => _obscure = !_obscure),
                  icon: Icon(
                    _obscure
                        ? Icons.visibility_outlined
                        : Icons.visibility_off_outlined,
                  ),
                ),
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.end,
              children: [
                if (s?.configured == true)
                  OutlinedButton.icon(
                    onPressed: _busy ? null : () => _delete(s!),
                    icon: const Icon(Icons.delete_outline),
                    label: const Text('ลบ key'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: theme.colorScheme.error,
                    ),
                  ),
                FilledButton.icon(
                  onPressed: _busy ? null : _save,
                  icon: _busy
                      ? const SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.verified_outlined),
                  label: const Text('ทดสอบและบันทึก'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
