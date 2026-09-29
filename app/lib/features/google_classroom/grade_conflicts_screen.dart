import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_providers.dart';
import '../review/review_providers.dart';
import 'assignment_google_section.dart' show openInClassroom;
import 'google_models.dart';
import 'google_providers.dart';
import 'google_repository.dart';

/// "คะแนนไม่ตรงกัน" of one assignment (DESIGN §19.3, §19.11): the teacher
/// changed a grade on the Classroom website, so it differs from the app's
/// total. The app's score is the real one; for each row the teacher sends
/// the app's score to Classroom (app courseWork only), takes Classroom's
/// score as the total, or leaves both as they are.
class GradeConflictsScreen extends ConsumerWidget {
  const GradeConflictsScreen({super.key, required this.assignmentId});

  final int assignmentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rows = ref.watch(gradeConflictsProvider(assignmentId));
    final title = ref
        .watch(assignmentDetailProvider(assignmentId))
        .value
        ?.title;
    Future<void> refresh() =>
        ref.read(gradeConflictsProvider(assignmentId).notifier).refresh();

    return Scaffold(
      appBar: AppBar(
        title: Text(
          title == null ? 'คะแนนไม่ตรงกัน' : 'คะแนนไม่ตรงกัน: $title',
          overflow: TextOverflow.ellipsis,
        ),
        actions: [
          IconButton(
            tooltip: 'โหลดใหม่',
            onPressed: rows.isLoading ? null : refresh,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: switch (rows) {
        AsyncError(:final error) when !rows.hasValue => ErrorView(
          message: googleErrorMessage(error),
          onRetry: refresh,
        ),
        _ when !rows.hasValue => const Center(
          child: CircularProgressIndicator(),
        ),
        _ => RefreshIndicator(
          onRefresh: refresh,
          child: ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
            child: ListView(
              children: [
                if (rows.isLoading) const LinearProgressIndicator(),
                _Intro(rows: rows.value!),
                if (rows.value!.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(24),
                    child: Text(
                      'คะแนนในแอปและใน Classroom ตรงกันทุกคน',
                      key: ValueKey('conflicts_empty'),
                      textAlign: TextAlign.center,
                    ),
                  ),
                for (final c in rows.value!)
                  _ConflictCard(assignmentId: assignmentId, conflict: c),
              ],
            ),
          ),
        ),
      },
    );
  }
}

class _Intro extends StatelessWidget {
  const _Intro({required this.rows});

  final List<GradeConflict> rows;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final open = rows.where((r) => r.isOpen).length;
    final web = rows.isNotEmpty && rows.every((r) => !r.canPushApp);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              open == 0 ? 'ไม่มีรายการรอเลือก' : 'รอเลือก $open รายการ',
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: 4),
            Text(
              'คะแนนในเว็บ Classroom ถูกแก้จนไม่ตรงกับคะแนนรวมในแอป '
              'คะแนนในแอปคือค่าจริง เลือกว่าจะใช้คะแนนฝั่งไหน '
              'ถ้า "ใช้คะแนนจาก Classroom" คะแนนรวมจะเปลี่ยน แต่คะแนนรายข้อและระดับความเข้าใจไม่เปลี่ยน',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            if (web) ...[
              const SizedBox(height: 8),
              Text(
                'งานนี้สร้างในเว็บ Classroom แอปส่งคะแนนกลับให้ไม่ได้ '
                'ถ้าคะแนนในแอปถูก ให้เปิดใน Classroom แล้วแก้คะแนนเอง',
                style: TextStyle(color: theme.colorScheme.tertiary),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _ConflictCard extends ConsumerStatefulWidget {
  const _ConflictCard({required this.assignmentId, required this.conflict});

  final int assignmentId;
  final GradeConflict conflict;

  @override
  ConsumerState<_ConflictCard> createState() => _ConflictCardState();
}

class _ConflictCardState extends ConsumerState<_ConflictCard> {
  bool _busy = false;

  GradeConflict get c => widget.conflict;

  String _score(double? v) => v == null ? '-' : formatScore(v);

  Future<void> _resolve(GradeConflictAction action) async {
    if (action == GradeConflictAction.acceptClassroom) {
      final ok = await confirm(
        context,
        title: 'ใช้คะแนนจาก Classroom?',
        message:
            'คะแนนรวมของ ${c.studentLabel} จะเป็น ${_score(c.classroomScore)} '
            '(ปรับตามที่ครูรับจาก Classroom) นักเรียนเห็นคะแนนรวมนี้ในแอป '
            'คะแนนรายข้อและระดับความเข้าใจไม่เปลี่ยน '
            'ถ้าแก้คะแนนข้อใดภายหลัง คะแนนรวมจะกลับเป็นผลรวมรายข้อ',
        confirmLabel: 'ใช้คะแนนจาก Classroom',
      );
      if (!ok || !mounted) return;
    }
    setState(() => _busy = true);
    try {
      await ref
          .read(gradeConflictsProvider(widget.assignmentId).notifier)
          .resolve(c, action);
      if (action != GradeConflictAction.dismiss) {
        // The effective total changed (or is being sent): the review queue
        // and the published list show it.
        ref.invalidate(reviewQueueProvider(widget.assignmentId));
      }
      if (mounted) {
        showMessage(context, switch (action) {
          GradeConflictAction.pushApp =>
            'กำลังส่งคะแนน ${_score(c.appScore)} ไปที่ Classroom',
          GradeConflictAction.acceptClassroom =>
            'ใช้คะแนน ${_score(c.classroomScore)} จาก Classroom แล้ว',
          GradeConflictAction.dismiss => 'ไม่เปลี่ยนคะแนนทั้งสองฝั่ง',
        });
      }
    } catch (e) {
      if (isGoogleReconnectError(e)) {
        ref.read(googleStatusProvider.notifier).markNeedsReconnect();
      }
      if (apiErrorCode(e) == 'conflict_resolved') {
        ref.invalidate(gradeConflictsProvider(widget.assignmentId));
      }
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Card(
      key: ValueKey('conflict_${c.id}'),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    c.studentLabel,
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                StatusChip(
                  label: c.status.label,
                  color: c.isOpen
                      ? theme.colorScheme.error
                      : Colors.green.shade700,
                ),
                const SizedBox(width: 8),
              ],
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 24,
              runSpacing: 4,
              children: [
                _ScoreBox(label: 'ในแอป', value: _score(c.appScore)),
                _ScoreBox(
                  label: 'ใน Classroom',
                  value: _score(c.classroomScore),
                ),
              ],
            ),
            if (c.detectedAt case final at? when c.isOpen)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('พบเมื่อ ${formatThaiDateTime(at)}', style: muted),
              ),
            if (!c.isOpen)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  [
                    ?c.reason,
                    if (c.resolvedAt case final at?)
                      'เมื่อ ${formatThaiDateTime(at)}',
                  ].join(' · '),
                  style: muted,
                ),
              ),
            if (_busy) ...[
              const SizedBox(height: 8),
              const LinearProgressIndicator(),
            ],
            if (c.isOpen)
              Wrap(
                spacing: 4,
                runSpacing: 4,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  if (c.canPushApp)
                    FilledButton.tonal(
                      key: ValueKey('conflict_push_${c.id}'),
                      onPressed: _busy
                          ? null
                          : () => _resolve(GradeConflictAction.pushApp),
                      child: const Text('ส่งคะแนนจากแอป'),
                    ),
                  OutlinedButton(
                    key: ValueKey('conflict_accept_${c.id}'),
                    onPressed: _busy
                        ? null
                        : () => _resolve(GradeConflictAction.acceptClassroom),
                    child: const Text('ใช้คะแนนจาก Classroom'),
                  ),
                  TextButton(
                    key: ValueKey('conflict_dismiss_${c.id}'),
                    onPressed: _busy
                        ? null
                        : () => _resolve(GradeConflictAction.dismiss),
                    child: const Text('ไม่สนใจ'),
                  ),
                  if (c.alternateLink case final link?)
                    IconButton(
                      tooltip: 'เปิดใน Classroom',
                      onPressed: () => openInClassroom(context, ref, link),
                      icon: const Icon(Icons.open_in_new),
                    ),
                ],
              ),
          ],
        ),
      ),
    );
  }
}

class _ScoreBox extends StatelessWidget {
  const _ScoreBox({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.bodySmall),
        Text(
          value,
          style: theme.textTheme.titleLarge?.copyWith(
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
  }
}
