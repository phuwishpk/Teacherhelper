import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import '../courses/course_models.dart';
import '../courses/courses_providers.dart';
import '../dashboard/heatmap.dart';
import 'mastery_models.dart';
import 'mastery_repository.dart';
import 'mastery_widgets.dart';

/// Student x skill mastery heatmap of a classroom (DESIGN §9.6, §14.3),
/// filtered to a course or one of its units and grouped by standard
/// (§20.4 chart 3). Tapping a student opens their weaknesses.
class ClassroomMasteryScreen extends ConsumerStatefulWidget {
  const ClassroomMasteryScreen({
    super.key,
    required this.classroomId,
    this.initialCourseId,
  });

  final int classroomId;

  /// Opens with this course's indicators (e.g. from the course charts).
  final int? initialCourseId;

  @override
  ConsumerState<ClassroomMasteryScreen> createState() =>
      _ClassroomMasteryScreenState();
}

class _ClassroomMasteryScreenState
    extends ConsumerState<ClassroomMasteryScreen> {
  late int? _courseId = widget.initialCourseId;
  int? _unitId;

  @override
  Widget build(BuildContext context) {
    final classroomId = widget.classroomId;
    final classroom = ref.watch(classroomProvider(classroomId));
    final name = classroom.value?.name;
    // A subject teacher sees only their own courses and must pick one
    // (DESIGN §24.20); the homeroom teacher sees every course of the room.
    final subject = classroom.value?.isSubject ?? false;
    final teaching = ref.watch(classroomTeachingProvider(classroomId));
    final courses = teaching.value ?? const <ClassroomCourse>[];
    final courseId = _courseId ?? (subject ? courses.firstOrNull?.id : null);
    final picked = courses.where((c) => c.id == courseId).firstOrNull;
    final query = (
      classroomId: classroomId,
      courseId: courseId,
      unitId: _unitId,
    );
    // Asking without a course is refused for a subject teacher: wait for
    // their courses first.
    final noCourse = subject && courseId == null;
    final AsyncValue<ClassroomMastery> mastery =
        classroom.isLoading && !classroom.hasValue
        ? const AsyncLoading()
        : noCourse
        ? (teaching.hasError
              ? AsyncError(
                  teaching.error!,
                  teaching.stackTrace ?? StackTrace.empty,
                )
              : const AsyncLoading())
        : ref.watch(classroomMasteryProvider(query));
    // Units come from the course itself: only the teacher's own course.
    final units = courseId == null || !(picked?.isMine ?? true)
        ? const <CourseUnit>[]
        : ref.watch(courseDetailProvider(courseId)).value?.units ??
              const <CourseUnit>[];
    final filters = Wrap(
      spacing: 12,
      runSpacing: 8,
      children: [
        SizedBox(
          width: 260,
          child: DropdownButtonFormField<int?>(
            key: const ValueKey('heatmap_course'),
            initialValue: courses.any((c) => c.id == courseId)
                ? courseId
                : null,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'รายวิชา'),
            items: [
              if (!subject)
                const DropdownMenuItem(value: null, child: Text('ทุกทักษะ')),
              for (final c in courses)
                DropdownMenuItem(
                  value: c.id,
                  child: Text(
                    c.isMine || c.teacher == null
                        ? c.title
                        : '${c.title} (${c.teacher!.name})',
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
            ],
            onChanged: (v) => setState(() {
              _courseId = v;
              _unitId = null;
            }),
          ),
        ),
        if (courseId != null && units.isNotEmpty)
          SizedBox(
            width: 220,
            child: DropdownButtonFormField<int?>(
              key: ValueKey('heatmap_unit_$courseId'),
              initialValue: _unitId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'หน่วย'),
              items: [
                const DropdownMenuItem(value: null, child: Text('ทุกหน่วย')),
                for (final u in units)
                  DropdownMenuItem(
                    value: u.id,
                    child: Text('หน่วย ${u.position} ${u.title}'),
                  ),
              ],
              onChanged: (v) => setState(() => _unitId = v),
            ),
          ),
      ],
    );
    return Scaffold(
      appBar: AppBar(
        title: Text(name == null ? 'ทักษะของห้อง' : 'ทักษะของห้อง $name'),
      ),
      body: noCourse && teaching.hasValue
          ? const EmptyView(
              key: ValueKey('heatmap_no_course'),
              icon: Icons.menu_book_outlined,
              title: 'ยังไม่มีรายวิชาของคุณในห้องนี้',
              message: 'ส่งคำขอผูกรายวิชากับห้องนี้ แล้วรอครูประจำชั้นอนุมัติ',
            )
          : AsyncView(
              value: mastery,
              onRetry: () {
                ref.invalidate(classroomTeachingProvider(classroomId));
                ref.invalidate(classroomMasteryProvider(query));
              },
              data: (m) => RefreshIndicator(
                onRefresh: () =>
                    ref.refresh(classroomMasteryProvider(query).future),
                child: ContentColumn(
                  maxWidth: 1100,
                  child: ListView(
                    children: [
                      if (courses.isNotEmpty) ...[
                        const SizedBox(height: 8),
                        filters,
                        const SizedBox(height: 12),
                      ],
                      if (m.skills.isEmpty || m.students.isEmpty)
                        EmptyView(
                          icon: Icons.grid_on_outlined,
                          title: courseId == null
                              ? 'ยังไม่มีข้อมูลทักษะ'
                              : 'ยังไม่มีตัวชี้วัดในส่วนนี้',
                          message: courseId == null
                              ? 'heatmap จะแสดงหลังเผยแพร่ผลการบ้านที่ติดตัวชี้วัดไว้ '
                                    'หรือหลังนักเรียนทำแบบฝึก'
                              : 'เพิ่มตัวชี้วัดให้รายวิชา หน่วย หรือแผนการสอนก่อน',
                        )
                      else ...[
                        _WeakSkillsCard(mastery: m),
                        const SizedBox(height: 12),
                        const MasteryLegend(),
                        const SizedBox(height: 12),
                        MasteryHeatmap(
                          mastery: m,
                          onStudent: (s) => context.push(
                            AppRoutes.studentMastery(classroomId, s.id),
                          ),
                        ),
                      ],
                      const SizedBox(height: 24),
                    ],
                  ),
                ),
              ),
            ),
    );
  }
}

/// Skills with the lowest class mean: where to reteach first.
class _WeakSkillsCard extends StatelessWidget {
  const _WeakSkillsCard({required this.mastery});

  final ClassroomMastery mastery;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ranked = [
      for (final s in mastery.skills)
        if (mastery.skillMean(s.id) case final mean?) (skill: s, mean: mean),
    ]..sort((a, b) => a.mean.compareTo(b.mean));
    final weak = ranked
        .where((e) => e.mean < MasteryLevel.goodFrom)
        .take(3)
        .toList();
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('ทักษะที่ห้องนี้ควรทบทวน', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            if (weak.isEmpty)
              Text(
                ranked.isEmpty
                    ? 'ข้อมูลยังน้อย ต้องมีผลอย่างน้อย 2 ครั้งต่อทักษะ'
                    : 'ทุกทักษะเฉลี่ยอยู่ในระดับเข้าใจดี',
              )
            else
              for (final e in weak)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(
                    children: [
                      Icon(
                        MasteryLevel.of(e.mean, 2).icon,
                        size: 18,
                        color: MasteryLevel.of(e.mean, 2).color(context),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          '${e.skill.code} ${e.skill.name}',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      Text('เฉลี่ย ${percent(e.mean)}'),
                    ],
                  ),
                ),
          ],
        ),
      ),
    );
  }
}

/// The heatmap itself; also used by tests.
class MasteryHeatmap extends StatelessWidget {
  const MasteryHeatmap({super.key, required this.mastery, this.onStudent});

  final ClassroomMastery mastery;
  final ValueChanged<MasteryStudent>? onStudent;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final m = mastery;
    final spans = m.groups.fold(0, (n, g) => n + g.skillIds.length);
    return HeatmapGrid(
      key: const ValueKey('mastery_heatmap'),
      // Standards as a band over their indicators (§20.4 chart 3).
      columnGroups: spans == m.skills.length && m.groups.length > 1
          ? [
              for (final g in m.groups)
                HeatmapColumnGroup(
                  label: g.label,
                  span: g.skillIds.length,
                  tooltip: g.standardName == null
                      ? g.label
                      : '${g.label} ${g.standardName}',
                ),
            ]
          : const [],
      corner: const Text('นักเรียน'),
      cellWidth: 68,
      rows: [
        for (final s in m.students)
          Text(
            s.studentNumber == null ? s.name : '${s.studentNumber}. ${s.name}',
          ),
      ],
      columns: [
        for (final s in m.skills)
          HeatmapColumn(label: s.code, tooltip: '${s.code} ${s.name}'),
      ],
      onRowTap: onStudent == null ? null : (r) => onStudent!(m.students[r]),
      cell: (r, c) {
        final student = m.students[r];
        final skill = m.skills[c];
        final cell = m.cell(student.id, skill.id);
        if (cell == null) {
          return HeatmapCell(
            text: '–',
            fill: theme.colorScheme.surfaceContainerLow,
            textColor: theme.colorScheme.onSurfaceVariant,
            tooltip: '${student.name} · ${skill.code}: ยังไม่มีข้อมูล',
            onTap: onStudent == null ? null : () => onStudent!(student),
          );
        }
        final level = cell.level;
        return HeatmapCell(
          text: percent(cell.value),
          fill: masteryFill(context, level),
          textColor: level == MasteryLevel.tooLittle
              ? theme.colorScheme.onSurfaceVariant
              : theme.colorScheme.onSurface,
          tooltip:
              '${student.name} · ${skill.code}: ${percent(cell.value)} '
              '${level.label} (${cell.nObs} ครั้ง)',
          onTap: onStudent == null ? null : () => onStudent!(student),
        );
      },
    );
  }
}
