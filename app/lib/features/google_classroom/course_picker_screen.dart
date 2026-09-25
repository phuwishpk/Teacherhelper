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

/// Picks the Google Classroom course for a classroom (`GET /google/courses`
/// then `POST /classrooms/{id}/google-link`), then goes on to the student
/// matching screen (DESIGN §18.7).
class GoogleCoursePickerScreen extends ConsumerStatefulWidget {
  const GoogleCoursePickerScreen({super.key, required this.classroomId});

  final int classroomId;

  @override
  ConsumerState<GoogleCoursePickerScreen> createState() =>
      _GoogleCoursePickerScreenState();
}

class _GoogleCoursePickerScreenState
    extends ConsumerState<GoogleCoursePickerScreen> {
  String? _linking;

  Future<void> _link(GoogleCourse course) async {
    setState(() => _linking = course.courseId);
    try {
      final link = await ref
          .read(googleClassroomRepositoryProvider)
          .link(widget.classroomId, course);
      ref
          .read(classroomsProvider.notifier)
          .setGoogleLink(widget.classroomId, link);
      if (!mounted) return;
      showMessage(
        context,
        'ผูกกับ ${course.name} แล้ว จับคู่นักเรียนต่อได้เลย',
      );
      context.pushReplacement(
        AppRoutes.classroomGoogleRoster(widget.classroomId),
      );
    } catch (e) {
      if (isGoogleReconnectError(e)) {
        ref.read(googleStatusProvider.notifier).markNeedsReconnect();
      }
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _linking = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final courses = ref.watch(googleCoursesProvider);
    final classroom = ref.watch(classroomProvider(widget.classroomId)).value;
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('เลือกคอร์สใน Google Classroom')),
      body: ContentColumn(
        child: ListView(
          children: [
            Card(
              color: theme.colorScheme.secondaryContainer,
              child: ListTile(
                leading: const Icon(Icons.info_outline),
                title: Text(
                  classroom == null
                      ? 'เลือกคอร์สที่จะผูกกับห้องนี้'
                      : 'เลือกคอร์สที่จะผูกกับห้อง ${classroom.name}',
                ),
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
                      ListTile(
                        leading: const Icon(Icons.class_outlined),
                        title: Text(course.name),
                        subtitle: course.section == null
                            ? null
                            : Text(course.section!),
                        trailing: _linking == course.courseId
                            ? const SizedBox.square(
                                dimension: 20,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : const Icon(Icons.chevron_right),
                        enabled: _linking == null,
                        onTap: () => _link(course),
                      ),
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
