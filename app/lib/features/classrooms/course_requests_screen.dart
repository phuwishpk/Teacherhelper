import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../courses/courses_providers.dart';
import '../home/teacher_attention.dart' show teacherAttentionProvider;
import 'classrooms_providers.dart';
import 'course_requests.dart';

/// `/course-requests`: "คำขอผูกรายวิชา" (DESIGN §24.7, §24.13). "ถึงฉัน" holds
/// the requests to the teacher's homerooms (approve or decline), "ที่ฉันส่ง"
/// the teacher's own (cancel while pending). Pending rows come first.
class CourseRequestsScreen extends StatefulWidget {
  const CourseRequestsScreen({
    super.key,
    this.initialBox = CourseRequestBox.incoming,
  });

  final CourseRequestBox initialBox;

  @override
  State<CourseRequestsScreen> createState() => _CourseRequestsScreenState();
}

class _CourseRequestsScreenState extends State<CourseRequestsScreen> {
  late CourseRequestBox _box = widget.initialBox;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('คำขอผูกรายวิชา')),
      floatingActionButtonLocation: const ContentFabLocation(),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'course_request_new',
        onPressed: () => context.push(AppRoutes.courseRequestNew()),
        icon: const Icon(Icons.add),
        label: const Text('ขอสอนห้องของครูท่านอื่น'),
      ),
      body: Column(
        children: [
          ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: SegmentedButton<CourseRequestBox>(
              segments: const [
                ButtonSegment(
                  value: CourseRequestBox.incoming,
                  icon: Icon(Icons.move_to_inbox_outlined),
                  label: Text('ถึงฉัน', key: ValueKey('tab_incoming')),
                ),
                ButtonSegment(
                  value: CourseRequestBox.outgoing,
                  icon: Icon(Icons.outbox_outlined),
                  label: Text('ที่ฉันส่ง', key: ValueKey('tab_outgoing')),
                ),
              ],
              selected: {_box},
              onSelectionChanged: (v) => setState(() => _box = v.single),
            ),
          ),
          Expanded(
            child: _RequestList(key: ValueKey(_box), box: _box),
          ),
        ],
      ),
    );
  }
}

class _RequestList extends ConsumerWidget {
  const _RequestList({super.key, required this.box});

  final CourseRequestBox box;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final requests = ref.watch(courseRequestsProvider(box));
    return AsyncView(
      value: requests,
      onRetry: () => ref.invalidate(courseRequestsProvider(box)),
      data: (list) {
        if (list.isEmpty) {
          return EmptyView(
            icon: Icons.inbox_outlined,
            title: 'ยังไม่มีคำขอ',
            message: box == CourseRequestBox.incoming
                ? 'เมื่อครูประจำวิชาขอสอนรายวิชาในห้องของคุณ คำขอจะแสดงที่นี่'
                : 'กด "ขอสอนห้องของครูท่านอื่น" เพื่อเลือกห้องและรายวิชา',
          );
        }
        final pending = [
          for (final r in list)
            if (r.isPending) r,
        ];
        final decided = [
          for (final r in list)
            if (!r.isPending) r,
        ];
        return RefreshIndicator(
          onRefresh: () => ref.refresh(courseRequestsProvider(box).future),
          child: ContentColumn(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
              children: [
                if (pending.isNotEmpty) ...[
                  _Heading('รอดำเนินการ (${pending.length})'),
                  for (final r in pending) _RequestCard(request: r, box: box),
                ],
                if (decided.isNotEmpty) ...[
                  const _Heading('ตัดสินแล้ว'),
                  for (final r in decided) _RequestCard(request: r, box: box),
                ],
              ],
            ),
          ),
        );
      },
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(4, 12, 4, 8),
    child: Text(text, style: Theme.of(context).textTheme.titleSmall),
  );
}

class _RequestCard extends ConsumerStatefulWidget {
  const _RequestCard({required this.request, required this.box});

  final CourseRequest request;
  final CourseRequestBox box;

  @override
  ConsumerState<_RequestCard> createState() => _RequestCardState();
}

class _RequestCardState extends ConsumerState<_RequestCard> {
  bool _busy = false;

  CourseRequest get r => widget.request;

  /// After a decision: both boxes, the attention badge and every list of
  /// the room's courses change.
  void _refresh() {
    ref.invalidate(courseRequestsProvider);
    ref.invalidate(teacherAttentionProvider);
    ref.invalidate(classroomsProvider);
    invalidateCourses(ref);
  }

  Future<void> _run(Future<void> Function() action, String done) async {
    setState(() => _busy = true);
    try {
      await action();
      _refresh();
      if (mounted) showMessage(context, done);
    } catch (e) {
      if (!mounted) return;
      showMessage(context, courseRequestErrorText(e));
      // A request decided elsewhere meanwhile: show its new state.
      if (apiErrorCode(e) == 'request_closed') _refresh();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _approve() async {
    final who = r.requester?.name ?? 'ครูประจำวิชา';
    final ok = await confirm(
      context,
      title: 'อนุมัติ ${r.course?.title ?? 'รายวิชา'}?',
      message:
          '$who จะสั่งงานและสอบรายวิชานี้ในห้อง ${r.classroom?.name ?? ''} ได้ '
          'เห็นรายชื่อนักเรียนแบบอ่านอย่างเดียว และเห็นเฉพาะผลของรายวิชาตัวเอง '
          'คุณยังเห็นงานและผลของรายวิชานี้ทั้งหมด (แก้ไม่ได้)',
      confirmLabel: 'อนุมัติ',
    );
    if (!ok || !mounted) return;
    await _run(
      () => ref.read(courseRequestsRepositoryProvider).approve(r.id),
      'อนุมัติแล้ว',
    );
  }

  Future<void> _decline() async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => const _DeclineDialog(),
    );
    if (reason == null || !mounted) return;
    await _run(
      () => ref
          .read(courseRequestsRepositoryProvider)
          .decline(r.id, reason: reason),
      'ไม่อนุมัติคำขอแล้ว',
    );
  }

  Future<void> _cancel() async {
    final ok = await confirm(
      context,
      title: 'ยกเลิกคำขอนี้?',
      message: 'ส่งคำขอใหม่ได้ภายหลัง',
      confirmLabel: 'ยกเลิกคำขอ',
      destructive: true,
    );
    if (!ok || !mounted) return;
    await _run(
      () => ref.read(courseRequestsRepositoryProvider).cancel(r.id),
      'ยกเลิกคำขอแล้ว',
    );
  }

  Color _statusColor(ColorScheme scheme) => switch (r.status) {
    CourseRequestStatus.pending => scheme.tertiary,
    CourseRequestStatus.approved => Colors.green.shade700,
    CourseRequestStatus.declined => scheme.error,
    CourseRequestStatus.cancelled => scheme.outline,
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final incoming = widget.box == CourseRequestBox.incoming;
    final room = r.classroom;
    final course = r.course;
    final created = r.createdAt;
    final decided = r.decidedAt;
    final mismatch = room == null || course == null
        ? null
        : gradeMismatchText(
            courseGrade: course.gradeLevel,
            roomGrade: room.gradeLevel,
          );
    return Card(
      key: ValueKey('course_request_${r.id}'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    course?.title ?? 'รายวิชา',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                StatusChip(
                  label: r.status.label,
                  color: _statusColor(theme.colorScheme),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              [
                if (room != null)
                  'ห้อง ${room.name} (${gradeLevelLabel(room.gradeLevel)} · ${room.academicYear})',
                if (incoming && r.requester != null)
                  'ครูผู้สอน ${r.requester!.name}'
                else if (!incoming && room?.homeroomTeacher != null)
                  'ครูประจำชั้น ${room!.homeroomTeacher!.name}',
              ].join(' · '),
            ),
            if (r.origin == 'classroom_import' && r.googleCourseName != null)
              Text(
                'จาก Google Classroom: ${r.googleCourseName}',
                style: theme.textTheme.bodySmall,
              ),
            if (created != null)
              Text(
                'ส่งเมื่อ ${formatThaiDateTime(created.toLocal())}'
                '${decided != null && !r.isPending ? ' · ตัดสินเมื่อ ${formatThaiDateTime(decided.toLocal())}' : ''}',
                style: theme.textTheme.bodySmall,
              ),
            if (r.message case final m?) ...[
              const SizedBox(height: 8),
              Text('"$m"', style: theme.textTheme.bodyMedium),
            ],
            if (r.declineReason case final reason?) ...[
              const SizedBox(height: 8),
              Text(
                'เหตุผลที่ไม่อนุมัติ: $reason',
                style: TextStyle(color: theme.colorScheme.error),
              ),
            ],
            if (mismatch != null && r.isPending) ...[
              const SizedBox(height: 8),
              Text(
                mismatch,
                key: ValueKey('request_mismatch_${r.id}'),
                style: TextStyle(color: Colors.orange.shade800),
              ),
            ],
            if (r.isPending) ...[
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: incoming
                    ? [
                        FilledButton.icon(
                          key: ValueKey('approve_${r.id}'),
                          onPressed: _busy ? null : _approve,
                          icon: const Icon(Icons.check),
                          label: const Text('อนุมัติ'),
                        ),
                        OutlinedButton.icon(
                          key: ValueKey('decline_${r.id}'),
                          onPressed: _busy ? null : _decline,
                          icon: const Icon(Icons.close),
                          label: const Text('ไม่อนุมัติ'),
                        ),
                      ]
                    : [
                        OutlinedButton.icon(
                          key: ValueKey('cancel_${r.id}'),
                          onPressed: _busy ? null : _cancel,
                          icon: const Icon(Icons.undo),
                          label: const Text('ยกเลิกคำขอ'),
                        ),
                      ],
              ),
            ] else if (r.status == CourseRequestStatus.approved &&
                room != null &&
                !room.closed) ...[
              const SizedBox(height: 8),
              TextButton.icon(
                key: ValueKey('open_room_${r.id}'),
                onPressed: () => context.push(AppRoutes.classroom(room.id)),
                icon: const Icon(Icons.groups_outlined),
                label: Text('เปิดห้อง ${room.name}'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// "ไม่อนุมัติ" with an optional reason; pops the reason ('' = none) or
/// null when the teacher backs out.
class _DeclineDialog extends StatefulWidget {
  const _DeclineDialog();

  @override
  State<_DeclineDialog> createState() => _DeclineDialogState();
}

class _DeclineDialogState extends State<_DeclineDialog> {
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('ไม่อนุมัติคำขอ?'),
    content: TextField(
      key: const ValueKey('decline_reason'),
      controller: _reason,
      maxLength: 255,
      maxLines: 2,
      decoration: const InputDecoration(
        labelText: 'เหตุผลถึงผู้ขอ (ไม่บังคับ)',
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('ยกเลิก'),
      ),
      FilledButton(
        key: const ValueKey('decline_confirm'),
        onPressed: () => Navigator.of(context).pop(_reason.text.trim()),
        child: const Text('ไม่อนุมัติ'),
      ),
    ],
  );
}
