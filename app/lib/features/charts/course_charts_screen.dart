import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../courses/course_models.dart';
import '../courses/courses_providers.dart';
import '../mastery/course_mastery.dart';
import 'chart_style.dart';
import 'charts_repository.dart';
import 'pass_rate_chart.dart';
import 'plan_progress_chart.dart';
import 'progress_chart.dart';
import 'rollup_chart.dart';

/// The spider chart of a roll-up in a card: the standard/unit toggle, the
/// chart (a radar of nodes or of indicators, or bars), every node as a row, and the coverage under it
/// (DESIGN §20.4). Tapping an axis, a bar or a row opens the drill-down.
class RollupCard extends StatelessWidget {
  const RollupCard({
    super.key,
    required this.title,
    required this.summary,
    required this.axis,
    required this.onAxis,
    required this.onNode,
    required this.onRetry,
  });

  final String title;
  final AsyncValue<CourseMasterySummary> summary;
  final MasteryAxis axis;
  final ValueChanged<MasteryAxis> onAxis;
  final ValueChanged<RollupNode> onNode;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final s = summary.value;
    return ChartCard(
      title: title,
      subtitle: 'แตะแกน แท่ง หรือแถวเพื่อดูตัวชี้วัดในนั้น',
      footer: s == null
          ? null
          : [
              if (s.summary.value != null)
                'ทั้งรายวิชา ${pct(s.summary.value!)}',
              s.summary.coverageText,
            ].join(' · '),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: AxisToggle<MasteryAxis>(
              key: const ValueKey('rollup_axis'),
              values: MasteryAxis.values,
              selected: axis,
              label: (a) => a.label,
              onChanged: onAxis,
            ),
          ),
          const SizedBox(height: 12),
          AsyncView(
            value: summary,
            onRetry: onRetry,
            data: (s) => Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                RollupChart(summary: s, onNode: onNode),
                if (!s.useRadar && s.nodes.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      'ประเมินแล้ว ${s.assessedNodes.length} กลุ่ม '
                      '${s.assessedIndicators.length} ตัวชี้วัด '
                      '(เรดาร์ใช้ $kRadarMinAxes–$kRadarMaxAxes แกน) จึงแสดงเป็นกราฟแท่ง',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ),
                const SizedBox(height: 8),
                RollupNodeList(nodes: s.nodes, onNode: onNode),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// `/courses/:id/charts`: the teacher's charts of one course in one
/// classroom (DESIGN §20.4): plan progress (5), the classroom roll-up,
/// % passing per indicator (2), each student (to their spider and
/// progress) and the heatmap (3). Graphs only, no AI text (§20.4).
class CourseChartsScreen extends ConsumerStatefulWidget {
  const CourseChartsScreen({
    super.key,
    required this.courseId,
    this.initialClassroomId,
  });

  final int courseId;
  final int? initialClassroomId;

  @override
  ConsumerState<CourseChartsScreen> createState() => _CourseChartsScreenState();
}

class _CourseChartsScreenState extends ConsumerState<CourseChartsScreen> {
  late int? _classroomId = widget.initialClassroomId;
  MasteryAxis _axis = MasteryAxis.standard;

  @override
  Widget build(BuildContext context) {
    final course = ref.watch(courseDetailProvider(widget.courseId));
    return Scaffold(
      appBar: AppBar(
        title: Text(
          course.value == null ? 'กราฟรายวิชา' : 'กราฟ ${course.value!.title}',
        ),
      ),
      body: AsyncView(
        value: course,
        onRetry: () => ref.invalidate(courseDetailProvider(widget.courseId)),
        data: (c) {
          if (c.classrooms.isEmpty) {
            return const EmptyView(
              icon: Icons.meeting_room_outlined,
              title: 'รายวิชานี้ยังไม่ได้ผูกกับห้องเรียน',
              message: 'ผูกห้องเรียนในหน้ารายวิชาก่อน แล้วกราฟจะแสดงที่นี่',
            );
          }
          final roomId = c.classrooms.any((r) => r.id == _classroomId)
              ? _classroomId!
              : c.classrooms.first.id;
          return _body(context, c, roomId);
        },
      ),
    );
  }

  Widget _body(BuildContext context, Course c, int roomId) {
    final summaryQuery = (
      courseId: c.id,
      classroomId: roomId,
      studentId: null,
      axis: _axis,
    );
    final summary = ref.watch(courseMasterySummaryProvider(summaryQuery));
    final passQuery = (classroomId: roomId, courseId: c.id);
    final passRate = ref.watch(indicatorPassRateProvider(passQuery));
    final planQuery = (courseId: c.id, classroomId: roomId);
    final plan = ref.watch(planProgressProvider(planQuery));
    return RefreshIndicator(
      onRefresh: () {
        ref.invalidate(courseMasterySummaryProvider(summaryQuery));
        ref.invalidate(indicatorPassRateProvider(passQuery));
        return ref.refresh(planProgressProvider(planQuery).future);
      },
      child: ContentColumn(
        maxWidth: 900,
        child: ListView(
          padding: const EdgeInsets.only(bottom: 32),
          children: [
            if (c.classrooms.length > 1)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: DropdownButtonFormField<int>(
                  key: const ValueKey('charts_classroom'),
                  initialValue: roomId,
                  decoration: const InputDecoration(labelText: 'ห้องเรียน'),
                  items: [
                    for (final r in c.classrooms)
                      DropdownMenuItem(value: r.id, child: Text(r.name)),
                  ],
                  onChanged: (v) => setState(() => _classroomId = v),
                ),
              )
            else
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Text('ห้อง ${c.classrooms.single.name}'),
              ),
            ChartCard(
              title: 'ความคืบหน้าตามแผนรายวิชา',
              subtitle: 'ตัวชี้วัดที่วางแผนไว้ในแต่ละหน่วย',
              footer: plan.value == null
                  ? null
                  : 'ประเมินแล้ว ${plan.value!.summary.assessed}/${plan.value!.summary.planned} ตัวชี้วัด · '
                        'สอนแล้ว ${plan.value!.summary.taught} ตัวชี้วัด '
                        '(${plan.value!.summary.plansTaught}/${plan.value!.summary.plansTotal} แผน)',
              child: AsyncView(
                value: plan,
                onRetry: () => ref.invalidate(planProgressProvider(planQuery)),
                data: (p) => Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    PlanProgressChart(data: p),
                    if (p.units.isNotEmpty) PlanProgressTable(data: p),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 12),
            RollupCard(
              title: 'ภาพรวมของห้อง',
              summary: summary,
              axis: _axis,
              onAxis: (a) => setState(() => _axis = a),
              onNode: (n) => showNodeIndicators(context, n),
              onRetry: () =>
                  ref.invalidate(courseMasterySummaryProvider(summaryQuery)),
            ),
            const SizedBox(height: 12),
            ChartCard(
              title: 'ร้อยละนักเรียนที่ผ่านแต่ละตัวชี้วัด',
              subtitle: passRate.value == null
                  ? null
                  : 'ผ่าน = ความเข้าใจ ≥ ${pct(passRate.value!.passThreshold)} · '
                        'n = จำนวนนักเรียนที่ประเมินแล้ว',
              footer: passRate.value == null
                  ? null
                  : coverageLabel(
                      passRate.value!.assessedCount,
                      passRate.value!.indicators.length,
                    ),
              child: AsyncView(
                value: passRate,
                onRetry: () =>
                    ref.invalidate(indicatorPassRateProvider(passQuery)),
                data: (p) => Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    PassRateChart(data: p),
                    if (p.indicators.isNotEmpty) PassRateTable(data: p),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 12),
            Card(
              child: ListTile(
                key: const ValueKey('charts_heatmap'),
                leading: const Icon(Icons.grid_on_outlined),
                title: const Text('Heatmap นักเรียน × ตัวชี้วัด'),
                subtitle: const Text('จัดกลุ่มตามมาตรฐาน กรองตามหน่วยได้'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push(
                  AppRoutes.classroomMastery(roomId, courseId: c.id),
                ),
              ),
            ),
            const SizedBox(height: 12),
            _StudentsCard(
              summary: summary.value,
              onStudent: (s) => context.push(
                AppRoutes.studentCourseCharts(c.id, s.id, classroomId: roomId),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StudentsCard extends StatelessWidget {
  const _StudentsCard({required this.summary, required this.onStudent});

  final CourseMasterySummary? summary;
  final ValueChanged<RollupStudent> onStudent;

  @override
  Widget build(BuildContext context) {
    final students = summary?.students ?? const <RollupStudent>[];
    return ChartCard(
      title: 'รายคน',
      subtitle: 'แตะชื่อเพื่อดูกราฟเรดาร์และพัฒนาการของนักเรียนคนนั้น',
      child: students.isEmpty
          ? const ChartEmpty(message: 'ยังไม่มีนักเรียนในห้องนี้')
          : Column(
              children: [
                for (final s in students)
                  ListTile(
                    key: ValueKey('charts_student_${s.id}'),
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text(
                      s.studentNumber == null
                          ? s.name
                          : '${s.studentNumber}. ${s.name}',
                    ),
                    subtitle: Text(coverageLabel(s.assessed, s.planned)),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(s.value == null ? 'ยังไม่ประเมิน' : pct(s.value!)),
                        const Icon(Icons.chevron_right),
                      ],
                    ),
                    onTap: () => onStudent(s),
                  ),
              ],
            ),
    );
  }
}

/// One student's spider (with the axis toggle and drill-down) and their
/// progress over time. [studentId] null = the signed-in student's own view
/// (no class average, no ranking: DESIGN §20.4, §20.9).
class StudentCourseChartsBody extends ConsumerStatefulWidget {
  const StudentCourseChartsBody({
    super.key,
    required this.courseId,
    this.studentId,
    this.classroomId,
    this.studentName,
  });

  final int courseId;
  final int? studentId;
  final int? classroomId;

  /// Shown in the spider chart's title in the teacher's per-student view.
  final String? studentName;

  @override
  ConsumerState<StudentCourseChartsBody> createState() =>
      _StudentCourseChartsBodyState();
}

class _StudentCourseChartsBodyState
    extends ConsumerState<StudentCourseChartsBody> {
  MasteryAxis _axis = MasteryAxis.standard;
  final _selection = ProgressSelection();

  bool get _own => widget.studentId == null;

  @override
  Widget build(BuildContext context) {
    final teacherQuery = (
      courseId: widget.courseId,
      classroomId: widget.classroomId,
      studentId: widget.studentId,
      axis: _axis,
    );
    final ownQuery = (courseId: widget.courseId, axis: _axis);
    final summary = _own
        ? ref.watch(myCourseMasteryProvider(ownQuery))
        : ref.watch(courseMasterySummaryProvider(teacherQuery));
    return ContentColumn(
      maxWidth: 900,
      child: ListView(
        padding: const EdgeInsets.only(top: 8, bottom: 32),
        children: [
          RollupCard(
            title: _own
                ? 'ความเข้าใจของฉัน'
                : widget.studentName == null
                ? 'ความเข้าใจรายตัวชี้วัด'
                : 'ความเข้าใจของ ${widget.studentName}',
            summary: summary,
            axis: _axis,
            onAxis: (a) => setState(() => _axis = a),
            onNode: (n) async {
              final skill = await showNodeIndicators(
                context,
                n,
                canShowProgress: true,
              );
              if (skill != null && context.mounted) {
                addProgressSkill(
                  context,
                  _selection,
                  skill,
                  () => setState(() {}),
                );
              }
            },
            onRetry: () => _own
                ? ref.invalidate(myCourseMasteryProvider(ownQuery))
                : ref.invalidate(courseMasterySummaryProvider(teacherQuery)),
          ),
          const SizedBox(height: 12),
          IndicatorProgressCard(
            studentId: widget.studentId,
            courseId: _own ? null : widget.courseId,
            selection: _selection,
            onChanged: () => setState(() {}),
          ),
        ],
      ),
    );
  }
}

/// `/courses/:id/students/:sid/charts`: the teacher's view of one student.
class StudentCourseChartsScreen extends ConsumerWidget {
  const StudentCourseChartsScreen({
    super.key,
    required this.courseId,
    required this.studentId,
    this.classroomId,
  });

  final int courseId;
  final int studentId;
  final int? classroomId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final name = classroomId == null
        ? null
        : ref
              .watch(
                courseMasterySummaryProvider((
                  courseId: courseId,
                  classroomId: classroomId,
                  studentId: null,
                  axis: MasteryAxis.standard,
                )),
              )
              .value
              ?.students
              .where((s) => s.id == studentId)
              .firstOrNull
              ?.name;
    return Scaffold(
      appBar: AppBar(title: Text(name ?? 'กราฟของนักเรียน')),
      body: StudentCourseChartsBody(
        courseId: courseId,
        studentId: studentId,
        classroomId: classroomId,
        studentName: name,
      ),
    );
  }
}

/// `/student/courses/:id`: a student's own charts of one course.
class MyCourseChartsScreen extends ConsumerWidget {
  const MyCourseChartsScreen({super.key, required this.courseId});

  final int courseId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final course = ref
        .watch(myCoursesProvider)
        .value
        ?.where((c) => c.id == courseId)
        .firstOrNull;
    return Scaffold(
      appBar: AppBar(title: Text(course?.title ?? 'รายวิชาของฉัน')),
      body: StudentCourseChartsBody(courseId: courseId),
    );
  }
}

/// The student's courses at the top of the "ทักษะ" tab, each opening
/// [MyCourseChartsScreen]. Nothing when the student has no course.
class MyCoursesSection extends ConsumerWidget {
  const MyCoursesSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final courses = ref.watch(myCoursesProvider).value ?? const [];
    if (courses.isEmpty) return const SizedBox.shrink();
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 4),
              child: Text(
                'กราฟตามรายวิชา',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            for (final c in courses)
              ListTile(
                key: ValueKey('my_course_${c.id}'),
                leading: const Icon(Icons.radar),
                title: Text(c.title),
                subtitle: _courseSubtitle(c),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push(AppRoutes.myCourseCharts(c.id)),
              ),
          ],
        ),
      ),
    );
  }
}

/// The subject and the classrooms of a student's course (DESIGN §24.11).
Widget? _courseSubtitle(StudentCourse c) {
  final text = [
    ?c.subjectName,
    for (final room in c.classrooms) room.text,
  ].join(' · ');
  return text.isEmpty ? null : Text(text);
}
