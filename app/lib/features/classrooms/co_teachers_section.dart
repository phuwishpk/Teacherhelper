import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import 'classroom.dart';
import 'co_teachers.dart';

/// "ครูร่วมสอน" on the owner's classroom page (DESIGN §29.13.4): the
/// colleagues invited by e-mail, their state and courses, inviting one more
/// and removing one.
class CoTeachersSection extends ConsumerWidget {
  const CoTeachersSection({super.key, required this.classroom});

  final Classroom classroom;

  Future<void> _invite(BuildContext context, WidgetRef ref) async {
    final email = await showDialog<String>(
      context: context,
      builder: (context) => _InviteDialog(classroomId: classroom.id),
    );
    if (email == null || !context.mounted) return;
    ref.invalidate(coTeachersProvider(classroom.id));
    showMessage(context, 'เชิญ $email แล้ว รอครูท่านนั้นตอบรับในแอป');
  }

  Future<void> _remove(
    BuildContext context,
    WidgetRef ref,
    CoTeacherRow row,
  ) async {
    final name = row.teacherName ?? row.email;
    final ok = await confirm(
      context,
      title: row.accepted ? 'เอา $name ออกจากห้อง?' : 'ถอนคำเชิญ $name?',
      message: row.accepted
          ? 'รายวิชาของครูท่านนี้จะถูกเลิกผูกจากห้อง ถ้ามีการบ้านของรายวิชานั้นในห้องแล้วจะเอาออกไม่ได้'
          : 'ครูท่านนี้จะไม่เห็นคำเชิญอีก',
      confirmLabel: row.accepted ? 'เอาออก' : 'ถอนคำเชิญ',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    try {
      await ref.read(coTeachersRepositoryProvider).remove(classroom.id, row.id);
      ref.invalidate(coTeachersProvider(classroom.id));
      if (context.mounted) showMessage(context, 'เอา $name ออกแล้ว');
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final rows = ref.watch(coTeachersProvider(classroom.id));
    final open = !classroom.isClosed;
    return Card(
      key: const ValueKey('co_teachers_section'),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            title: Text('ครูร่วมสอน', style: theme.textTheme.titleMedium),
            subtitle: const Text(
              'เชิญครูท่านอื่นด้วยอีเมล ครูร่วมผูกรายวิชาของตัวเองและสั่งงานในห้องนี้ได้ แต่แก้รายชื่อไม่ได้',
            ),
            trailing: open
                ? TextButton.icon(
                    key: const ValueKey('co_teacher_invite'),
                    onPressed: () => _invite(context, ref),
                    icon: const Icon(Icons.person_add_alt_outlined),
                    label: const Text('เชิญครู'),
                  )
                : null,
          ),
          ...rows.when(
            skipLoadingOnRefresh: true,
            // Not an endless animation: a pending load must not keep the
            // page from settling.
            loading: () => const [
              Padding(
                padding: EdgeInsets.fromLTRB(16, 0, 16, 16),
                child: Text('กำลังโหลด…'),
              ),
            ],
            error: (e, _) => [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                child: Text(
                  apiErrorMessage(e),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            ],
            data: (rows) => rows.isEmpty
                ? const [
                    Padding(
                      padding: EdgeInsets.fromLTRB(16, 0, 16, 16),
                      child: Text('ยังไม่มีครูร่วมสอน'),
                    ),
                  ]
                : [
                    for (final row in rows)
                      ListTile(
                        key: ValueKey('co_teacher_${row.id}'),
                        leading: Icon(
                          row.accepted
                              ? Icons.co_present_outlined
                              : Icons.mail_outline,
                        ),
                        title: Text(row.teacherName ?? row.email),
                        subtitle: Text(
                          [
                            if (row.teacherName != null) row.email,
                            row.statusLabel,
                            if (row.courses.isNotEmpty) row.courses.join(', '),
                          ].join(' · '),
                        ),
                        trailing: open
                            ? IconButton(
                                key: ValueKey('co_teacher_remove_${row.id}'),
                                tooltip: row.accepted ? 'เอาออก' : 'ถอนคำเชิญ',
                                icon: const Icon(Icons.person_remove_outlined),
                                onPressed: () => _remove(context, ref, row),
                              )
                            : null,
                      ),
                  ],
          ),
        ],
      ),
    );
  }
}

class _InviteDialog extends ConsumerStatefulWidget {
  const _InviteDialog({required this.classroomId});

  final int classroomId;

  @override
  ConsumerState<_InviteDialog> createState() => _InviteDialogState();
}

class _InviteDialogState extends ConsumerState<_InviteDialog> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final row = await ref
          .read(coTeachersRepositoryProvider)
          .invite(widget.classroomId, _email.text.trim());
      if (mounted) Navigator.of(context).pop(row.email);
    } catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error = apiErrorMessage(e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('เชิญครูร่วมสอน'),
      content: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'ใส่อีเมลที่ครูท่านนั้นใช้สมัคร Krucheck คำเชิญจะขึ้นในหน้าห้องเรียนของครูท่านนั้น',
            ),
            const SizedBox(height: 12),
            TextFormField(
              key: const ValueKey('invite_email'),
              controller: _email,
              autofocus: true,
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
              decoration: const InputDecoration(labelText: 'อีเมลของครู'),
              validator: (v) => (v == null || !v.contains('@'))
                  ? 'กรอกอีเมลให้ถูกต้อง'
                  : null,
              onFieldSubmitted: (_) => _send(),
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                _error!,
                key: const ValueKey('invite_error'),
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('invite_send'),
          onPressed: _busy ? null : _send,
          child: const Text('ส่งคำเชิญ'),
        ),
      ],
    );
  }
}
