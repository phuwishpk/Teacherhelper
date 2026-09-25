import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_providers.dart';
import 'missing_key_banner.dart';
import 'response_review_pane.dart';
import 'review_labels.dart';
import 'review_models.dart';
import 'review_providers.dart';

/// Width from which the queue shows list and detail side by side (tablet).
const reviewMasterDetailBreakpoint = 840.0;

/// Review queue of one assignment (DESIGN §13): tabs ต้องตรวจ / ควรดู /
/// มั่นใจ plus a per-student tab for publishing, the missing-key banner and
/// rescans of published pages waiting for confirm-replace.
class ReviewQueueScreen extends ConsumerStatefulWidget {
  const ReviewQueueScreen({super.key, required this.assignmentId});

  final int assignmentId;

  @override
  ConsumerState<ReviewQueueScreen> createState() => _ReviewQueueScreenState();
}

class _ReviewQueueScreenState extends ConsumerState<ReviewQueueScreen> {
  bool _busy = false;

  int get _aid => widget.assignmentId;
  ReviewQueueNotifier get _queue =>
      ref.read(reviewQueueProvider(_aid).notifier);

  Future<void> _run(Future<String> Function() action) async {
    setState(() => _busy = true);
    try {
      final message = await action();
      if (mounted) showMessage(context, message);
    } catch (e) {
      if (mounted) showMessage(context, _actionError(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  static String _actionError(Object e) => switch (apiErrorCode(e)) {
    'ai_key_missing' =>
      'ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง',
    _ => apiErrorMessage(e),
  };

  Future<void> _publishAll(ReviewQueue q) async {
    final ready = q.publishableCount;
    final waiting = q.submissions
        .where((s) => !s.isPublished && !s.canPublish)
        .length;
    final ok = await confirm(
      context,
      title: 'เผยแพร่ผลทั้งการบ้าน?',
      message:
          'นักเรียน $ready คนที่ตรวจทานครบแล้วจะเห็นคะแนนและคำอธิบายรายข้อ '
          'และได้รับการแจ้งเตือน'
          '${waiting > 0 ? ' ส่วนอีก $waiting คนที่ยังตรวจไม่ครบจะยังไม่เห็นผล' : ''} '
          'ภาพเต็มหน้าของใบงานจะถูกลบหลังเผยแพร่',
      confirmLabel: 'เผยแพร่',
    );
    if (!ok) return;
    await _run(() async {
      final r = await _queue.publishAll();
      return 'เผยแพร่แล้ว ${r.published} คน'
          '${r.skipped > 0 ? ' (ยังตรวจไม่ครบ ${r.skipped} คน)' : ''}';
    });
  }

  Future<void> _approveConfident(ReviewQueue q) async {
    final n = q.bulkApprovable.length;
    final ok = await confirm(
      context,
      title: 'อนุมัติ $n ข้อในกลุ่มมั่นใจ?',
      message:
          'ใช้คะแนนและคำอธิบายที่ AI ให้ ข้อที่น่าสงสัย ตัวตนไม่ตรง '
          'หรือมีคำขอให้ตรวจใหม่จะไม่ถูกอนุมัติด้วยวิธีนี้',
      confirmLabel: 'อนุมัติ',
    );
    if (!ok) return;
    await _run(() async {
      final approved = await _queue.approveConfident();
      return 'อนุมัติแล้ว $approved ข้อ';
    });
  }

  Future<void> _publishSubmission(SubmissionSummary s) async {
    final name = s.student?.label ?? 'นักเรียนคนนี้';
    final ok = await confirm(
      context,
      title: 'เผยแพร่ผลของ $name?',
      message:
          'นักเรียนจะเห็นคะแนนและคำอธิบายรายข้อ และได้รับการแจ้งเตือน '
          'ภาพเต็มหน้าของใบงานจะถูกลบหลังเผยแพร่',
      confirmLabel: 'เผยแพร่',
    );
    if (!ok) return;
    await _run(() async {
      await _queue.publishSubmission(s.id);
      return 'เผยแพร่ผลของ $name แล้ว';
    });
  }

  Future<void> _confirmReplace(PendingScan scan) async {
    final name = scan.student?.label ?? 'นักเรียนคนนี้';
    final ok = await confirm(
      context,
      title: 'ใช้สแกนใหม่แทนผลเดิม?',
      message:
          'ผลของ $name เผยแพร่ไปแล้ว ถ้ายืนยัน ระบบจะตรวจหน้านี้ใหม่ '
          'และนักเรียนจะเห็นผลใหม่หลังคุณเผยแพร่อีกครั้ง',
      confirmLabel: 'ใช้สแกนใหม่',
    );
    if (!ok) return;
    await _run(() async {
      await _queue.confirmReplace(scan.scanId);
      return 'แทนที่แล้ว ระบบกำลังตรวจหน้านี้ใหม่';
    });
  }

  Future<void> _requeue() => _run(() async {
    final n = await _queue.requeueMissingKey();
    return n > 0
        ? 'ส่ง $n ข้อกลับไปให้ AI ตรวจแล้ว ดึงรายการใหม่อีกครั้งในไม่กี่นาที'
        : 'ไม่มีข้อที่ค้างเพราะไม่มี key';
  });

  @override
  Widget build(BuildContext context) {
    final queue = ref.watch(reviewQueueProvider(_aid));
    final title = ref.watch(assignmentDetailProvider(_aid)).value?.title;
    final q = queue.value;

    String tabLabel(PriorityBand b) {
      final n = q?.unreviewedIn(b) ?? 0;
      return n > 0 ? '${b.label} ($n)' : b.label;
    }

    return DefaultTabController(
      length: 4,
      child: Scaffold(
        appBar: AppBar(
          title: Text(title == null ? 'ตรวจทาน' : 'ตรวจทาน: $title'),
          actions: [
            IconButton(
              tooltip: 'ดึงรายการใหม่',
              onPressed: _busy ? null : () => _queue.refresh(),
              icon: const Icon(Icons.refresh),
            ),
            IconButton(
              tooltip: 'เผยแพร่ทั้งการบ้าน',
              onPressed: _busy || q == null || q.publishableCount == 0
                  ? null
                  : () => _publishAll(q),
              icon: const Icon(Icons.send_outlined),
            ),
          ],
          bottom: TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            tabs: [
              for (final b in PriorityBand.values) Tab(text: tabLabel(b)),
              Tab(
                text: q == null || q.publishableCount == 0
                    ? 'รายคน'
                    : 'รายคน (${q.publishableCount})',
              ),
            ],
          ),
        ),
        body: AsyncView(
          value: queue,
          onRetry: () => ref.invalidate(reviewQueueProvider(_aid)),
          data: (q) => Column(
            children: [
              if (_busy) const LinearProgressIndicator(),
              if (q.missingAiKeyCount > 0)
                MissingKeyBanner(
                  count: q.missingAiKeyCount,
                  busy: _busy,
                  onOpenSettings: () => context.push(AppRoutes.settings),
                  onRequeue: _requeue,
                ),
              if (q.pendingScans.isNotEmpty)
                _PendingScansCard(
                  scans: q.pendingScans,
                  busy: _busy,
                  onConfirm: _confirmReplace,
                ),
              Expanded(
                child: TabBarView(
                  children: [
                    for (final b in PriorityBand.values)
                      _BandTab(
                        assignmentId: _aid,
                        band: b,
                        queue: q,
                        busy: _busy,
                        onApproveConfident: () => _approveConfident(q),
                      ),
                    _SubmissionsTab(
                      submissions: q.submissions,
                      busy: _busy,
                      onPublish: _publishSubmission,
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// One band tab: the list, plus the detail pane beside it on tablets.
class _BandTab extends ConsumerStatefulWidget {
  const _BandTab({
    required this.assignmentId,
    required this.band,
    required this.queue,
    required this.busy,
    required this.onApproveConfident,
  });

  final int assignmentId;
  final PriorityBand band;
  final ReviewQueue queue;
  final bool busy;
  final VoidCallback onApproveConfident;

  @override
  ConsumerState<_BandTab> createState() => _BandTabState();
}

class _BandTabState extends ConsumerState<_BandTab>
    with AutomaticKeepAliveClientMixin {
  int? _selectedId;

  @override
  bool get wantKeepAlive => true;

  @override
  Widget build(BuildContext context) {
    super.build(context);
    final rows = widget.queue.tab(widget.band);
    final wide =
        MediaQuery.sizeOf(context).width >= reviewMasterDetailBreakpoint;

    if (rows.isEmpty) {
      return EmptyView(
        icon: Icons.task_alt,
        title: 'ไม่มีข้อในกลุ่ม${widget.band.label}',
        message: switch (widget.band) {
          PriorityBand.check =>
            'ข้อที่ AI ไม่แน่ใจ ลายมืออ่านยาก หรือข้อที่ต้องตรวจเองจะอยู่ที่นี่',
          PriorityBand.look => 'ข้อที่ควรดูอีกครั้งจะอยู่ที่นี่',
          PriorityBand.confident =>
            'ข้อที่ AI มั่นใจและอนุมัติแบบกลุ่มได้จะอยู่ที่นี่',
        },
      );
    }

    final selectedIndex = rows.indexWhere((r) => r.id == _selectedId);
    final current = selectedIndex >= 0
        ? selectedIndex
        : rows.indexWhere((r) => !r.isReviewed).clamp(0, rows.length - 1);

    void select(int index) => setState(() => _selectedId = rows[index].id);

    final approvable = widget.band == PriorityBand.confident
        ? widget.queue.bulkApprovable.length
        : 0;
    final list = Column(
      children: [
        if (approvable > 0)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
            child: SizedBox(
              width: double.infinity,
              child: FilledButton.tonalIcon(
                onPressed: widget.busy ? null : widget.onApproveConfident,
                icon: const Icon(Icons.done_all),
                label: Text('อนุมัติทั้งกลุ่มมั่นใจ ($approvable ข้อ)'),
              ),
            ),
          ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => ref
                .read(reviewQueueProvider(widget.assignmentId).notifier)
                .refresh(),
            child: ListView.builder(
              padding: const EdgeInsets.fromLTRB(8, 8, 8, 24),
              itemCount: rows.length,
              itemBuilder: (context, i) => ReviewItemTile(
                item: rows[i],
                selected: wide && i == current,
                onTap: () {
                  if (wide) {
                    select(i);
                  } else {
                    context.push(
                      AppRoutes.reviewResponse(
                        widget.assignmentId,
                        rows[i].id,
                        band: widget.band,
                      ),
                    );
                  }
                },
              ),
            ),
          ),
        ),
      ],
    );
    if (!wide) return list;

    final item = rows[current];
    return Row(
      children: [
        SizedBox(width: 380, child: list),
        const VerticalDivider(width: 1),
        Expanded(
          child: ResponseReviewPane(
            key: ValueKey(item.id),
            responseId: item.id,
            positionLabel: '${current + 1} จาก ${rows.length}',
            onPrev: current > 0 ? () => select(current - 1) : null,
            onNext: current < rows.length - 1
                ? () => select(current + 1)
                : null,
            onSaved: (advance) {
              if (advance && current < rows.length - 1) select(current + 1);
              ref.invalidate(reviewQueueProvider(widget.assignmentId));
            },
          ),
        ),
      ],
    );
  }
}

/// A queue row: question, student, flags and the current score.
class ReviewItemTile extends StatelessWidget {
  const ReviewItemTile({
    super.key,
    required this.item,
    required this.onTap,
    this.selected = false,
  });

  final ReviewItem item;
  final VoidCallback onTap;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final error = theme.colorScheme.error;
    final chips = <Widget>[
      if (item.isManual)
        StatusChip(
          label: item.missingAiKey ? 'ตรวจเอง · ไม่มี key' : 'ตรวจเอง',
          color: error,
        ),
      if (item.isSuspicious) StatusChip(label: 'น่าสงสัย', color: error),
      if (item.identityMismatch) StatusChip(label: 'ตัวตนไม่ตรง', color: error),
      if (item.hasOpenAppeal)
        StatusChip(label: 'ขอตรวจใหม่', color: Colors.orange.shade800),
      if (item.isGrading && !item.isManual)
        StatusChip(label: 'AI กำลังตรวจ', color: theme.colorScheme.outline),
      if (item.isPublished)
        StatusChip(label: 'เผยแพร่แล้ว', color: theme.colorScheme.outline),
    ];
    final score = item.currentScore;
    return Card(
      color: selected ? theme.colorScheme.secondaryContainer : null,
      child: ListTile(
        onTap: onTap,
        leading: CircleAvatar(
          radius: 18,
          child: Text(
            '${item.questionPosition}',
            semanticsLabel: 'ข้อ ${item.questionPosition}',
          ),
        ),
        title: Text(
          item.student?.label ?? 'นักเรียน',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'ข้อ ${item.questionPosition} · ${questionTypeLabel(item.questionType)}'
              '${item.currentUnderstanding == null ? '' : ' · ${item.currentUnderstanding!.label}'}',
            ),
            if (chips.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Wrap(spacing: 6, runSpacing: 4, children: chips),
              ),
          ],
        ),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              '${formatScore(score)}/${formatScore(item.maxPoints)}',
              style: theme.textTheme.titleMedium,
            ),
            if (item.isReviewed)
              Icon(
                Icons.check_circle,
                size: 18,
                color: Colors.green.shade700,
                semanticLabel: 'ตรวจทานแล้ว',
              ),
          ],
        ),
      ),
    );
  }
}

class _PendingScansCard extends StatelessWidget {
  const _PendingScansCard({
    required this.scans,
    required this.busy,
    required this.onConfirm,
  });

  final List<PendingScan> scans;
  final bool busy;
  final ValueChanged<PendingScan> onConfirm;

  @override
  Widget build(BuildContext context) {
    return Card(
      key: const ValueKey('pending_scans_card'),
      margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      child: ExpansionTile(
        leading: const Icon(Icons.published_with_changes),
        title: Text('สแกนใหม่ของผลที่เผยแพร่แล้ว (${scans.length})'),
        subtitle: const Text('ต้องยืนยันก่อนระบบจะตรวจหน้านั้นใหม่'),
        children: [
          for (final s in scans)
            ListTile(
              title: Text(s.student?.label ?? 'submission #${s.submissionId}'),
              subtitle: Text(
                [
                  if (s.pageNo != null) 'หน้า ${s.pageNo}',
                  if (s.scannedAt != null)
                    'สแกนเมื่อ ${formatThaiDateTime(s.scannedAt!)}',
                ].join(' · '),
              ),
              trailing: TextButton(
                onPressed: busy ? null : () => onConfirm(s),
                child: const Text('ใช้สแกนใหม่'),
              ),
            ),
        ],
      ),
    );
  }
}

class _SubmissionsTab extends StatelessWidget {
  const _SubmissionsTab({
    required this.submissions,
    required this.busy,
    required this.onPublish,
  });

  final List<SubmissionSummary> submissions;
  final bool busy;
  final ValueChanged<SubmissionSummary> onPublish;

  @override
  Widget build(BuildContext context) {
    if (submissions.isEmpty) {
      return const EmptyView(
        icon: Icons.people_outline,
        title: 'ยังไม่มีใบงานที่สแกน',
        message: 'สแกนใบงานของนักเรียนก่อน แล้วผลจะมาอยู่ที่นี่',
      );
    }
    return ContentColumn(
      padding: const EdgeInsets.fromLTRB(8, 8, 8, 24),
      child: ListView(
        children: [
          for (final s in submissions)
            Card(
              child: ListTile(
                leading: CircleAvatar(
                  child: Text('${s.student?.studentNumber ?? '?'}'),
                ),
                title: Text(s.student?.name ?? 'submission #${s.id}'),
                subtitle: Text(
                  'ตรวจทานแล้ว ${s.reviewedCount}/${s.responseCount} ข้อ'
                  '${s.totalScore == null ? '' : ' · รวม ${formatScore(s.totalScore)} คะแนน'}',
                ),
                trailing: s.isPublished
                    ? StatusChip(
                        label: 'เผยแพร่แล้ว',
                        color: Theme.of(context).colorScheme.outline,
                      )
                    : FilledButton.tonal(
                        onPressed: busy || !s.canPublish
                            ? null
                            : () => onPublish(s),
                        child: const Text('เผยแพร่'),
                      ),
              ),
            ),
        ],
      ),
    );
  }
}
