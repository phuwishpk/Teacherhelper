import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'course_document_flow.dart';
import 'course_models.dart';
import 'courses_providers.dart';

/// "สร้างรายวิชา": fill in the form, or let AI read a course description
/// or course structure first (DESIGN §20.1).
Future<void> startNewCourse(
  BuildContext context,
  WidgetRef ref, {
  int? classroomId,
}) async {
  final choice = await showModalBottomSheet<String>(
    context: context,
    showDragHandle: true,
    builder: (context) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const ListTile(
            title: Text('สร้างรายวิชา'),
            subtitle: Text(
              'สร้างครั้งเดียว แล้วผูกกับห้องเรียนที่สอนวิชานี้ได้หลายห้อง',
            ),
          ),
          ListTile(
            key: const ValueKey('new_course_form'),
            leading: const Icon(Icons.edit_note),
            title: const Text('กรอกในฟอร์ม'),
            onTap: () => Navigator.of(context).pop('form'),
          ),
          ListTile(
            key: const ValueKey('new_course_document'),
            leading: const Icon(Icons.auto_awesome_outlined),
            title: const Text('ให้ AI อ่านจากเอกสาร'),
            subtitle: const Text(
              'คำอธิบายรายวิชาหรือโครงสร้างรายวิชา (PDF หรือรูป)',
            ),
            onTap: () => Navigator.of(context).pop('document'),
          ),
        ],
      ),
    ),
  );
  if (!context.mounted || choice == null) return;
  if (choice == 'form') {
    await context.push(AppRoutes.courseNewFor(classroomId));
    return;
  }
  final course = await runCourseDocumentImport(
    context,
    ref,
    purpose: CourseDocumentPurpose.course,
  );
  if (course != null && context.mounted) {
    await context.push(AppRoutes.course(course.id));
  }
}

/// `/courses`: the teacher's courses, newest academic year first.
class CoursesScreen extends ConsumerWidget {
  const CoursesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final courses = ref.watch(coursesProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('รายวิชาและแผนการสอน')),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'course_new',
        onPressed: () => startNewCourse(context, ref),
        icon: const Icon(Icons.add),
        label: const Text('สร้างรายวิชา'),
      ),
      body: AsyncView(
        value: courses,
        onRetry: () => ref.invalidate(coursesProvider),
        data: (list) {
          if (list.isEmpty) {
            return const EmptyView(
              icon: Icons.menu_book_outlined,
              title: 'ยังไม่มีรายวิชา',
              message:
                  'สร้างรายวิชาครั้งเดียวแล้วผูกกับห้องเรียน การบ้านใหม่ทุกงานต้องเลือกรายวิชา',
            );
          }
          return RefreshIndicator(
            onRefresh: () => ref.refresh(coursesProvider.future),
            child: ContentColumn(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
              child: ListView(
                children: [for (final c in list) CourseCard(course: c)],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// One course in a list: code, name, term, classrooms and counts.
class CourseCard extends StatelessWidget {
  const CourseCard({super.key, required this.course});

  final Course course;

  @override
  Widget build(BuildContext context) {
    final c = course;
    return Card(
      child: ListTile(
        key: ValueKey('course_${c.id}'),
        leading: const CircleAvatar(child: Icon(Icons.menu_book_outlined)),
        title: Text(c.title),
        subtitle: Text(
          [
            c.termLabel,
            if (c.classrooms.isEmpty)
              'ยังไม่ผูกห้องเรียน'
            else
              c.classrooms.map((r) => r.name).join(', '),
            'ตัวชี้วัด ${c.indicatorCount} · หน่วย ${c.unitCount} · แผน ${c.lessonPlanCount}',
          ].join('\n'),
        ),
        isThreeLine: true,
        trailing: const Icon(Icons.chevron_right),
        onTap: () => context.push(AppRoutes.course(c.id)),
      ),
    );
  }
}
