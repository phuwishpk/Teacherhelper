import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/answer_key_models.dart';
import 'hand_in_files.dart';
import 'hand_in_models.dart';
import 'hand_in_repository.dart';
import 'student_assignments_page.dart';

/// "ส่งงาน" of one assignment (DESIGN §19.6): photos from the camera or
/// images/PDFs from the device, then one upload with a progress bar. The
/// student sees only that it was handed in and when; the score comes once
/// the teacher publishes.
class StudentHandInScreen extends ConsumerWidget {
  const StudentHandInScreen({
    super.key,
    required this.assignmentId,
    this.initial,
    this.now,
  });

  final int assignmentId;

  /// The row of "งานที่ต้องส่ง" when opened from it.
  final StudentAssignment? initial;
  final DateTime Function()? now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final initial = this.initial;
    if (initial != null) {
      return _HandInForm(assignment: initial, now: now);
    }
    final list = ref.watch(studentAssignmentsProvider);
    return AsyncView(
      value: list,
      onRetry: () => ref.invalidate(studentAssignmentsProvider),
      data: (items) {
        final match = items.where((a) => a.id == assignmentId).firstOrNull;
        if (match == null) {
          return Scaffold(
            appBar: AppBar(title: const Text('ส่งงาน')),
            body: const EmptyView(
              icon: Icons.assignment_late_outlined,
              title: 'ไม่พบการบ้านนี้',
              message: 'การบ้านอาจปิดรับแล้ว หรือไม่ใช่งานของห้องเรียนของเรา',
            ),
          );
        }
        return _HandInForm(assignment: match, now: now);
      },
    );
  }
}

class _HandInForm extends ConsumerStatefulWidget {
  const _HandInForm({required this.assignment, this.now});

  final StudentAssignment assignment;
  final DateTime Function()? now;

  @override
  ConsumerState<_HandInForm> createState() => _HandInFormState();
}

class _HandInFormState extends ConsumerState<_HandInForm> {
  var _files = <PickedDocument>[];
  bool _sending = false;
  double? _progress;
  String? _error;
  HandInReceipt? _receipt;

  StudentAssignment get _a => widget.assignment;

  Future<void> _send() async {
    if (_sending || handInFilesProblem(_files) != null) return;
    if (_a.isSubmitted) {
      final ok = await confirm(
        context,
        title: 'ส่งงานใหม่?',
        message:
            'ส่งงานนี้ไปแล้ว ถ้าส่งใหม่ ครูจะตรวจงานที่ส่งล่าสุดแทนงานเดิม',
        confirmLabel: 'ส่งใหม่',
      );
      if (!ok || !mounted) return;
    }
    setState(() {
      _sending = true;
      _progress = 0;
      _error = null;
    });
    try {
      final receipt = await ref
          .read(handInRepositoryProvider)
          .submit(
            _a.id,
            _files,
            onProgress: (sent, total) {
              if (!mounted) return;
              setState(() => _progress = progressShare(sent, total));
            },
          );
      if (!mounted) return;
      ref.invalidate(studentAssignmentsProvider);
      setState(() {
        _receipt = receipt;
        _files = [];
      });
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final now = (widget.now ?? DateTime.now)();
    final a = _a;
    final receipt = _receipt;
    final closed = !a.canSubmit && receipt == null;
    final chips = studentAssignmentChips(a, now, scheme);

    return Scaffold(
      appBar: AppBar(title: const Text('ส่งงาน')),
      body: FormColumn(
        children: [
          Card(
            child: ListTile(
              title: Text(a.title, style: theme.textTheme.titleMedium),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    [
                      ?a.subjectName,
                      ?a.classroomName,
                      if (a.dueAt != null)
                        'กำหนดส่ง ${formatThaiDateTime(a.dueAt!)}'
                      else
                        'ไม่มีกำหนดส่ง',
                    ].join(' · '),
                  ),
                  if (chips.isNotEmpty && receipt == null) ...[
                    const SizedBox(height: 6),
                    Wrap(spacing: 6, runSpacing: 4, children: chips),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          if (receipt != null)
            _Receipt(receipt: receipt, onDone: () => context.pop())
          else if (closed)
            Card(
              color: scheme.errorContainer,
              child: const ListTile(
                leading: Icon(Icons.lock_clock_outlined),
                title: Text('ปิดรับงานแล้ว'),
                subtitle: Text(
                  'เลยกำหนดส่งและการบ้านนี้ไม่รับงานส่งช้า ติดต่อครูผู้สอน',
                ),
              ),
            )
          else ...[
            if (a.isOverdue(now))
              Card(
                color: scheme.tertiaryContainer,
                child: const ListTile(
                  leading: Icon(Icons.schedule),
                  title: Text('เลยกำหนดส่งแล้ว'),
                  subtitle: Text('ยังส่งได้ แต่งานจะติดป้าย "ส่งช้า"'),
                ),
              ),
            Text('รูปหรือไฟล์งาน', style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(
              'ถ่ายให้เห็นทั้งหน้าและอ่านออก เรียงตามลำดับหน้า',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            HandInFilesPanel(
              files: _files,
              enabled: !_sending,
              pickerTitle: 'เลือกรูปหรือ PDF ของงาน',
              onChanged: (files) => setState(() {
                _files = files;
                _error = null;
              }),
            ),
            const SizedBox(height: 16),
            if (_sending) ...[
              UploadProgressBar(progress: _progress),
              const SizedBox(height: 12),
            ],
            if (_error case final error?) ...[
              Text(error, style: TextStyle(color: scheme.error)),
              const SizedBox(height: 12),
            ],
            FilledButton.icon(
              key: const ValueKey('hand_in_send'),
              onPressed: _sending || handInFilesProblem(_files) != null
                  ? null
                  : _send,
              icon: const Icon(Icons.send),
              label: Text(a.isSubmitted ? 'ส่งงานใหม่' : 'ส่งงาน'),
            ),
          ],
        ],
      ),
    );
  }
}

class _Receipt extends StatelessWidget {
  const _Receipt({required this.receipt, required this.onDone});

  final HandInReceipt receipt;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final at = receipt.submittedAt;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            Icon(Icons.task_alt, size: 56, color: theme.colorScheme.primary),
            const SizedBox(height: 12),
            Text('ส่งงานแล้ว', style: theme.textTheme.titleLarge),
            const SizedBox(height: 4),
            Text(
              [
                if (at != null) 'เวลา ${formatThaiDateTime(at)}',
                if (receipt.pages > 0) '${receipt.pages} หน้า',
              ].join(' · '),
            ),
            if (receipt.late) ...[
              const SizedBox(height: 8),
              StatusChip(label: 'ส่งช้า', color: theme.colorScheme.error),
            ],
            const SizedBox(height: 8),
            Text(
              'ครูจะตรวจและประกาศผล แล้วคะแนนจะขึ้นในแท็บ "ผลการบ้าน"',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: onDone, child: const Text('เสร็จ')),
          ],
        ),
      ),
    );
  }
}
