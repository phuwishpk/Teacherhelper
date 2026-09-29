import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import 'classroom_google_section.dart' show gradeReturnNote;
import 'google_models.dart';
import 'google_providers.dart';
import 'google_repository.dart';

/// Picks a Google Classroom course. For a classroom ([classroomId] set) it
/// links the course (`POST /classrooms/{id}/google-link`) and goes on to
/// the student matching screen (DESIGN §18.7). Without one
/// ([GoogleCoursePickerScreen.forImport]) it opens the import preview of
/// the course (§19.2). Courses already linked to a room are shown faded
/// and cannot be picked (409 `course_already_linked`).
class GoogleCoursePickerScreen extends ConsumerStatefulWidget {
  const GoogleCoursePickerScreen({super.key, required int this.classroomId});

  const GoogleCoursePickerScreen.forImport({super.key}) : classroomId = null;

  final int? classroomId;

  bool get importing => classroomId == null;

  @override
  ConsumerState<GoogleCoursePickerScreen> createState() =>
      _GoogleCoursePickerScreenState();
}

class _GoogleCoursePickerScreenState
    extends ConsumerState<GoogleCoursePickerScreen> {
  String? _linking;

  void _pick(GoogleCourse course) {
    if (widget.classroomId case final id?) {
      _link(id, course);
    } else {
      _import(course);
    }
  }

  /// The preview pops with the new classroom's id once it is created.
  Future<void> _import(GoogleCourse course) async {
    final id = await context.push<int>(
      AppRoutes.classroomImportPreview(course.courseId),
    );
    if (id == null || !mounted) return;
    ref.invalidate(googleCoursesProvider);
    context.pushReplacement(AppRoutes.classroom(id));
  }

  Future<void> _link(int classroomId, GoogleCourse course) async {
    setState(() => _linking = course.courseId);
    try {
      final link = await ref
          .read(googleClassroomRepositoryProvider)
          .link(classroomId, course);
      ref.read(classroomsProvider.notifier).setGoogleLink(classroomId, link);
      ref.invalidate(googleCoursesProvider);
      if (!mounted) return;
      showMessage(
        context,
        'ผูกกับ ${course.name} แล้ว จับคู่นักเรียนต่อได้เลย',
      );
      context.pushReplacement(AppRoutes.classroomGoogleRoster(classroomId));
    } catch (e) {
      ref.read(googleStatusProvider.notifier).noteError(e);
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _linking = null);
    }
  }

  Widget _tile(GoogleCourse course) {
    final linked = course.linkedClassroom;
    final details = [
      ?course.section,
      if (linked != null) 'ผูกกับ ${linked.name} แล้ว',
    ];
    return ListTile(
      key: ValueKey('google_course_${course.courseId}'),
      leading: Icon(linked == null ? Icons.class_outlined : Icons.link),
      title: Text(course.name),
      subtitle: details.isEmpty ? null : Text(details.join(' · ')),
      trailing: _linking == course.courseId
          ? const SizedBox.square(
              dimension: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : linked == null
          ? const Icon(Icons.chevron_right)
          : null,
      // A linked course stays in the list, faded, so the teacher sees why
      // it cannot be picked.
      enabled: _linking == null && linked == null,
      onTap: () => _pick(course),
    );
  }

  @override
  Widget build(BuildContext context) {
    final courses = ref.watch(googleCoursesProvider);
    final classroom = switch (widget.classroomId) {
      final id? => ref.watch(classroomProvider(id)).value,
      null => null,
    };
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.importing
              ? 'นำเข้าจาก Google Classroom'
              : 'เลือกคอร์สใน Google Classroom',
        ),
      ),
      body: ContentColumn(
        child: ListView(
          children: [
            Card(
              color: theme.colorScheme.secondaryContainer,
              child: ListTile(
                leading: const Icon(Icons.info_outline),
                title: Text(switch ((widget.importing, classroom)) {
                  (true, _) =>
                    'เลือกคอร์สที่จะสร้างเป็นห้องเรียนใหม่ พร้อมรายชื่อนักเรียน',
                  (false, null) => 'เลือกคอร์สที่จะผูกกับห้องนี้',
                  (false, final c?) => 'เลือกคอร์สที่จะผูกกับห้อง ${c.name}',
                }),
                subtitle: const Text(gradeReturnNote),
              ),
            ),
            const SizedBox(height: 8),
            switch (courses) {
              AsyncData(:final value) when value.isEmpty => const Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'ไม่พบคอร์สที่คุณเป็นผู้สอนและยังเปิดใช้อยู่ '
                  'สร้างชั้นเรียนใน classroom.google.com ก่อน แล้วกลับมาที่หน้านี้',
                  textAlign: TextAlign.center,
                ),
              ),
              AsyncData(:final value) => Card(
                clipBehavior: Clip.antiAlias,
                child: Column(
                  children: [
                    for (final course in value) ...[
                      _tile(course),
                      if (course != value.last) const Divider(height: 1),
                    ],
                  ],
                ),
              ),
              AsyncError(:final error) => ErrorView(
                message: googleErrorMessage(error),
                onRetry: () => ref.invalidate(googleCoursesProvider),
              ),
              _ => const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: CircularProgressIndicator()),
              ),
            },
          ],
        ),
      ),
    );
  }
}
