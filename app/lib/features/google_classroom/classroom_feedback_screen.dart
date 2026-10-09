import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_providers.dart';
import '../review/review_models.dart';
import '../review/review_providers.dart';
import 'assignment_google_section.dart' show openInClassroom, webCourseWorkNote;
import 'google_models.dart';
import 'google_providers.dart';
import 'google_reconnect_banner.dart';
import 'google_repository.dart';
import 'submissions_screen.dart'
    show copyOneScore, copyScores, publishedScore, scoresForClipboard;

/// "ประกาศผลรายคน" of one assignment (DESIGN §19.7, Phase 8 build step 6):
/// every publish of a student's result becomes a private Classroom
/// announcement to that student (the total, the per-question explanations
/// and a link into the app). The screen lists the announcement of each
/// student's latest publish with its delivery state, sends the failed ones
/// again ("ส่งประกาศอีกครั้ง"), and says when the Google account must be
/// connected again for the new announcements scope. For courseWork created
/// on the Classroom website, where the app cannot set grades, it also
/// offers "เปิดใน Classroom" and "คัดลอกคะแนน".
class ClassroomFeedbackScreen extends ConsumerWidget {
  const ClassroomFeedbackScreen({super.key, required this.assignmentId});

  final int assignmentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rows = ref.watch(classroomFeedbackProvider(assignmentId));
    final assignment = ref.watch(assignmentDetailProvider(assignmentId)).value;
    final fromWeb = assignment?.fromClassroomWeb ?? false;
    // Published totals for "คัดลอกคะแนน" (web courseWork only).
    final queue = fromWeb
        ? ref.watch(reviewQueueProvider(assignmentId)).value
        : null;
    final title = assignment?.title;
    Future<void> refresh() async {
      if (fromWeb) ref.invalidate(reviewQueueProvider(assignmentId));
      await ref
          .read(classroomFeedbackProvider(assignmentId).notifier)
          .refresh();
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(
          title == null ? 'ประกาศผลรายคน' : 'ประกาศผล: $title',
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
        _ => FeedbackList(
          assignmentId: assignmentId,
          rows: rows.value!,
          refreshing: rows.isLoading,
          onRefresh: refresh,
          fromClassroomWeb: fromWeb,
          courseWorkLink: assignment?.googleLink?.alternateLink,
          submissions: {
            for (final s in queue?.submissions ?? const <SubmissionSummary>[])
              s.id: s,
          },
        ),
      },
    );
  }
}

/// The list itself (separate so tests can pump it with rows).
class FeedbackList extends ConsumerStatefulWidget {
  const FeedbackList({
    super.key,
    required this.assignmentId,
    required this.rows,
    required this.onRefresh,
    this.refreshing = false,
    this.fromClassroomWeb = false,
    this.courseWorkLink,
    this.submissions = const {},
  });

  final int assignmentId;
  final List<ClassroomFeedbackPost> rows;
  final Future<void> Function() onRefresh;
  final bool refreshing;

  /// CourseWork created on the Classroom website: no grade goes back, so
  /// the teacher copies the scores (DESIGN §19.3).
  final bool fromClassroomWeb;
  final String? courseWorkLink;

  /// Review-queue summaries by submission id (published totals).
  final Map<int, SubmissionSummary> submissions;

  @override
  ConsumerState<FeedbackList> createState() => _FeedbackListState();
}

class _FeedbackListState extends ConsumerState<FeedbackList> {
  bool _busy = false;

  Future<void> _retry() async {
    setState(() => _busy = true);
    try {
      final queued = await ref
          .read(classroomFeedbackProvider(widget.assignmentId).notifier)
          .retryFailed();
      if (!mounted) return;
      showMessage(context, switch (queued) {
        null => 'กำลังส่งประกาศอีกครั้ง',
        0 =>
          'ยังส่งใหม่ไม่ได้ ดูเหตุผลในแต่ละรายการ '
              '(เช่น นักเรียนยังไม่ได้จับคู่บัญชี Google)',
        final n => 'กำลังส่งประกาศอีกครั้ง $n คน',
      });
    } catch (e) {
      ref.read(googleStatusProvider.notifier).noteError(e);
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// "คัดลอกคะแนน" of every published student of the review queue.
  String _allScores() => scoresForClipboard(const [], {
    for (final s in widget.submissions.values)
      if (s.student case final student?) student.id: s,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = widget.rows;
    final status = ref.watch(googleStatusProvider).value;
    final reconnect = status != null && status.connected && !status.ready;
    final counts = {
      for (final s in FeedbackPostState.values)
        s: rows.where((r) => r.state == s).length,
    };
    final failed = counts[FeedbackPostState.failed]!;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final link = widget.courseWorkLink;

    return RefreshIndicator(
      onRefresh: widget.onRefresh,
      child: ContentColumn(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        child: ListView(
          children: [
            if (widget.refreshing || _busy) const LinearProgressIndicator(),
            const GoogleReconnectBanner(),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'ส่งประกาศแล้ว ${counts[FeedbackPostState.posted]}/${rows.length} คน',
                      key: const ValueKey('feedback_summary'),
                      style: theme.textTheme.titleMedium,
                    ),
                    if (rows.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        [
                          for (final s in FeedbackPostState.values)
                            if (counts[s]! > 0) '${s.label} ${counts[s]}',
                        ].join(' · '),
                        key: const ValueKey('feedback_counts'),
                      ),
                    ],
                    const SizedBox(height: 8),
                    Text(
                      'เมื่อเผยแพร่ผล ระบบส่งประกาศส่วนตัวใน Classroom ถึงนักเรียนแต่ละคน '
                      '(เห็นเฉพาะนักเรียนคนนั้นและครูของคอร์ส) มีคะแนนรวม คำอธิบายรายข้อ '
                      'และลิงก์เปิดผลในแอป Krucheck ส่งเฉพาะนักเรียนที่จับคู่บัญชี Google แล้ว',
                      style: muted,
                    ),
                    if (counts[FeedbackPostState.queued]! > 0) ...[
                      const SizedBox(height: 4),
                      Text(
                        'รายการ "รอส่ง" จะถูกส่งภายในไม่กี่นาที กดโหลดใหม่เพื่อดูสถานะ',
                        style: muted,
                      ),
                    ],
                    if (widget.fromClassroomWeb) ...[
                      const SizedBox(height: 8),
                      Text(
                        '$webCourseWorkNote แต่ส่งประกาศผลรายคนได้ '
                        'กด "คัดลอกคะแนน" แล้วกรอกคะแนนในเว็บ Classroom เอง',
                        key: const ValueKey('web_coursework_note'),
                        style: TextStyle(color: theme.colorScheme.tertiary),
                      ),
                    ],
                    if (failed > 0 && reconnect) ...[
                      const SizedBox(height: 8),
                      Text(
                        'เชื่อมบัญชี Google ใหม่ก่อน แล้วจึงกด "ส่งประกาศอีกครั้ง"',
                        key: const ValueKey('feedback_reconnect_first'),
                        style: TextStyle(color: theme.colorScheme.error),
                      ),
                    ],
                    const SizedBox(height: 12),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        if (failed > 0)
                          FilledButton.icon(
                            key: const ValueKey('retry_feedback'),
                            onPressed: _busy || reconnect ? null : _retry,
                            icon: const Icon(Icons.replay),
                            label: Text('ส่งประกาศอีกครั้ง ($failed)'),
                          ),
                        if (widget.fromClassroomWeb)
                          OutlinedButton.icon(
                            key: const ValueKey('copy_scores'),
                            onPressed: () => copyScores(context, _allScores()),
                            icon: const Icon(Icons.content_copy),
                            label: const Text('คัดลอกคะแนน'),
                          ),
                        if (link != null && link.isNotEmpty)
                          OutlinedButton.icon(
                            key: const ValueKey('open_in_classroom'),
                            onPressed: () =>
                                openInClassroom(context, ref, link),
                            icon: const Icon(Icons.open_in_new),
                            label: const Text('เปิดใน Classroom'),
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
                  'ยังไม่มีประกาศผล ระบบส่งประกาศเมื่อเผยแพร่ผลของนักเรียน '
                  'ที่จับคู่บัญชี Google แล้ว',
                  key: ValueKey('feedback_empty'),
                  textAlign: TextAlign.center,
                ),
              ),
            for (final row in rows)
              _FeedbackCard(
                row: row,
                score: widget.fromClassroomWeb
                    ? publishedScore(widget.submissions[row.submissionId])
                    : null,
              ),
          ],
        ),
      ),
    );
  }
}

class _FeedbackCard extends StatelessWidget {
  const _FeedbackCard({required this.row, this.score});

  final ClassroomFeedbackPost row;

  /// The published total to type into Classroom (web courseWork only).
  final double? score;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final score = this.score;
    return Card(
      key: ValueKey('feedback_${row.id}'),
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
                  key: ValueKey('feedback_state_${row.id}'),
                  label: row.state.label,
                  color: row.state.color(theme.colorScheme),
                ),
                const SizedBox(width: 8),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              [
                if (row.publishedAt case final at?)
                  'เผยแพร่ ${formatThaiDateTime(at)}',
                if (row.postedAt case final at?)
                  'ส่งประกาศ ${formatThaiDateTime(at)}',
              ].join(' · '),
              style: muted,
            ),
            if (row.failed)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  'ส่งไม่สำเร็จ: ${row.lastError ?? 'ไม่ทราบสาเหตุ'}',
                  key: ValueKey('feedback_error_${row.id}'),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            if (score != null)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  key: ValueKey('feedback_copy_score_${row.id}'),
                  onPressed: () =>
                      copyOneScore(context, row.studentLabel, score),
                  icon: const Icon(Icons.content_copy),
                  label: Text('คัดลอกคะแนน ${formatScore(score)}'),
                ),
              )
            else
              const SizedBox(height: 4),
          ],
        ),
      ),
    );
  }
}
