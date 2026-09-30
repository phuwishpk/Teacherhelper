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

/// After any change to courses: lists and details are loaded again.
void invalidateCourses(WidgetRef ref) {
  ref.invalidate(coursesProvider);
  ref.invalidate(classroomCoursesProvider);
  ref.invalidate(courseDetailProvider);
}
