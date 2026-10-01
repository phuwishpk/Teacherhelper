import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../courses/course_models.dart';
import 'gradebook_models.dart';
import 'gradebook_providers.dart';

/// "ตัดเกรด" tab of the teacher shell (DESIGN §23.9): every own course of
/// the chosen academic year with each bound classroom's grading status,
/// and the way into its gradebook, settings and CSV export.
class GradesHomePage extends ConsumerStatefulWidget {
  const GradesHomePage({super.key});

  @override
  ConsumerState<GradesHomePage> createState() => _GradesHomePageState();
}

class _GradesHomePageState extends ConsumerState<GradesHomePage> {
  /// null = the latest year present.
  int? _year;

  /// null = every semester of the year.
  int? _semester;

  /// `courseId:classroomId` of the CSV being exported.
  String? _exporting;

  Future<void> _refresh() => ref.refresh(gradebookOverviewProvider.future);

  /// Opens [location] and loads the overview again on the way back (scores,
  /// settings or a publication may have changed there).
  Future<void> _open(String location) async {
    await context.push(location);
    if (mounted) ref.invalidate(gradebookOverviewProvider);
  }

  Future<void> _export(
    GradebookOverviewCourse course,
    GradebookOverviewRoom room,
  ) async {
    setState(() => _exporting = '${course.id}:${room.id}');
    try {
      await shareGradebookCsv(
        ref,
        courseId: course.id,
        classroomId: room.id,
        courseCode: course.code,
        classroomName: room.name,
      );
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _exporting = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final overview = ref.watch(gradebookOverviewProvider);
    return AsyncView(
      value: overview,
      onRetry: () => ref.invalidate(gradebookOverviewProvider),
      data: (courses) => RefreshIndicator(
        onRefresh: _refresh,
        child: courses.isEmpty ? _empty() : _list(context, courses),
      ),
    );
  }

  Widget _empty() => ListView(
    physics: const AlwaysScrollableScrollPhysics(),
    children: [
      EmptyView(
        icon: Icons.grading_outlined,
        title: 'ยังไม่มีรายวิชา',
        message:
            'สร้างรายวิชาและผูกกับห้องเรียน แล้วตั้งหมวดคะแนนเพื่อเริ่มตัดเกรด',
        action: FilledButton.icon(
          key: const ValueKey('grades_create_course'),
          onPressed: () => _open(AppRoutes.courses),
          icon: const Icon(Icons.menu_book_outlined),
          label: const Text('ไปหน้ารายวิชา'),
        ),
      ),
    ],
  );

  Widget _list(BuildContext context, List<GradebookOverviewCourse> courses) {
    final theme = Theme.of(context);
    final years = {for (final c in courses) c.academicYear}.toList()
      ..sort((a, b) => b.compareTo(a));
    final year = years.contains(_year) ? _year! : years.first;
    final ofYear = courses.where((c) => c.academicYear == year).toList();
    // 1, 2, then ทั้งปี.
    final semesters = {for (final c in ofYear) c.semester}.toList()
      ..sort((a, b) => (a == 0 ? 3 : a).compareTo(b == 0 ? 3 : b));
    final semester = semesters.contains(_semester) ? _semester : null;
    final shown = ofYear
        .where((c) => semester == null || c.semester == semester)
        .toList();

    return ContentColumn(
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          Text('ปีการศึกษา', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              for (final y in years)
                ChoiceChip(
                  key: ValueKey('grades_year_$y'),
                  label: Text('$y'),
                  selected: y == year,
                  onSelected: (_) => setState(() {
                    _year = y;
                    _semester = null;
                  }),
                ),
            ],
          ),
          if (semesters.length > 1) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                ChoiceChip(
                  key: const ValueKey('grades_semester_all'),
                  label: const Text('ทุกภาคเรียน'),
                  selected: semester == null,
                  onSelected: (_) => setState(() => _semester = null),
                ),
                for (final s in semesters)
                  ChoiceChip(
                    key: ValueKey('grades_semester_$s'),
                    label: Text(semesterLabel(s)),
                    selected: s == semester,
                    onSelected: (_) => setState(() => _semester = s),
                  ),
              ],
            ),
          ],
          const SizedBox(height: 12),
          for (final course in shown)
            _CourseCard(
              course: course,
              exporting: _exporting,
              onOpen: _open,
              onExport: (room) => _export(course, room),
            ),
        ],
      ),
    );
  }
}

class _CourseCard extends StatelessWidget {
  const _CourseCard({
    required this.course,
    required this.exporting,
    required this.onOpen,
    required this.onExport,
  });

  final GradebookOverviewCourse course;
  final String? exporting;
  final void Function(String location) onOpen;
  final void Function(GradebookOverviewRoom room) onExport;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Card(
      key: ValueKey('grades_course_${course.id}'),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(course.title, style: theme.textTheme.titleMedium),
                Text(
                  '${gradeLevelLabel(course.gradeLevel)} · '
                  '${semesterLabel(course.semester)} · '
                  'ปีการศึกษา ${course.academicYear}',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
                if (!course.configured) ...[
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      StatusChip(
                        label: 'ยังไม่ตั้งหมวดคะแนน',
                        color: scheme.error,
                      ),
                      // The gradebook of an unconfigured course offers the
                      // templates first, then the settings (§23.9).
                      FilledButton.tonalIcon(
                        key: ValueKey('grades_setup_${course.id}'),
                        onPressed: () => onOpen(AppRoutes.gradebook(course.id)),
                        icon: const Icon(Icons.tune),
                        label: const Text('ตั้งค่าหมวดคะแนน'),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
          if (course.classrooms.isEmpty)
            ListTile(
              key: ValueKey('grades_no_rooms_${course.id}'),
              leading: const Icon(Icons.link_off),
              title: const Text('ยังไม่ได้ผูกกับห้องเรียน'),
              subtitle: const Text('ผูกห้องเรียนที่หน้ารายวิชา'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => onOpen(AppRoutes.course(course.id)),
            ),
          for (final room in course.classrooms) ...[
            const Divider(height: 1),
            _RoomRow(
              course: course,
              room: room,
              exporting: exporting == '${course.id}:${room.id}',
              busy: exporting != null,
              onOpen: onOpen,
              onExport: () => onExport(room),
            ),
          ],
        ],
      ),
    );
  }
}

class _RoomRow extends StatelessWidget {
  const _RoomRow({
    required this.course,
    required this.room,
    required this.exporting,
    required this.busy,
    required this.onOpen,
    required this.onExport,
  });

  final GradebookOverviewCourse course;
  final GradebookOverviewRoom room;
  final bool exporting;
  final bool busy;
  final void Function(String location) onOpen;
  final VoidCallback onExport;

  String get _key => '${course.id}_${room.id}';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final status = roomStatusChip(context, room);
    final counts = [
      if (room.atRiskMsCount > 0) 'อาจติด มส ${room.atRiskMsCount} คน',
      if (room.rCount > 0 || room.msCount > 0)
        'ร ${room.rCount} · มส ${room.msCount}',
    ];
    return InkWell(
      key: ValueKey('grades_room_$_key'),
      onTap: () => onOpen(AppRoutes.gradebook(course.id, classroomId: room.id)),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 4, 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${room.name} · ${room.studentCount} คน',
                    style: theme.textTheme.titleSmall,
                  ),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    children: [
                      status,
                      for (final c in counts)
                        StatusChip(label: c, color: theme.colorScheme.error),
                    ],
                  ),
                  if (room.status == GradebookRoomStatus.missingScores &&
                      room.emptyCategories.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      'ยังไม่มีคะแนน: ${room.emptyCategories.join(', ')}',
                      style: muted,
                    ),
                  ],
                ],
              ),
            ),
            IconButton(
              key: ValueKey('grades_open_$_key'),
              tooltip: 'เปิดสมุดคะแนน',
              icon: const Icon(Icons.table_chart_outlined),
              onPressed: () =>
                  onOpen(AppRoutes.gradebook(course.id, classroomId: room.id)),
            ),
            IconButton(
              key: ValueKey('grades_settings_$_key'),
              tooltip: 'ตั้งค่าสมุดคะแนน',
              icon: const Icon(Icons.tune),
              onPressed: () => onOpen(AppRoutes.gradebookSettings(course.id)),
            ),
            IconButton(
              key: ValueKey('grades_export_$_key'),
              tooltip: course.configured
                  ? 'ส่งออก CSV'
                  : 'ตั้งหมวดคะแนนก่อนส่งออก CSV',
              icon: exporting
                  ? const SizedBox.square(
                      dimension: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.ios_share),
              onPressed: course.configured && !busy ? onExport : null,
            ),
          ],
        ),
      ),
    );
  }
}

/// The status chip of a classroom on the "ตัดเกรด" page.
Widget roomStatusChip(BuildContext context, GradebookOverviewRoom room) {
  final scheme = Theme.of(context).colorScheme;
  final missing = room.emptyCategories.length;
  final (label, color) = switch (room.status) {
    GradebookRoomStatus.notConfigured => ('ยังไม่ตั้งหมวดคะแนน', scheme.error),
    GradebookRoomStatus.missingScores => (
      'ยังขาดคะแนน $missing หมวด',
      scheme.tertiary,
    ),
    GradebookRoomStatus.ready => ('พร้อมประกาศ', scheme.primary),
    GradebookRoomStatus.published => (
      room.publishedAt == null
          ? 'ประกาศแล้ว'
          : 'ประกาศแล้ว ${formatThaiDate(room.publishedAt!)}',
      Colors.green.shade700,
    ),
    GradebookRoomStatus.publishedStale => (
      'ประกาศแล้ว แต่คะแนนเปลี่ยน',
      scheme.error,
    ),
  };
  final chip = StatusChip(label: label, color: color);
  return room.status == GradebookRoomStatus.missingScores && missing > 0
      ? Tooltip(
          message: 'หมวดที่ยังไม่มีคะแนน: ${room.emptyCategories.join(', ')}',
          child: chip,
        )
      : chip;
}
