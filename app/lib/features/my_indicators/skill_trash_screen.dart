import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../assignments/assignments_providers.dart';
import 'my_indicators.dart';
import 'skill_trash.dart';

/// Moves [id] to the trash after asking; true when it went (DESIGN §34.1).
/// The snack bar offers "เลิกทำ".
Future<bool> confirmMoveToTrash(
  BuildContext context,
  WidgetRef ref, {
  required int id,
  required String code,
}) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text('ลบ $code'),
      content: const Text(
        'ตัวชี้วัดนี้ (และทักษะย่อยที่คุณเพิ่มไว้ใต้มัน) จะไปอยู่ในถังขยะ '
        'เลือกใช้ใหม่ไม่ได้จนกว่าจะกู้คืน ข้อ รายวิชา แผน และคะแนนที่ใช้อยู่แล้วยังเก็บไว้ตามเดิม',
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('skill_trash_confirm'),
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('ย้ายไปถังขยะ'),
        ),
      ],
    ),
  );
  if (ok != true || !context.mounted) return false;
  final messenger = ScaffoldMessenger.of(context);
  final repo = ref.read(skillTrashRepositoryProvider);
  try {
    await repo.moveToTrash(id);
  } catch (e) {
    messenger.showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
    return false;
  }
  _refreshSkills(ref);
  messenger.showSnackBar(
    SnackBar(
      content: Text('ย้าย $code ไปถังขยะแล้ว'),
      action: SnackBarAction(
        label: 'เลิกทำ',
        onPressed: () async {
          try {
            await repo.restore(id);
            _refreshSkills(ref);
          } catch (e) {
            messenger.showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
          }
        },
      ),
    ),
  );
  return true;
}

void _refreshSkills(WidgetRef ref) {
  ref.invalidate(ownIndicatorsProvider);
  ref.invalidate(skillTrashProvider);
  ref.invalidate(myIndicatorsProvider);
  ref.invalidate(subjectsProvider);
}

/// "ถังขยะตัวชี้วัด" (DESIGN §34.1): the teacher's own indicators that were
/// deleted. Restore one, or delete it for good when nothing uses it.
class SkillTrashScreen extends ConsumerStatefulWidget {
  const SkillTrashScreen({super.key});

  @override
  ConsumerState<SkillTrashScreen> createState() => _SkillTrashScreenState();
}

class _SkillTrashScreenState extends ConsumerState<SkillTrashScreen> {
  bool _busy = false;

  Future<void> _run(Future<void> Function() action, String done) async {
    setState(() => _busy = true);
    try {
      await action();
      _refreshSkills(ref);
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(done)));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _deleteForGood(TrashedSkill t) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('ลบ ${t.skill.code} ถาวร'),
        content: const Text('ลบแล้วกู้คืนไม่ได้'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('ยกเลิก'),
          ),
          FilledButton(
            key: const ValueKey('skill_trash_purge_confirm'),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
              foregroundColor: Theme.of(context).colorScheme.onError,
            ),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('ลบถาวร'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    await _run(
      () => ref.read(skillTrashRepositoryProvider).deleteForGood(t.skill.id),
      'ลบ ${t.skill.code} ถาวรแล้ว',
    );
  }

  @override
  Widget build(BuildContext context) {
    final trash = ref.watch(skillTrashProvider);
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('ถังขยะตัวชี้วัด')),
      body: AsyncView<List<TrashedSkill>>(
        value: trash,
        onRetry: () => ref.invalidate(skillTrashProvider),
        data: (rows) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 720),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      'ตัวชี้วัดที่คุณเพิ่มเองแล้วลบ อยู่ที่นี่จนกว่าจะกู้คืนหรือลบถาวร '
                      'ลบถาวรได้เมื่อไม่มีข้อ รายวิชา แผน หรือผลการประเมินใช้อยู่',
                      style: theme.textTheme.bodyMedium,
                    ),
                    if (_busy) ...[
                      const SizedBox(height: 8),
                      const LinearProgressIndicator(),
                    ],
                    const SizedBox(height: 12),
                    if (rows.isEmpty)
                      Text(
                        'ถังขยะว่าง',
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    for (final t in rows)
                      Card(
                        key: ValueKey('skill_trash_${t.skill.id}'),
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                t.skill.code,
                                style: theme.textTheme.titleSmall,
                              ),
                              Text(t.skill.name),
                              if (t.inUse)
                                Padding(
                                  padding: const EdgeInsets.only(top: 4),
                                  child: Text(
                                    'ยังใช้อยู่ใน ${t.uses.entries.map((e) => '${e.key} ${e.value}').join(', ')}',
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      color: theme.colorScheme.onSurfaceVariant,
                                    ),
                                  ),
                                ),
                              Align(
                                alignment: Alignment.centerRight,
                                child: Wrap(
                                  spacing: 8,
                                  children: [
                                    TextButton.icon(
                                      onPressed: _busy || t.inUse
                                          ? null
                                          : () => _deleteForGood(t),
                                      icon: const Icon(Icons.delete_forever),
                                      label: const Text('ลบถาวร'),
                                    ),
                                    FilledButton.tonalIcon(
                                      onPressed: _busy
                                          ? null
                                          : () => _run(
                                              () => ref
                                                  .read(
                                                    skillTrashRepositoryProvider,
                                                  )
                                                  .restore(t.skill.id),
                                              'กู้คืน ${t.skill.code} แล้ว',
                                            ),
                                      icon: const Icon(Icons.restore),
                                      label: const Text('กู้คืน'),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
