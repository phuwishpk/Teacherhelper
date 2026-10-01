import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/content_column.dart';
import '../courses/course_models.dart';
import '../courses/courses_providers.dart';
import 'classroom.dart';
import 'course_requests.dart';

/// `/course-requests/new`: "ขอสอนในห้องของครูท่านอื่น" (DESIGN §24.7 step 1).
/// The teacher searches the school's open classrooms (no roster) and asks
/// the homeroom teacher to bind one of their own courses to a room;
/// [courseId] preselects the course when opened from a course's page.
class RequestClassroomScreen extends ConsumerStatefulWidget {
  const RequestClassroomScreen({super.key, this.courseId});

  final int? courseId;

  @override
  ConsumerState<RequestClassroomScreen> createState() =>
      _RequestClassroomScreenState();
}

class _RequestClassroomScreenState
    extends ConsumerState<RequestClassroomScreen> {
  static const _debounce = Duration(milliseconds: 400);

  final _text = TextEditingController();
  Timer? _timer;
  String _query = '';

  @override
  void dispose() {
    _timer?.cancel();
    _text.dispose();
    super.dispose();
  }

  void _searchNow() {
    _timer?.cancel();
    setState(() => _query = _text.text.trim());
  }

  void _onChanged(String _) {
    _timer?.cancel();
    _timer = Timer(_debounce, () {
      if (mounted) _searchNow();
    });
  }

  Future<void> _pick(DirectoryClassroom room) async {
    final sent = await showCourseRequestDialog(
      context,
      room: room,
      courseId: widget.courseId,
    );
    if (sent && mounted) context.pop();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rooms = ref.watch(classroomDirectoryProvider(_query));
    return Scaffold(
      appBar: AppBar(title: const Text('ขอสอนในห้องของครูท่านอื่น')),
      body: ContentColumn(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          children: [
            Text(
              'เลือกห้องของครูประจำชั้น แล้วเลือกรายวิชาของคุณที่จะสอนห้องนั้น '
              'เมื่อครูประจำชั้นอนุมัติ คุณสั่งงานและสอบในห้องนั้นได้ '
              'เห็นรายชื่อแบบอ่านอย่างเดียว และเห็นเฉพาะผลของรายวิชาตัวเอง',
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 12),
            TextField(
              key: const ValueKey('directory_query'),
              controller: _text,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                labelText: 'ค้นชื่อห้องหรือชื่อครูประจำชั้น',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                  key: const ValueKey('directory_search'),
                  tooltip: 'ค้นหา',
                  icon: const Icon(Icons.search),
                  onPressed: _searchNow,
                ),
              ),
              onChanged: _onChanged,
              onSubmitted: (_) => _searchNow(),
            ),
            const SizedBox(height: 12),
            ...rooms.when(
              skipLoadingOnRefresh: true,
              loading: () => const [LinearProgressIndicator()],
              error: (e, _) => [
                Text(
                  apiErrorMessage(e),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
                TextButton(
                  onPressed: () =>
                      ref.invalidate(classroomDirectoryProvider(_query)),
                  child: const Text('ลองใหม่'),
                ),
              ],
              data: (list) => list.isEmpty
                  ? [
                      Text(
                        _query.isEmpty
                            ? 'ยังไม่มีห้องเรียนที่เปิดอยู่ในโรงเรียน'
                            : 'ไม่พบห้องที่ตรงกับ "$_query"',
                      ),
                    ]
                  : [
                      Card(
                        clipBehavior: Clip.antiAlias,
                        child: Column(
                          children: [
                            for (final r in list) ...[
                              _DirectoryTile(room: r, onTap: () => _pick(r)),
                              if (r != list.last) const Divider(height: 1),
                            ],
                          ],
                        ),
                      ),
                    ],
            ),
          ],
        ),
      ),
    );
  }
}

class _DirectoryTile extends StatelessWidget {
  const _DirectoryTile({required this.room, required this.onTap});

  final DirectoryClassroom room;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final r = room;
    final scheme = Theme.of(context).colorScheme;
    return ListTile(
      key: ValueKey('directory_room_${r.id}'),
      leading: CircleAvatar(child: Text(gradeLevelLabel(r.gradeLevel))),
      title: Wrap(
        spacing: 8,
        runSpacing: 4,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(r.name),
          if (r.myRole case final role?)
            StatusChip(
              label: role == ClassroomRole.homeroom ? 'ห้องของคุณ' : 'สอนอยู่',
              color: role == ClassroomRole.homeroom
                  ? scheme.primary
                  : scheme.tertiary,
            ),
        ],
      ),
      subtitle: Text(
        [
          'ปีการศึกษา ${r.academicYear}',
          if (r.homeroomTeacher case final t?) 'ครูประจำชั้น ${t.name}',
          'นักเรียน ${r.studentCount} คน',
        ].join(' · '),
      ),
      trailing: const Icon(Icons.chevron_right),
      onTap: onTap,
    );
  }
}

/// Asks to bind one of the teacher's own courses to [room] (DESIGN §24.7):
/// pick the course ([courseId] preselected), add an optional message and
/// send. One's own room is bound at once. True once sent or bound.
Future<bool> showCourseRequestDialog(
  BuildContext context, {
  required DirectoryClassroom room,
  int? courseId,
}) async {
  final done = await showDialog<bool>(
    context: context,
    builder: (_) => _CourseRequestDialog(room: room, courseId: courseId),
  );
  return done ?? false;
}

class _CourseRequestDialog extends ConsumerStatefulWidget {
  const _CourseRequestDialog({required this.room, this.courseId});

  final DirectoryClassroom room;
  final int? courseId;

  @override
  ConsumerState<_CourseRequestDialog> createState() =>
      _CourseRequestDialogState();
}

class _CourseRequestDialogState extends ConsumerState<_CourseRequestDialog> {
  final _message = TextEditingController();
  late int? _courseId = widget.courseId;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _message.dispose();
    super.dispose();
  }

  bool _bound(Course c) => c.classroomIds.contains(widget.room.id);

  Future<void> _send() async {
    final courseId = _courseId;
    if (courseId == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(courseRequestsRepositoryProvider)
          .request(
            widget.room.id,
            courseId: courseId,
            message: widget.room.isMine ? null : _message.text,
          );
      ref.invalidate(courseRequestsProvider(CourseRequestBox.outgoing));
      ref.invalidate(classroomDirectoryProvider);
      invalidateCourses(ref);
      if (!mounted) return;
      showMessage(
        context,
        result.bound
            ? 'ผูกรายวิชากับห้อง ${widget.room.name} แล้ว'
            : 'ส่งคำขอถึงครูประจำชั้นของห้อง ${widget.room.name} แล้ว',
      );
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error = courseRequestErrorText(e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final room = widget.room;
    final courses = ref.watch(coursesProvider);
    final list = courses.value ?? const <Course>[];
    final picked = list.where((c) => c.id == _courseId).firstOrNull;
    final mismatch = picked == null
        ? null
        : gradeMismatchText(
            courseGrade: picked.gradeLevel,
            roomGrade: room.gradeLevel,
          );
    return AlertDialog(
      title: Text('ขอสอนห้อง ${room.name}'),
      content: SizedBox(
        width: 420,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                [
                  '${gradeLevelLabel(room.gradeLevel)} · ปีการศึกษา ${room.academicYear}',
                  if (room.homeroomTeacher case final t?)
                    'ครูประจำชั้น ${t.name}',
                ].join(' · '),
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              if (courses.isLoading && courses.value == null)
                const LinearProgressIndicator()
              else if (courses.hasError && courses.value == null)
                Text(
                  'โหลดรายวิชาไม่ได้: ${apiErrorMessage(courses.error!)}',
                  style: TextStyle(color: theme.colorScheme.error),
                )
              else if (list.isEmpty)
                const Text('ยังไม่มีรายวิชา สร้างรายวิชาก่อนแล้วค่อยส่งคำขอ')
              else
                DropdownButtonFormField<int>(
                  key: const ValueKey('request_course'),
                  initialValue: list.any((c) => c.id == _courseId && !_bound(c))
                      ? _courseId
                      : null,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'รายวิชาของคุณ'),
                  items: [
                    for (final c in list)
                      DropdownMenuItem(
                        value: c.id,
                        enabled: !_bound(c),
                        child: Text(
                          _bound(c) ? '${c.title} (ผูกแล้ว)' : c.title,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: _busy
                      ? null
                      : (v) => setState(() {
                          _courseId = v;
                          _error = null;
                        }),
                ),
              if (mismatch != null) ...[
                const SizedBox(height: 8),
                Text(
                  mismatch,
                  key: const ValueKey('request_grade_warning'),
                  style: TextStyle(color: Colors.orange.shade800),
                ),
              ],
              const SizedBox(height: 12),
              if (room.isMine)
                const Text(
                  'ห้องนี้เป็นห้องของคุณ รายวิชาจะผูกกับห้องทันทีโดยไม่ต้องรออนุมัติ',
                )
              else
                TextField(
                  key: const ValueKey('request_message'),
                  controller: _message,
                  maxLength: 255,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'ข้อความถึงครูประจำชั้น (ไม่บังคับ)',
                  ),
                ),
              if (_error != null) ...[
                const SizedBox(height: 8),
                Text(
                  _error!,
                  key: const ValueKey('request_error'),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(false),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('request_send'),
          onPressed: _busy || picked == null || _bound(picked) ? null : _send,
          child: Text(room.isMine ? 'ผูกรายวิชา' : 'ส่งคำขอ'),
        ),
      ],
    );
  }
}

/// "คำขอผูกรายวิชา" with the number of requests waiting for the teacher
/// (a badge), from the classroom list.
class CourseRequestsButton extends StatelessWidget {
  const CourseRequestsButton({super.key, required this.pending});

  final int pending;

  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
    key: const ValueKey('open_course_requests'),
    onPressed: () => context.push(AppRoutes.courseRequests),
    icon: Badge.count(
      key: const ValueKey('course_requests_badge'),
      count: pending,
      isLabelVisible: pending > 0,
      child: const Icon(Icons.inbox_outlined),
    ),
    label: const Text('คำขอผูกรายวิชา'),
  );
}
