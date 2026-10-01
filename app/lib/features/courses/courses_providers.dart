import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import 'course_models.dart';
import 'courses_repository.dart';

/// The teacher's courses (`GET /courses`), cached for the session.
final coursesProvider = FutureProvider.autoDispose<List<Course>>((ref) {
  watchSignedInUser(ref);
  return ref.watch(coursesRepositoryProvider).list();
});

/// Courses bound to one classroom (`GET /courses?classroom_id=`): the
/// choices of an assignment in that classroom (DESIGN §20.1).
final classroomCoursesProvider = FutureProvider.autoDispose
    .family<List<Course>, int>((ref, classroomId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(coursesRepositoryProvider)
          .list(classroomId: classroomId);
    });

/// The courses taught in a classroom with their teachers (`GET
/// /classrooms/{id}/courses`, DESIGN §24.12 B): every course of the room
/// for the homeroom teacher, the teacher's own for a subject teacher.
final classroomTeachingProvider = FutureProvider.autoDispose
    .family<List<ClassroomCourse>, int>((ref, classroomId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(coursesRepositoryProvider).taughtIn(classroomId);
    });

/// One course with its indicators, units and lesson plans.
final courseDetailProvider = FutureProvider.autoDispose.family<Course, int>((
  ref,
  id,
) {
  watchSignedInUser(ref, keepAlive: false);
  return ref.watch(coursesRepositoryProvider).get(id);
});

/// How often a queued document read is polled. The queue worker runs once
/// a minute on the host (CLAUDE.md); tests shorten it.
final courseExtractionPollIntervalProvider = Provider<Duration>(
  (ref) => const Duration(seconds: 5),
);

/// The guidance ("คำแนะนำถึง AI", DESIGN §21.12) the server echoed for the
/// last course or lesson-plan read of each purpose, so reading a document
/// again starts from it. Forgotten when another user signs in.
class CourseGuidanceMemory
    extends Notifier<Map<CourseDocumentPurpose, String>> {
  @override
  Map<CourseDocumentPurpose, String> build() {
    ref.watch(sessionProvider.select((s) => s is SignedIn ? s.user.id : null));
    return const {};
  }

  void remember(CourseDocumentPurpose purpose, String? guidance) {
    final next = {...state};
    if (guidance == null) {
      next.remove(purpose);
    } else {
      next[purpose] = guidance;
    }
    state = next;
  }
}

final courseGuidanceMemoryProvider =
    NotifierProvider<CourseGuidanceMemory, Map<CourseDocumentPurpose, String>>(
      CourseGuidanceMemory.new,
    );

/// After any change to courses: lists and details are loaded again.
void invalidateCourses(WidgetRef ref) {
  ref.invalidate(coursesProvider);
  ref.invalidate(classroomCoursesProvider);
  ref.invalidate(classroomTeachingProvider);
  ref.invalidate(courseDetailProvider);
}
