import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../charts/course_charts_screen.dart' show StudentCourseChartsBody;
import '../gradebook/student_grades.dart' show StudentGradeBody;
import '../hand_in/hand_in_repository.dart';
import '../hand_in/student_assignments_page.dart' show StudentAssignmentCard;
import '../results/results_page.dart' show StudentResultTile;
import '../results/results_repository.dart';
import 'student_labels.dart';
import 'student_overview.dart';

/// `/student/subject?key=`: one subject of "วิชาของฉัน" (DESIGN §24.11,
/// §24.13) in one classroom: its work to hand in, published results, grade
/// and charts. "เปลี่ยนวิชา" switches to another subject in place. Work
/// without a course ("อื่นๆ") has no grade or charts.
class StudentSubjectScreen extends ConsumerStatefulWidget {
  const StudentSubjectScreen({super.key, required this.initialKey, this.now});

  /// A [SubjectTag.key] of `GET /student/overview`.
  final String initialKey;

  /// Clock for the overdue labels (tests).
  final DateTime Function()? now;

  @override
  ConsumerState<StudentSubjectScreen> createState() =>
      _StudentSubjectScreenState();
}

class _StudentSubjectScreenState extends ConsumerState<StudentSubjectScreen> {
  late String _key = widget.initialKey;

  Future<void> _switch(StudentOverview overview) async {
    final key = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _SubjectSwitcher(overview: overview, current: _key),
    );
    if (key != null && mounted) setState(() => _key = key);
  }

  @override
  Widget build(BuildContext context) {
    final overview = ref.watch(studentOverviewProvider);
    Scaffold plain(Widget body) => Scaffold(
      appBar: AppBar(title: const Text('วิชาของฉัน')),
      body: body,
    );
    return overview.when(
      skipLoadingOnRefresh: true,
      loading: () => plain(const Center(child: CircularProgressIndicator())),
      error: (e, _) => plain(
        ErrorView(
          message: apiErrorMessage(e),
          onRetry: () => ref.invalidate(studentOverviewProvider),
        ),
      ),
      data: (o) {
        final group = o.groupOf(_key);
        if (group == null) {
          return plain(
            const EmptyView(
              icon: Icons.search_off,
              title: 'ไม่พบรายวิชานี้',
              message:
                  'ครูอาจเอารายวิชานี้ออกจากห้องแล้ว '
                  'กลับไปที่ "วิชาของฉัน" เพื่อดูรายวิชาทั้งหมด',
            ),
          );
        }
        return _subject(context, o, group);
      },
    );
  }

  Widget _subject(BuildContext context, StudentOverview o, SubjectGroup g) {
    final tag = g.tag;
    final course = g.course;
    final theme = Theme.of(context);
    final tabs = <(String, Widget)>[
      ('งาน', _WorkTab(tag: tag, now: widget.now)),
      ('ผล', _ResultsTab(tag: tag)),
      if (course != null) ...[
        (
          'เกรด',
          StudentGradeBody(courseId: course.id, classroomId: g.classroom.id),
        ),
        ('กราฟ', StudentCourseChartsBody(courseId: course.id)),
      ],
    ];
    final subtitle = [?tag.classroomText, ?g.teacherName].join(' · ');
    return DefaultTabController(
      key: ValueKey('subject_tabs_${tag.key}'),
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(tag.title, overflow: TextOverflow.ellipsis),
              if (subtitle.isNotEmpty)
                Text(
                  subtitle,
                  key: const ValueKey('subject_subtitle'),
                  style: theme.textTheme.bodySmall,
                  overflow: TextOverflow.ellipsis,
                ),
            ],
          ),
          actions: [
            if (o.groups.length > 1)
              IconButton(
                key: const ValueKey('subject_switch'),
                tooltip: 'เปลี่ยนวิชา',
                icon: const Icon(Icons.swap_horiz),
                onPressed: () => _switch(o),
              ),
          ],
          bottom: TabBar(
            tabs: [for (final (label, _) in tabs) Tab(text: label)],
          ),
        ),
        body: Column(
          children: [
            if (g.classroom.closed)
              Container(
                key: const ValueKey('subject_closed_banner'),
                width: double.infinity,
                color: theme.colorScheme.surfaceContainerHighest,
                padding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 10,
                ),
                child: const Text('ห้องเก่า อ่านอย่างเดียว ส่งงานเพิ่มไม่ได้'),
              ),
            Expanded(
              child: TabBarView(children: [for (final (_, body) in tabs) body]),
            ),
          ],
        ),
      ),
    );
  }
}

/// The bottom sheet of "เปลี่ยนวิชา": every subject, open classrooms first,
/// then "ห้องเก่า". Pops the chosen [SubjectTag.key].
class _SubjectSwitcher extends StatelessWidget {
  const _SubjectSwitcher({required this.overview, required this.current});

  final StudentOverview overview;
  final String current;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final closed = overview.closedGroups;
    Widget tile(SubjectGroup g) {
      final tag = g.tag;
      return ListTile(
        key: ValueKey('switch_${tag.key}'),
        selected: tag.key == current,
        title: Text(tag.title),
        subtitle: Text([?tag.classroomText, ?g.teacherName].join(' · ')),
        trailing: g.todoCount > 0 ? Badge(label: Text('${g.todoCount}')) : null,
        onTap: () => Navigator.of(context).pop(tag.key),
      );
    }

    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxHeight: MediaQuery.sizeOf(context).height * 0.8,
        ),
        child: ListView(
          shrinkWrap: true,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: Text('เปลี่ยนวิชา', style: theme.textTheme.titleMedium),
            ),
            for (final g in overview.openGroups) tile(g),
            if (closed.isNotEmpty) ...[
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                child: Text('ห้องเก่า', style: theme.textTheme.titleSmall),
              ),
              for (final g in closed) tile(g),
            ],
          ],
        ),
      ),
    );
  }
}

/// "งาน": the subject's assignments, as on the "ส่งงาน" tab.
class _WorkTab extends ConsumerWidget {
  const _WorkTab({required this.tag, this.now});

  final SubjectTag tag;
  final DateTime Function()? now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final list = ref.watch(studentAssignmentsProvider);
    return AsyncView(
      value: list,
      onRetry: () => ref.invalidate(studentAssignmentsProvider),
      data: (all) {
        final items = [
          for (final a in all)
            if (a.tag.key == tag.key) a,
        ];
        final time = (now ?? DateTime.now)();
        return RefreshIndicator(
          onRefresh: () => ref.refresh(studentAssignmentsProvider.future),
          child: items.isEmpty
              ? ListView(
                  children: [
                    EmptyView(
                      icon: Icons.task_alt,
                      title: 'ไม่มีงานของวิชานี้',
                      message: tag.closed
                          ? 'ห้องเก่าไม่รับงานแล้ว'
                          : 'เมื่อครูสั่งงานของวิชานี้ งานจะขึ้นที่นี่',
                    ),
                  ],
                )
              : ContentColumn(
                  child: ListView(
                    children: [
                      for (final a in items)
                        StudentAssignmentCard(assignment: a, now: time),
                    ],
                  ),
                ),
        );
      },
    );
  }
}

/// "ผล": the subject's published results, newest first.
class _ResultsTab extends ConsumerWidget {
  const _ResultsTab({required this.tag});

  final SubjectTag tag;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final list = ref.watch(studentResultsProvider);
    return AsyncView(
      value: list,
      onRetry: () => ref.invalidate(studentResultsProvider),
      data: (all) {
        final items = [
          for (final r in all)
            if (r.tag.key == tag.key) r,
        ];
        return RefreshIndicator(
          onRefresh: () => ref.read(studentResultsProvider.notifier).refresh(),
          child: items.isEmpty
              ? ListView(
                  children: const [
                    EmptyView(
                      icon: Icons.inbox_outlined,
                      title: 'ยังไม่มีผลของวิชานี้',
                      message:
                          'เมื่อครูตรวจและเผยแพร่ผลของวิชานี้แล้ว '
                          'จะเห็นคะแนนที่นี่',
                    ),
                  ],
                )
              : ContentColumn(
                  child: ListView(
                    children: [
                      for (final r in items) StudentResultTile(result: r),
                    ],
                  ),
                ),
        );
      },
    );
  }
}
