import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_providers.dart';
import '../review/review_models.dart';
import '../review/review_providers.dart';
import '../review/review_queue_screen.dart' show reviewActionError;
import '../home/teacher_attention.dart';
import 'assignment_google_section.dart'
    show copyLink, openInClassroom, webCourseWorkNote;
import 'google_models.dart';
import 'google_providers.dart';
import 'google_reconnect_banner.dart';
import 'google_repository.dart';

/// Classroom submissions of one assignment (DESIGN §18.7, §19.4). Since
/// Phase 8 the server downloads each hand-in from Drive and grades it from
/// the whole page, so nothing is downloaded or scanned on this phone (and
/// the screen works the same on the web). Each row shows two things: the
/// sync state of the Classroom hand-in (downloaded, file unusable, late,
/// waiting for the answer key, grade sent back) and, once the server holds
/// the files, the grading state of the student's submission from the review
/// queue, with "ตรวจ" for a new hand-in that waits for the teacher
/// (`regrade_pending`), "ตีกลับให้ถ่ายใหม่", "ส่งคะแนนกลับอีกครั้ง" and
/// "รับงานส่งช้า" for a hand-in the late policy refused (§19.3). For
/// courseWork created on the Classroom website the app cannot set grades,
/// so the screen offers "เปิดใน Classroom" and "คัดลอกคะแนน" instead.
/// "ประกาศผลรายคน" opens the private announcements (§19.7).
class GoogleSubmissionsScreen extends ConsumerWidget {
  const GoogleSubmissionsScreen({super.key, required this.assignmentId});

  final int assignmentId;

  Future<void> _refresh(WidgetRef ref) async {
    ref.invalidate(reviewQueueProvider(assignmentId));
    await ref.read(googleSubmissionsProvider(assignmentId).notifier).refresh();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rows = ref.watch(googleSubmissionsProvider(assignmentId));
    // Grading progress per student; the list works without it.
    final queue = ref.watch(reviewQueueProvider(assignmentId));
    final assignment = ref.watch(assignmentDetailProvider(assignmentId)).value;
    final title = assignment?.title;

    return Scaffold(
      appBar: AppBar(
        title: Text(
          title == null ? 'งานที่ส่งใน Classroom' : 'งานที่ส่ง: $title',
        ),
        actions: [
          IconButton(
            tooltip: 'ดึงงานที่ส่งอีกครั้ง',
            onPressed: rows.isLoading ? null : () => _refresh(ref),
            icon: const Icon(Icons.refresh),
          ),
          IconButton(
            tooltip: 'ตรวจทาน',
            onPressed: () => context.push(AppRoutes.review(assignmentId)),
            icon: const Icon(Icons.rate_review_outlined),
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
          submissions: {
            for (final s in queue.value?.submissions ?? const [])
              if (s.student case final student?) student.id: s,
          },
          refreshing: rows.isLoading || queue.isLoading,
          onRefresh: () => _refresh(ref),
          fromClassroomWeb: assignment?.fromClassroomWeb ?? false,
          courseWorkLink: assignment?.googleLink?.alternateLink,
        ),
      },
    );
  }
}

/// What the teacher reads about one row: where the hand-in stands.
typedef RowProgress = ({String label, Color color, String? detail});

/// The grading side of a row (DESIGN §19.4): from the Classroom sync state
/// until the server holds the files, then from the student's submission.
RowProgress rowProgress(
  GoogleSubmission row,
  SubmissionSummary? submission,
  ColorScheme scheme,
) {
  final muted = scheme.outline;
  switch (row.state) {
    case SubmissionImportState.newSubmission:
      if (row.student == null) {
        return (
          label: 'ยังไม่ได้จับคู่นักเรียน',
          color: scheme.tertiary,
          detail:
              'บัญชีนี้ยังไม่ได้จับคู่กับนักเรียนในห้อง จับคู่ที่หน้าห้องเรียนก่อน '
              'แล้วเซิร์ฟเวอร์จะดาวน์โหลดไฟล์มาตรวจให้',
        );
      }
      return (
        label: 'รอดาวน์โหลด',
        color: muted,
        detail: row.lastError == null
            ? 'เซิร์ฟเวอร์จะดาวน์โหลดไฟล์จาก Google Drive แล้วให้ AI ตรวจ'
            : 'ยังดาวน์โหลดไม่ได้: ${row.lastError}',
      );
    case SubmissionImportState.waitingKey:
      return (
        label: 'รออนุมัติเฉลย',
        color: scheme.primary,
        detail: 'เก็บไฟล์ไว้แล้ว จะตรวจเมื่อครูอนุมัติเฉลยของการบ้านนี้',
      );
    case SubmissionImportState.unsupported:
      return (
        label: 'ตรวจไม่ได้',
        color: scheme.error,
        detail:
            'ใช้ไม่ได้: ${row.lastError ?? 'ไฟล์ชนิดนี้ตรวจไม่ได้'} '
            'ให้นักเรียนส่งเป็นรูปหรือ PDF ใหม่ใน Classroom',
      );
    case SubmissionImportState.rejectedLate:
      return (
        label: 'ไม่รับงานส่งช้า',
        color: scheme.tertiary,
        detail:
            'ส่งหลังกำหนด และการบ้านนี้ไม่รับงานส่งช้า จึงไม่ได้ตรวจ '
            'กด "รับงานส่งช้า" ถ้าจะตรวจงานนี้',
      );
    case SubmissionImportState.returnedForRetake:
      return (
        label: 'รอส่งใหม่',
        color: scheme.tertiary,
        detail:
            'รอนักเรียนส่งรูปใหม่ใน Classroom งานที่ส่งใหม่หลังตีกลับจะถูกตรวจอัตโนมัติ',
      );
    case SubmissionImportState.needsRetake:
      return (
        label: 'ต้องถ่ายใหม่',
        color: scheme.error,
        detail: 'รูปนี้ใช้ไม่ได้ ตีกลับให้นักเรียนถ่ายใหม่',
      );
    case SubmissionImportState.imported ||
        SubmissionImportState.graded ||
        SubmissionImportState.gradeFailed:
      if (submission == null) {
        return (label: 'รับไฟล์แล้ว', color: muted, detail: 'กำลังเริ่มตรวจ');
      }
      if (submission.regradePending) {
        return (
          label: 'ส่งใหม่ รอครูกดตรวจ',
          color: scheme.tertiary,
          detail:
              'นักเรียนส่งงานใหม่ ผลเดิมยังอยู่จนกว่าครูจะกด "ตรวจ" '
              '(AI อ่านใหม่ทั้งหน้า)',
        );
      }
      return switch (submission.status) {
        'grading' => (label: 'AI กำลังตรวจ', color: muted, detail: null),
        'published' => (
          label: 'เผยแพร่ผลแล้ว',
          color: Colors.green.shade700,
          detail: submission.totalScore == null
              ? null
              : 'คะแนนรวม ${formatScore(submission.totalScore!)}'
                    '${submission.totalOverridden ? ' (รับจาก Classroom)' : ''}',
        ),
        'reviewed' => (
          label: 'ตรวจทานครบ รอเผยแพร่',
          color: scheme.primary,
          detail: null,
        ),
        'needs_review' => (
          label: 'รอครูตรวจทาน',
          color: scheme.primary,
          detail:
              'AI ตรวจแล้ว ตรวจทานแล้ว ${submission.reviewedCount}/${submission.responseCount} ข้อ',
        ),
        _ => (label: 'รอตรวจ', color: muted, detail: null),
      };
  }
}

/// The effective total to type into Classroom: only once published.
double? publishedScore(SubmissionSummary? submission) =>
    submission != null && submission.isPublished ? submission.totalScore : null;

/// "คัดลอกคะแนน" of the whole list: one line per published student,
/// `เลขที่<TAB>ชื่อ<TAB>คะแนน` in student-number order, ready to read off
/// while typing grades on the Classroom website. Students come from the
/// Classroom hand-ins ([rows]) and from every other submission of the
/// review queue that names its student (work handed in through the app or
/// uploaded by the teacher, DESIGN §19.6), each once.
String scoresForClipboard(
  List<GoogleSubmission> rows,
  Map<int, SubmissionSummary> submissions,
) {
  final lines = <int, (int, String)>{};
  void add(int studentId, String name, int? number, double? score) {
    if (score == null || lines.containsKey(studentId)) return;
    lines[studentId] = (
      number ?? 1 << 30,
      '${number ?? '-'}\t$name\t${formatScore(score)}',
    );
  }

  for (final r in rows) {
    final student = r.student;
    if (student == null) continue;
    add(
      student.id,
      student.name,
      student.studentNumber,
      publishedScore(submissions[student.id]),
    );
  }
  for (final s in submissions.values) {
    final student = s.student;
    if (student == null) continue;
    add(student.id, student.name, student.studentNumber, publishedScore(s));
  }
  final sorted = lines.values.toList()..sort((a, b) => a.$1.compareTo(b.$1));
  return sorted.map((l) => l.$2).join('\n');
}

/// Copies [text] from [scoresForClipboard] and says how many students.
Future<void> copyScores(BuildContext context, String text) async {
  if (text.isEmpty) {
    showMessage(context, 'ยังไม่มีคะแนนที่เผยแพร่แล้วให้คัดลอก');
    return;
  }
  await Clipboard.setData(ClipboardData(text: text));
  if (context.mounted) {
    showMessage(
      context,
      'คัดลอกคะแนน ${text.split('\n').length} คนแล้ว (เลขที่ ชื่อ คะแนน)',
    );
  }
}

/// "คัดลอกคะแนน" of one student.
Future<void> copyOneScore(
  BuildContext context,
  String studentLabel,
  double score,
) async {
  await Clipboard.setData(ClipboardData(text: formatScore(score)));
  if (context.mounted) {
    showMessage(
      context,
      'คัดลอกคะแนน ${formatScore(score)} ของ $studentLabel แล้ว',
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
    this.submissions = const {},
    this.refreshing = false,
    this.fromClassroomWeb = false,
    this.courseWorkLink,
  });

  final int assignmentId;
  final List<GoogleSubmission> rows;

  /// CourseWork created on the Classroom website: no grade goes back, the
  /// teacher copies the scores instead (DESIGN §19.3).
  final bool fromClassroomWeb;

  /// The courseWork in the Classroom web app ("เปิดใน Classroom").
  final String? courseWorkLink;

  /// The students' submissions (review queue `meta.submissions`) by
  /// student id.
  final Map<int, SubmissionSummary> submissions;
  final Future<void> Function() onRefresh;
  final bool refreshing;

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
      ref.read(googleStatusProvider.notifier).noteError(e);
      if (context.mounted) showMessage(context, googleErrorMessage(e));
    }
  }

  SubmissionSummary? _submissionOf(GoogleSubmission row) =>
      row.student == null ? null : submissions[row.student!.id];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final failedGrades = rows
        .where((r) => r.state == SubmissionImportState.gradeFailed)
        .length;
    final waiting = rows
        .where(
          (r) =>
              r.state.hasFiles && (_submissionOf(r)?.regradePending ?? false),
        )
        .length;
    final counts = <SubmissionImportState, int>{};
    for (final r in rows) {
      counts[r.state] = (counts[r.state] ?? 0) + 1;
    }
    final late = rows.where((r) => r.late).length;

    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ContentColumn(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        child: ListView(
          children: [
            if (refreshing) const LinearProgressIndicator(),
            const GoogleReconnectBanner(),
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
                          if (late > 0) 'ส่งช้า $late',
                        ].join(' · '),
                        key: const ValueKey('submission_counts'),
                      ),
                    ],
                    if (fromClassroomWeb) ...[
                      const SizedBox(height: 8),
                      Text(
                        '$webCourseWorkNote เมื่อเผยแพร่ผลแล้ว กด "คัดลอกคะแนน" '
                        'แล้วกรอกคะแนนในเว็บ Classroom เอง',
                        key: const ValueKey('web_coursework_note'),
                        style: TextStyle(color: theme.colorScheme.tertiary),
                      ),
                    ],
                    const SizedBox(height: 8),
                    Text(
                      'เซิร์ฟเวอร์ดาวน์โหลดรูปหรือ PDF ที่นักเรียนส่งจาก Google Drive '
                      'แล้วให้ AI ตรวจจากรูปทั้งหน้าเอง ไม่ต้องสแกนในเครื่องนี้ '
                      'ไฟล์ Word หรือ Google Docs ตรวจไม่ได้ ผลตรวจรอครูตรวจทานที่หน้า "ตรวจทาน"',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    if (waiting > 0) ...[
                      const SizedBox(height: 8),
                      Text(
                        'มี $waiting คนส่งงานใหม่ รอครูกด "ตรวจ"',
                        key: const ValueKey('regrade_waiting'),
                        style: TextStyle(color: theme.colorScheme.tertiary),
                      ),
                    ],
                    const SizedBox(height: 12),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        FilledButton.icon(
                          key: const ValueKey('open_review'),
                          onPressed: () =>
                              context.push(AppRoutes.review(assignmentId)),
                          icon: const Icon(Icons.rate_review_outlined),
                          label: const Text('ตรวจทาน'),
                        ),
                        if (failedGrades > 0 && !fromClassroomWeb)
                          OutlinedButton.icon(
                            key: const ValueKey('retry_grades'),
                            onPressed: () => _retryGrades(context, ref),
                            icon: const Icon(Icons.replay),
                            label: Text('ส่งคะแนนกลับอีกครั้ง ($failedGrades)'),
                          ),
                        if (fromClassroomWeb)
                          OutlinedButton.icon(
                            key: const ValueKey('copy_scores'),
                            onPressed: () => copyScores(
                              context,
                              scoresForClipboard(rows, submissions),
                            ),
                            icon: const Icon(Icons.content_copy),
                            label: const Text('คัดลอกคะแนน'),
                          ),
                        if (courseWorkLink case final link?
                            when link.isNotEmpty)
                          OutlinedButton.icon(
                            key: const ValueKey('open_in_classroom'),
                            onPressed: () =>
                                openInClassroom(context, ref, link),
                            icon: const Icon(Icons.open_in_new),
                            label: const Text('เปิดใน Classroom'),
                          ),
                        OutlinedButton.icon(
                          key: const ValueKey('open_grade_conflicts'),
                          onPressed: () => context.push(
                            AppRoutes.gradeConflicts(assignmentId),
                          ),
                          icon: const Icon(Icons.compare_arrows),
                          label: const Text('คะแนนไม่ตรงกัน'),
                        ),
                        OutlinedButton.icon(
                          key: const ValueKey('open_google_feedback'),
                          onPressed: () => context.push(
                            AppRoutes.googleFeedback(assignmentId),
                          ),
                          icon: const Icon(Icons.campaign_outlined),
                          label: const Text('ประกาศผลรายคน'),
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
                submission: _submissionOf(row),
                fromClassroomWeb: fromClassroomWeb,
              ),
          ],
        ),
      ),
    );
  }
}

class _SubmissionCard extends ConsumerStatefulWidget {
  const _SubmissionCard({
    required this.assignmentId,
    required this.row,
    required this.submission,
    this.fromClassroomWeb = false,
  });

  final int assignmentId;
  final GoogleSubmission row;
  final SubmissionSummary? submission;
  final bool fromClassroomWeb;

  @override
  ConsumerState<_SubmissionCard> createState() => _SubmissionCardState();
}

class _SubmissionCardState extends ConsumerState<_SubmissionCard> {
  bool _busy = false;

  GoogleSubmission get row => widget.row;

  Future<void> _returnForRetake() async {
    final suggestion = row.retakeReason ?? '';
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => _RetakeDialog(
        student: row.studentLabel,
        initial: suggestion.length > 255
            ? suggestion.substring(0, 255)
            : suggestion,
      ),
    );
    if (reason == null || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(googleSubmissionsProvider(widget.assignmentId).notifier)
          .returnForRetake(row, reason);
      if (mounted) {
        showMessage(context, 'ส่งคืนงานใน Classroom และแจ้งนักเรียนแล้ว');
      }
    } catch (e) {
      ref.read(googleStatusProvider.notifier).noteError(e);
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _acceptLate() async {
    final ok = await confirm(
      context,
      title: 'รับงานส่งช้าของ ${row.studentLabel}?',
      message:
          'งานนี้ส่งหลังกำหนดและถูกปฏิเสธตามการตั้งค่าของการบ้าน '
          'ถ้ารับ ระบบจะดาวน์โหลดและตรวจในรอบซิงก์ถัดไป (ภายในไม่กี่นาที) พร้อมป้าย "ส่งช้า"',
      confirmLabel: 'รับงาน',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(googleSubmissionsProvider(widget.assignmentId).notifier)
          .acceptLate(row);
      ref.invalidate(teacherAttentionProvider);
      if (mounted) {
        showMessage(
          context,
          'รับงานของ ${row.studentLabel} แล้ว จะตรวจในรอบซิงก์ถัดไป',
        );
      }
    } catch (e) {
      ref.read(googleStatusProvider.notifier).noteError(e);
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _grade(SubmissionSummary submission) async {
    setState(() => _busy = true);
    try {
      await ref
          .read(reviewQueueProvider(widget.assignmentId).notifier)
          .gradeSubmission(submission.id);
      if (mounted) {
        showMessage(
          context,
          'กำลังตรวจงานของ ${row.studentLabel} ดึงรายการใหม่อีกครั้งในไม่กี่นาที',
        );
      }
    } catch (e) {
      if (mounted) showMessage(context, reviewActionError(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// The published total to type into Classroom, for web courseWork.
  double? fromClassroomWebScore(SubmissionSummary? submission) =>
      widget.fromClassroomWeb ? publishedScore(submission) : null;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final submission = widget.submission;
    final progress = rowProgress(row, submission, theme.colorScheme);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final canGrade =
        row.state.hasFiles && (submission?.regradePending ?? false);
    final score = fromClassroomWebScore(submission);
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
            const SizedBox(height: 4),
            Wrap(
              spacing: 6,
              runSpacing: 4,
              children: [
                StatusChip(
                  key: ValueKey('progress_${row.id}'),
                  label: progress.label,
                  color: progress.color,
                ),
                if (row.late)
                  StatusChip(label: 'ส่งช้า', color: Colors.orange.shade800),
              ],
            ),
            if (progress.detail case final detail?)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(detail, key: ValueKey('progress_detail_${row.id}')),
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
            if (row.classroomGrade case final grade?)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  'คะแนนใน Classroom: ${formatScore(grade)}',
                  key: ValueKey('classroom_grade_${row.id}'),
                  style: muted,
                ),
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
            if (_busy) ...[
              const SizedBox(height: 8),
              const LinearProgressIndicator(),
            ],
            Wrap(
              spacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                if (canGrade)
                  FilledButton.tonalIcon(
                    key: ValueKey('grade_${row.id}'),
                    onPressed: _busy ? null : () => _grade(submission!),
                    icon: const Icon(Icons.fact_check_outlined),
                    label: const Text('ตรวจ'),
                  ),
                if (row.canAcceptLate)
                  FilledButton.tonalIcon(
                    key: ValueKey('accept_late_${row.id}'),
                    onPressed: _busy ? null : _acceptLate,
                    icon: const Icon(Icons.schedule),
                    label: const Text('รับงานส่งช้า'),
                  ),
                if (score != null)
                  TextButton.icon(
                    key: ValueKey('copy_score_${row.id}'),
                    onPressed: () =>
                        copyOneScore(context, row.studentLabel, score),
                    icon: const Icon(Icons.content_copy),
                    label: Text('คัดลอกคะแนน ${formatScore(score)}'),
                  ),
                if (row.state.canReturnForRetake)
                  TextButton.icon(
                    key: ValueKey('return_${row.id}'),
                    onPressed: _busy ? null : _returnForRetake,
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
              'และนักเรียนเห็นเหตุผลนี้ในแอป Krucheck',
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
