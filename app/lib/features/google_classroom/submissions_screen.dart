import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_providers.dart';
import 'assignment_google_section.dart' show copyLink;
import 'classroom_importer.dart';
import 'google_auth.dart';
import 'google_models.dart';
import 'google_providers.dart';
import 'google_repository.dart';

/// Classroom submissions of one assignment (DESIGN §18.2, §18.7): states
/// from the server, "ดาวน์โหลดและสแกน" for all or one (on this phone), the
/// result of every picture, "ตีกลับให้ถ่ายใหม่" and "ส่งคะแนนกลับอีกครั้ง".
class GoogleSubmissionsScreen extends ConsumerWidget {
  const GoogleSubmissionsScreen({super.key, required this.assignmentId});

  final int assignmentId;

  Future<void> _refresh(WidgetRef ref) =>
      ref.read(googleSubmissionsProvider(assignmentId).notifier).refresh();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rows = ref.watch(googleSubmissionsProvider(assignmentId));
    final running = ref.watch(
      classroomImportProvider(assignmentId).select((s) => s.running),
    );
    final title = ref
        .watch(assignmentDetailProvider(assignmentId))
        .value
        ?.title;

    return Scaffold(
      appBar: AppBar(
        title: Text(
          title == null ? 'งานที่ส่งใน Classroom' : 'งานที่ส่ง: $title',
        ),
        actions: [
          IconButton(
            tooltip: 'ดึงงานที่ส่งอีกครั้ง',
            onPressed: running || rows.isLoading ? null : () => _refresh(ref),
            icon: const Icon(Icons.refresh),
          ),
          IconButton(
            tooltip: 'คิวอัปโหลด',
            onPressed: () => context.push(AppRoutes.uploadQueue),
            icon: const Icon(Icons.cloud_upload_outlined),
          ),
        ],
      ),
      body: switch (rows) {
        AsyncError(:final error) when !rows.hasValue => ErrorView(
          message: googleErrorMessage(error),
          onRetry: () => _refresh(ref),
        ),
        _ when !rows.hasValue => const Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircularProgressIndicator(),
              SizedBox(height: 16),
              Text('กำลังดึงงานที่ส่งจาก Google Classroom…'),
            ],
          ),
        ),
        _ => SubmissionsList(
          assignmentId: assignmentId,
          rows: rows.value!,
          refreshing: rows.isLoading,
          onRefresh: () => _refresh(ref),
        ),
      },
    );
  }
}

/// The list itself (separate so tests can pump it with rows).
class SubmissionsList extends ConsumerWidget {
  const SubmissionsList({
    super.key,
    required this.assignmentId,
    required this.rows,
    required this.onRefresh,
    this.refreshing = false,
  });

  final int assignmentId;
  final List<GoogleSubmission> rows;
  final Future<void> Function() onRefresh;
  final bool refreshing;

  Future<void> _run(
    BuildContext context,
    WidgetRef ref,
    List<GoogleSubmission> targets,
  ) async {
    GoogleStatus? status;
    try {
      // The Drive token must belong to the account connected on the server.
      status = await ref.read(googleStatusProvider.future);
    } catch (_) {
      status = null; // the server will say if the connection is gone
    }
    if (!context.mounted) return;
    if (status != null && !status.ready) {
      showMessage(
        context,
        status.connected
            ? 'ต้องเชื่อมบัญชี Google ใหม่ก่อน (ตั้งค่า → Google Classroom)'
            : 'เชื่อมบัญชี Google ก่อน (ตั้งค่า → Google Classroom)',
      );
      return;
    }
    try {
      await ref
          .read(classroomImportProvider(assignmentId).notifier)
          .run(targets, expectedEmail: status?.email);
      if (!context.mounted) return;
      final state = ref.read(classroomImportProvider(assignmentId));
      final results = [for (final t in targets) ?state.rows[t.id]?.result];
      final pages = results.fold<int>(0, (n, r) => n + r.queuedCount);
      final skipped = results.fold<int>(
        0,
        (n, r) => n + r.outcomes.whereType<ImageAlreadyQueued>().length,
      );
      final problems = results.where((r) => r.hasProblems).length;
      final unmatched = results.where((r) => r.needsMatchCount > 0).length;
      showMessage(
        context,
        [
          'สแกนแล้ว $pages หน้า',
          if (skipped > 0) 'ข้าม $skipped หน้าที่อยู่ในคิวแล้ว',
          if (problems > 0) 'มี $problems งานที่ต้องให้นักเรียนถ่ายใหม่',
          if (unmatched > 0) 'มี $unmatched งานที่ต้องจับคู่นักเรียนก่อน',
          if (problems == 0 && unmatched == 0) 'กำลังอัปโหลด',
        ].join(' '),
      );
    } on ClassroomImportStopped catch (e) {
      // Part of the batch ran; the rows show what was done.
      if (context.mounted) showMessage(context, e.message);
    } on GoogleAuthCanceled {
      // The teacher closed the picker before anything ran.
    } on GoogleAuthException catch (e) {
      if (context.mounted) showMessage(context, e.message);
    } catch (e) {
      if (context.mounted) showMessage(context, googleErrorMessage(e));
    }
  }

  Future<void> _retryGrades(BuildContext context, WidgetRef ref) async {
    try {
      final queued = await ref
          .read(googleSubmissionsProvider(assignmentId).notifier)
          .retryGrades();
      if (context.mounted) {
        showMessage(
          context,
          queued == null
              ? 'กำลังส่งคะแนนกลับอีกครั้ง'
              : 'กำลังส่งคะแนนกลับอีกครั้ง $queued คน',
        );
      }
    } catch (e) {
      if (isGoogleReconnectError(e)) {
        ref.read(googleStatusProvider.notifier).markNeedsReconnect();
      }
      if (context.mounted) showMessage(context, googleErrorMessage(e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final import = ref.watch(classroomImportProvider(assignmentId));
    final supported = ref.watch(classroomImporterProvider).isSupported;
    final waiting = rows.where((r) => r.state.awaitsScan).toList();
    final failedGrades = rows
        .where((r) => r.state == SubmissionImportState.gradeFailed)
        .length;
    final counts = <SubmissionImportState, int>{};
    for (final r in rows) {
      counts[r.state] = (counts[r.state] ?? 0) + 1;
    }

    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ContentColumn(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        child: ListView(
          children: [
            if (refreshing) const LinearProgressIndicator(),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'ส่งใน Classroom ${rows.length} คน',
                      style: theme.textTheme.titleMedium,
                    ),
                    if (counts.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        [
                          for (final s in SubmissionImportState.values)
                            if (counts[s] case final n?) '${s.label} $n',
                        ].join(' · '),
                        key: const ValueKey('submission_counts'),
                      ),
                    ],
                    const SizedBox(height: 8),
                    Text(
                      supported
                          ? 'รูปถูกดาวน์โหลดจาก Google Drive มาที่เครื่องนี้ ตัดภาพแล้วอัปโหลดผ่านคิวอัปโหลดตามปกติ '
                                'รูปที่ใช้ไม่ได้จะแสดงเหตุผลให้ตีกลับให้นักเรียนถ่ายใหม่'
                          : 'ดาวน์โหลดและสแกนได้เฉพาะในแอป Android บนมือถือหรือแท็บเล็ต',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    if (import.batch case final batch?) ...[
                      const SizedBox(height: 12),
                      const LinearProgressIndicator(),
                      const SizedBox(height: 4),
                      Text('กำลังดาวน์โหลดและสแกน $batch'),
                    ],
                    const SizedBox(height: 12),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        FilledButton.icon(
                          key: const ValueKey('import_all'),
                          onPressed:
                              !supported || import.running || waiting.isEmpty
                              ? null
                              : () => _run(context, ref, waiting),
                          icon: const Icon(Icons.document_scanner_outlined),
                          label: Text(
                            'ดาวน์โหลดและสแกนทั้งหมด (${waiting.length})',
                          ),
                        ),
                        if (failedGrades > 0)
                          OutlinedButton.icon(
                            key: const ValueKey('retry_grades'),
                            onPressed: () => _retryGrades(context, ref),
                            icon: const Icon(Icons.replay),
                            label: Text('ส่งคะแนนกลับอีกครั้ง ($failedGrades)'),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            if (rows.isEmpty)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'ยังไม่มีนักเรียนส่งงานใน Classroom ที่มีไฟล์แนบ',
                  textAlign: TextAlign.center,
                ),
              ),
            for (final row in rows)
              _SubmissionCard(
                assignmentId: assignmentId,
                row: row,
                import: import.rows[row.id],
                canScan: supported && !import.running,
                onScan: () => _run(context, ref, [row]),
              ),
          ],
        ),
      ),
    );
  }
}

class _SubmissionCard extends ConsumerWidget {
  const _SubmissionCard({
    required this.assignmentId,
    required this.row,
    required this.import,
    required this.canScan,
    required this.onScan,
  });

  final int assignmentId;
  final GoogleSubmission row;
  final ImportRow? import;
  final bool canScan;
  final VoidCallback onScan;

  Future<void> _returnForRetake(BuildContext context, WidgetRef ref) async {
    final suggestion =
        import?.result?.problems.join(' ') ?? row.retakeReason ?? '';
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => _RetakeDialog(
        student: row.studentLabel,
        initial: suggestion.length > 255
            ? suggestion.substring(0, 255)
            : suggestion,
      ),
    );
    if (reason == null || !context.mounted) return;
    try {
      await ref
          .read(googleSubmissionsProvider(assignmentId).notifier)
          .returnForRetake(row, reason);
      if (context.mounted) {
        showMessage(context, 'ส่งคืนงานใน Classroom และแจ้งนักเรียนแล้ว');
      }
    } catch (e) {
      if (isGoogleReconnectError(e)) {
        ref.read(googleStatusProvider.notifier).markNeedsReconnect();
      }
      if (context.mounted) showMessage(context, googleErrorMessage(e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final result = import?.result;
    final running = import?.running ?? false;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Card(
      key: ValueKey('submission_${row.id}'),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    row.studentLabel,
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                StatusChip(
                  label: row.state.label,
                  color: row.state.color(theme.colorScheme),
                ),
                const SizedBox(width: 8),
              ],
            ),
            if (row.student == null)
              Text(
                'บัญชีนี้ยังไม่ได้จับคู่กับนักเรียนในห้อง สแกนได้ถ้าใบงานมี QR ของนักเรียน',
                style: muted,
              ),
            const SizedBox(height: 4),
            Text(
              row.attachments.isEmpty
                  ? 'ไม่มีไฟล์แนบ'
                  : 'ไฟล์แนบ ${row.attachments.length} ไฟล์: '
                        '${row.attachments.map((a) => a.title).join(', ')}',
              style: muted,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            if (row.retakeReason case final reason?)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('เหตุผลที่ตีกลับ: $reason'),
              ),
            if (row.state == SubmissionImportState.returnedForRetake)
              Text(
                'รอนักเรียนส่งรูปใหม่ใน Classroom แล้วกด "ดึงงานที่ส่ง" อีกครั้ง',
                style: muted,
              ),
            if (row.state == SubmissionImportState.gradeFailed &&
                row.lastError != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  'ส่งคะแนนไม่สำเร็จ: ${row.lastError}',
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            if (running) ...[
              const SizedBox(height: 8),
              const LinearProgressIndicator(),
              const SizedBox(height: 4),
              Text(import?.status ?? 'กำลังทำงาน…', style: muted),
            ],
            if (result?.interruption case final stop?)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  'ยังไม่เสร็จ: $stop',
                  key: ValueKey('interrupted_${row.id}'),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            if (result != null) ...[
              const SizedBox(height: 8),
              for (final outcome in result.outcomes)
                _OutcomeTile(
                  outcome: outcome,
                  onAccept: outcome is ImageRejected && outcome.canOverride
                      ? () => ref
                            .read(
                              classroomImportProvider(assignmentId).notifier,
                            )
                            .acceptDespiteBlur(row, outcome)
                      : null,
                ),
              if (result.outcomes.isEmpty && result.isComplete)
                const _OutcomeTile(
                  outcome: AttachmentFailed('งานนี้', reason: 'ไม่มีไฟล์แนบ'),
                ),
            ],
            Wrap(
              spacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                TextButton.icon(
                  key: ValueKey('scan_${row.id}'),
                  onPressed:
                      canScan && row.state.canScan && row.attachments.isNotEmpty
                      ? onScan
                      : null,
                  icon: const Icon(Icons.document_scanner_outlined),
                  label: Text(
                    result == null && row.state.awaitsScan
                        ? 'ดาวน์โหลดและสแกน'
                        : 'สแกนอีกครั้ง',
                  ),
                ),
                if (row.state.canReturnForRetake)
                  (result?.hasProblems ?? false)
                      ? FilledButton.tonalIcon(
                          key: ValueKey('return_${row.id}'),
                          onPressed: running
                              ? null
                              : () => _returnForRetake(context, ref),
                          icon: const Icon(Icons.assignment_return_outlined),
                          label: const Text('ตีกลับให้ถ่ายใหม่'),
                        )
                      : TextButton.icon(
                          key: ValueKey('return_${row.id}'),
                          onPressed: running
                              ? null
                              : () => _returnForRetake(context, ref),
                          icon: const Icon(Icons.assignment_return_outlined),
                          label: const Text('ตีกลับให้ถ่ายใหม่'),
                        ),
                if (row.alternateLink case final link?)
                  IconButton(
                    tooltip: 'คัดลอกลิงก์ของงานนี้ใน Classroom',
                    onPressed: () => copyLink(context, link),
                    icon: const Icon(Icons.link),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _OutcomeTile extends StatelessWidget {
  const _OutcomeTile({required this.outcome, this.onAccept});

  final ImageOutcome outcome;
  final VoidCallback? onAccept;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ok = Colors.green.shade700;
    final (icon, color, lines) = switch (outcome) {
      ImageQueued(:final student, :final page, :final identityNote) => (
        Icons.check_circle_outline,
        ok,
        ['ผ่าน: $student หน้า $page (อยู่ในคิวอัปโหลด)', ?identityNote],
      ),
      ImageAlreadyQueued(:final student, :final page) => (
        Icons.playlist_add_check,
        theme.colorScheme.tertiary,
        ['ข้าม: $student หน้า $page รออยู่ในคิวอัปโหลดแล้ว ไม่ได้เพิ่มซ้ำ'],
      ),
      ImageNeedsMatch(:final reason) => (
        Icons.person_search_outlined,
        theme.colorScheme.tertiary,
        ['ยังสแกนไม่ได้: $reason'],
      ),
      ImageWaitingLayout(:final page) => (
        Icons.cloud_off_outlined,
        theme.colorScheme.tertiary,
        ['หน้า $page เก็บไว้ในคิว จะตัดภาพเมื่อโหลด layout ได้'],
      ),
      ImageRejected(:final reasons) => (
        Icons.error_outline,
        theme.colorScheme.error,
        ['ต้องถ่ายใหม่: ${reasons.join(' ')}'],
      ),
      AttachmentFailed(:final reason) => (
        Icons.error_outline,
        theme.colorScheme.error,
        ['ใช้ไม่ได้: $reason'],
      ),
    };
    return Padding(
      padding: const EdgeInsets.only(bottom: 6, right: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 2, right: 8),
            child: Icon(icon, size: 18, color: color),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(outcome.label, style: theme.textTheme.labelLarge),
                for (final line in lines) Text(line),
                if (onAccept != null)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton(
                      onPressed: onAccept,
                      child: const Text('ภาพไม่คมแต่อ่านได้ ใช้ภาพนี้ต่อ'),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _RetakeDialog extends StatefulWidget {
  const _RetakeDialog({required this.student, required this.initial});

  final String student;
  final String initial;

  @override
  State<_RetakeDialog> createState() => _RetakeDialogState();
}

class _RetakeDialogState extends State<_RetakeDialog> {
  late final _reason = TextEditingController(text: widget.initial);
  String? _error;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  void _submit() {
    final text = _reason.text.trim();
    if (text.isEmpty) {
      setState(() => _error = 'บอกนักเรียนว่าต้องถ่ายใหม่เพราะอะไร');
      return;
    }
    Navigator.of(context).pop(text);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text('ตีกลับให้ ${widget.student} ถ่ายใหม่?'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'งานจะถูกส่งคืนใน Classroom ให้นักเรียนส่งใหม่ได้ '
              'และนักเรียนเห็นเหตุผลนี้ในแอป EduVision',
            ),
            const SizedBox(height: 12),
            TextField(
              key: const ValueKey('retake_reason'),
              controller: _reason,
              maxLines: 3,
              maxLength: 255,
              decoration: InputDecoration(
                labelText: 'เหตุผล',
                border: const OutlineInputBorder(),
                errorText: _error,
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('retake_confirm'),
          onPressed: _submit,
          child: const Text('ตีกลับ'),
        ),
      ],
    );
  }
}
