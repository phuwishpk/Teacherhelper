import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../review/review_providers.dart';
import '../review/review_repository.dart';
import 'exam_models.dart';
import 'exam_providers.dart';
import 'exam_scan_repository.dart';
import 'exam_version_picker_screen.dart';

/// `GET /exams/{id}/sheet-status` while the results screen is open.
final examSheetStatusProvider = FutureProvider.autoDispose
    .family<ExamSheetStatus, int>((ref, examId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(examScanRepositoryProvider).sheetStatus(examId);
    });

/// "ตรวจทานและประกาศผล" of an app-graded exam (DESIGN §22.11): who has
/// been scanned, who waits for review, a version or a page, and
/// "ประกาศผลทั้งห้อง" (`POST /assignments/{id}/publish`) with the counts
/// shown before it runs. A page without a known version opens
/// [ExamVersionPickerScreen]. Once every scanned student is published the
/// cached scan kit (it holds the key) is deleted from the phone (§22.9).
class ExamResultsScreen extends ConsumerStatefulWidget {
  const ExamResultsScreen({super.key, required this.examId});

  final int examId;

  @override
  ConsumerState<ExamResultsScreen> createState() => _ExamResultsScreenState();
}

class _ExamResultsScreenState extends ConsumerState<ExamResultsScreen> {
  bool _busy = false;

  int get _id => widget.examId;

  Future<ExamSheetStatus> _reload() {
    ref.invalidate(examSheetStatusProvider(_id));
    ref.invalidate(reviewQueueProvider(_id));
    return ref.read(examSheetStatusProvider(_id).future);
  }

  Future<void> _publish(ExamSheetStatus s, ExamDetail? exam) async {
    final noSheet = s.students.where((r) => r.isMissing).length;
    final showKey = exam?.exam.showKeyToStudents ?? false;
    final ok = await confirm(
      context,
      title: 'ประกาศผลทั้งห้อง?',
      message: [
        'นักเรียน ${s.readyToPublish} คนที่ตรวจทานครบแล้วจะเห็นคะแนนรวมและคะแนนรายตอน'
            '${showKey ? ' พร้อมเฉลยรายข้อ' : ''} และได้รับการแจ้งเตือน',
        if (s.waitingReview > 0)
          'อีก ${s.waitingReview} คนยังไม่ประกาศ (รอตรวจทาน เลือกชุด หรือขาดหน้า)',
        if (noSheet > 0) 'ยังไม่มีกระดาษคำตอบ $noSheet คน',
        'ภาพหน้ากระดาษคำตอบจะถูกลบหลังประกาศ',
      ].join('\n'),
      confirmLabel: 'ประกาศผล',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      final result = await ref
          .read(reviewRepositoryProvider)
          .publishAssignment(_id);
      if (!mounted) return;
      showMessage(
        context,
        'ประกาศผลแล้ว ${result.published} คน'
        '${result.skipped > 0 ? ' (ยังไม่ครบ ${result.skipped} คน)' : ''}',
      );
      await _reload();
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickVersion(
    ExamSheetStudentStatus student,
    ExamSheetPageStatus page,
    int versionCount,
  ) async {
    final result = await Navigator.of(context).push<ExamSheetPageResult>(
      MaterialPageRoute(
        builder: (_) => ExamVersionPickerScreen(
          scanId: page.scanId,
          pageNo: page.pageNo,
          versionCount: versionCount,
          studentLabel: 'เลขที่ ${student.studentNumber} ${student.name}',
          currentVersion: page.versionNo,
          pageOneVersion: page.pageNo > 1 ? student.page(1)?.versionNo : null,
          versionDoubtful: page.versionDoubtful,
        ),
      ),
    );
    if (result == null || !mounted) return;
    showMessage(context, versionChosenMessage(result));
    await _reload();
  }

  Future<void> _openStudent(
    ExamSheetStudentStatus student,
    int versionCount,
  ) async {
    if (student.pages.isEmpty) return;
    final page = await showModalBottomSheet<ExamSheetPageStatus>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          children: [
            ListTile(
              title: Text('เลขที่ ${student.studentNumber} ${student.name}'),
              subtitle: Text(_studentSummary(student)),
            ),
            for (final p in student.pages)
              ListTile(
                key: ValueKey('sheet_page_${p.scanId}'),
                leading: const Icon(Icons.description_outlined),
                title: Text('หน้า ${p.pageNo}'),
                subtitle: Text(
                  p.versionNo == null
                      ? 'ไม่ทราบชุด'
                      : 'ชุด ${examVersionLabel(p.versionNo!)}'
                            '${p.versionDoubtful ? ' (วงชุดไม่ชัด)' : ''}'
                            '${p.versionSource == 'teacher' ? ' · ครูเลือก' : ''}',
                ),
                trailing: versionCount > 1
                    ? const Text('เลือกชุด')
                    : const Icon(Icons.image_outlined),
                onTap: () => Navigator.of(context).pop(p),
              ),
            if (student.missingPages.isNotEmpty)
              ListTile(
                leading: const Icon(Icons.warning_amber_outlined),
                title: Text(
                  'ยังขาดหน้า ${student.missingPages.join(', ')} '
                  'สแกนหน้าที่ขาดก่อนประกาศผล',
                ),
              ),
          ],
        ),
      ),
    );
    if (page == null || !mounted) return;
    await _pickVersion(student, page, versionCount);
  }

  @override
  Widget build(BuildContext context) {
    final status = ref.watch(examSheetStatusProvider(_id));
    final exam = ref.watch(examDetailProvider(_id)).value;
    // The scan kit holds the key: once every scanned student is published
    // it has no use on this phone any more (§22.9 step 1).
    ref.listen(examSheetStatusProvider(_id), (_, next) {
      if (next.value?.allPublished ?? false) {
        ref.read(examKitCacheProvider).remove(_id);
      }
    });
    return Scaffold(
      appBar: AppBar(
        title: Text(
          exam == null ? 'ตรวจทานและประกาศผล' : 'ผลสอบ: ${exam.exam.title}',
        ),
        actions: [
          IconButton(
            tooltip: 'ดึงข้อมูลใหม่',
            onPressed: _busy ? null : _reload,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: AsyncView(
        value: status,
        onRetry: _reload,
        data: (s) {
          final versionCount = exam?.exam.versionCount ?? 1;
          return RefreshIndicator(
            onRefresh: _reload,
            child: ContentColumn(
              padding: EdgeInsets.zero,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
                children: [
                  if (_busy) const LinearProgressIndicator(),
                  _SummaryCard(
                    status: s,
                    busy: _busy,
                    onReview: () => context.push(AppRoutes.review(_id)),
                    onPublish: () => _publish(s, exam),
                  ),
                  const SizedBox(height: 8),
                  if (s.students.isEmpty)
                    const EmptyView(
                      icon: Icons.groups_outlined,
                      title: 'ยังไม่มีนักเรียนในห้อง',
                      message: 'เพิ่มนักเรียนในห้องก่อนสแกนกระดาษคำตอบ',
                    ),
                  for (final student in s.students)
                    _StudentTile(
                      student: student,
                      maxScore: s.maxScore,
                      onTap: student.pages.isEmpty
                          ? null
                          : () => _openStudent(student, versionCount),
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

String _studentSummary(ExamSheetStudentStatus s) => [
  if (s.pagesReceived.isEmpty)
    'ยังไม่ได้สแกน'
  else
    'หน้า ${s.pagesReceived.join(', ')} จาก ${s.pageCount}',
  if (s.versionNo != null) 'ชุด ${examVersionLabel(s.versionNo!)}',
  if (s.doubtCount > 0) 'ต้องตรวจ ${s.doubtCount} ข้อ',
].join(' · ');

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({
    required this.status,
    required this.busy,
    required this.onReview,
    required this.onPublish,
  });

  final ExamSheetStatus status;
  final bool busy;
  final VoidCallback onReview;
  final VoidCallback onPublish;

  @override
  Widget build(BuildContext context) {
    final s = status;
    final theme = Theme.of(context);
    Widget count(String label, int n, Color? color, String key) => Expanded(
      child: Column(
        key: ValueKey(key),
        children: [
          Text(
            '$n',
            style: theme.textTheme.headlineSmall?.copyWith(color: color),
          ),
          Text(
            label,
            style: theme.textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'สแกนครบ ${s.scanned}/${s.total} คน',
              style: theme.textTheme.titleMedium,
            ),
            if (s.missingNumbers.isNotEmpty)
              Text(
                'ยังขาด เลขที่ ${s.missingNumbers.join(', ')}',
                style: theme.textTheme.bodySmall,
              ),
            const SizedBox(height: 12),
            Row(
              children: [
                count(
                  'พร้อมประกาศ',
                  s.readyToPublish,
                  Colors.green.shade700,
                  'count_ready',
                ),
                count(
                  'รอตรวจทาน',
                  s.waitingReview,
                  s.waitingReview > 0 ? theme.colorScheme.error : null,
                  'count_waiting',
                ),
                count('ประกาศแล้ว', s.published, null, 'count_published'),
              ],
            ),
            if (s.allPublished)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  'ประกาศผลครบทุกคนที่สแกนแล้ว เฉลยที่เตรียมสแกนไว้ในเครื่องถูกลบแล้ว',
                  key: const ValueKey('all_published'),
                  style: theme.textTheme.bodySmall,
                ),
              ),
            const SizedBox(height: 12),
            Wrap(
              alignment: WrapAlignment.end,
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton.icon(
                  key: const ValueKey('exam_results_review'),
                  onPressed: onReview,
                  icon: const Icon(Icons.fact_check_outlined),
                  label: const Text('ตรวจทานข้อที่สงสัย'),
                ),
                FilledButton.icon(
                  key: const ValueKey('exam_publish_all'),
                  onPressed: busy || s.readyToPublish == 0 ? null : onPublish,
                  icon: const Icon(Icons.campaign_outlined),
                  label: Text('ประกาศผลทั้งห้อง (${s.readyToPublish})'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _StudentTile extends StatelessWidget {
  const _StudentTile({
    required this.student,
    required this.maxScore,
    required this.onTap,
  });

  final ExamSheetStudentStatus student;
  final double maxScore;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final s = student;
    final scheme = Theme.of(context).colorScheme;
    final chips = [
      if (s.isPublished)
        StatusChip(label: 'ประกาศแล้ว', color: scheme.outline)
      else if (s.isReady)
        StatusChip(label: 'พร้อมประกาศ', color: Colors.green.shade700)
      else if (!s.isMissing)
        StatusChip(label: 'รอตรวจทาน', color: scheme.error),
      if (s.needsVersion)
        StatusChip(label: 'ให้ครูเลือกชุด', color: scheme.error),
      if (!s.isMissing && s.missingPages.isNotEmpty)
        StatusChip(
          label: 'ยังขาดหน้า ${s.missingPages.join(', ')}',
          color: Colors.orange.shade800,
        ),
    ];
    return Card(
      key: ValueKey('exam_student_${s.studentId}'),
      child: ListTile(
        onTap: onTap,
        leading: CircleAvatar(radius: 18, child: Text('${s.studentNumber}')),
        title: Text(s.name, maxLines: 1, overflow: TextOverflow.ellipsis),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_studentSummary(s)),
            if (chips.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Wrap(spacing: 6, runSpacing: 4, children: chips),
              ),
          ],
        ),
        trailing: s.score == null
            ? null
            : Text(
                '${formatPoints(s.score!)}/${formatPoints(maxScore)}',
                style: Theme.of(context).textTheme.titleMedium,
              ),
      ),
    );
  }
}
