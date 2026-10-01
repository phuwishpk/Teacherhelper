import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import '../courses/courses_providers.dart';
import 'mastery_models.dart';
import 'mastery_page.dart';
import 'mastery_repository.dart';
import 'mastery_widgets.dart';

/// Teacher view of one student: the three weakest skills (DESIGN §14.3
/// "จุดอ่อนรายคน"), a link to their charts of each course bound to the
/// classroom (§20.4) and every skill below them.
class StudentMasteryScreen extends ConsumerWidget {
  const StudentMasteryScreen({
    super.key,
    required this.classroomId,
    required this.studentId,
    this.courseId,
  });

  final int classroomId;
  final int studentId;

  /// Only this course's indicators. A subject teacher (DESIGN §24.20) must
  /// name a course; without one the screen uses their first course of the
  /// room.
  final int? courseId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final classroom = ref.watch(classroomProvider(classroomId));
    final subject = classroom.value?.isSubject ?? false;
    var courseId = this.courseId;
    if (courseId == null && subject) {
      courseId = ref
          .watch(classroomTeachingProvider(classroomId))
          .value
          ?.firstOrNull
          ?.id;
    }
    // Wait for the room (and a subject teacher's course): asking without
    // a course would be refused for a subject teacher.
    final waiting =
        (classroom.isLoading && !classroom.hasValue) ||
        (subject && courseId == null);
    final query = (studentId: studentId, courseId: courseId);
    final AsyncValue<List<SkillMastery>> mastery = waiting
        ? const AsyncLoading()
        : ref.watch(studentMasteryProvider(query));
    final roster = ref.watch(rosterProvider(classroomId)).value ?? const [];
    final student = roster.where((s) => s.studentId == studentId).firstOrNull;
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(
          student == null
              ? 'ทักษะของนักเรียน'
              : '${student.studentNumber}. ${student.name}',
        ),
        actions: [
          // The AI analysis is the homeroom teacher's (DESIGN §24.8).
          if (!subject)
            IconButton(
              tooltip: 'วิเคราะห์รายคน (AI)',
              icon: const Icon(Icons.auto_awesome_outlined),
              onPressed: () => context.push(
                AppRoutes.studentAnalysis(classroomId, studentId),
              ),
            ),
        ],
      ),
      body: AsyncView(
        value: mastery,
        onRetry: () => ref.invalidate(studentMasteryProvider(query)),
        data: (rows) {
          if (rows.isEmpty) {
            return EmptyView(
              icon: Icons.insights_outlined,
              title: 'ยังไม่มีข้อมูลทักษะ',
              message: 'จะแสดงหลังเผยแพร่ผลการบ้านของนักเรียนคนนี้',
              action: StudentCourseChartLinks(
                classroomId: classroomId,
                studentId: studentId,
              ),
            );
          }
          final sorted = SkillMastery.weakestFirst(rows);
          final weakest = sorted
              .where(
                (m) =>
                    m.level != MasteryLevel.tooLittle &&
                    m.level != MasteryLevel.good,
              )
              .take(3)
              .toList();
          return RefreshIndicator(
            onRefresh: () => ref.refresh(studentMasteryProvider(query).future),
            child: ContentColumn(
              child: ListView(
                children: [
                  Card(
                    color: theme.colorScheme.surfaceContainerHigh,
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'จุดอ่อน 3 อันดับ',
                            style: theme.textTheme.titleMedium,
                          ),
                          const SizedBox(height: 8),
                          if (weakest.isEmpty)
                            const Text(
                              'ยังไม่พบทักษะที่ต่ำกว่าระดับเข้าใจดี (หรือข้อมูลยังน้อย)',
                            )
                          else
                            for (final (i, m) in weakest.indexed)
                              ListTile(
                                contentPadding: EdgeInsets.zero,
                                leading: CircleAvatar(
                                  radius: 14,
                                  child: Text('${i + 1}'),
                                ),
                                title: Text('${m.skill.code} ${m.skill.name}'),
                                subtitle: Text(
                                  '${percent(m.value)} จาก ${m.nObs} ครั้ง',
                                ),
                                trailing: MasteryLevelChip(level: m.level),
                              ),
                        ],
                      ),
                    ),
                  ),
                  StudentCourseChartLinks(
                    classroomId: classroomId,
                    studentId: studentId,
                  ),
                  const SizedBox(height: 12),
                  const MasteryLegend(),
                  const SizedBox(height: 12),
                  for (final m in sorted) SkillMasteryCard(mastery: m),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// "กราฟตามรายวิชา": one link per course bound to the classroom, to the
/// student's spider and progress in that course (DESIGN §20.4). Nothing
/// while the courses load, when they fail, or when there are none.
class StudentCourseChartLinks extends ConsumerWidget {
  const StudentCourseChartLinks({
    super.key,
    required this.classroomId,
    required this.studentId,
  });

  final int classroomId;
  final int studentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final courses =
        ref.watch(classroomCoursesProvider(classroomId)).value ?? const [];
    if (courses.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
              child: Text(
                'กราฟตามรายวิชา',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            for (final c in courses)
              ListTile(
                key: ValueKey('student_course_charts_${c.id}'),
                leading: const Icon(Icons.radar),
                title: Text('กราฟรายวิชา ${c.code.isEmpty ? c.name : c.code}'),
                subtitle: Text(c.name),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push(
                  AppRoutes.studentCourseCharts(
                    c.id,
                    studentId,
                    classroomId: classroomId,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
