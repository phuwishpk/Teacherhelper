import '../auth/user.dart';
import '../router/app_router.dart';

/// Where a tapped notification leads (DESIGN §9.9). The server sends a
/// `data` map with `type` and the ids as strings:
///
/// | type                | who     | data                | opens                |
/// |---------------------|---------|---------------------|----------------------|
/// | `grading_done`      | teacher | `assignment_id`     | review queue         |
/// | `appeal_opened`     | teacher | -                   | appeals list         |
/// | `classroom_work_imported` | teacher | `assignment_id` | answer key (§19.3) |
/// | `google_reconnect`  | teacher | -                   | settings (reconnect) |
/// | `course_request`    | teacher | `request_id`, `classroom_id` | requests to decide (§24.7) |
/// | `course_request_decided` | teacher | `request_id`, `classroom_id`, `course_id` | own requests |
/// | `results_published` | student | `submission_id`     | result detail        |
/// | `appeal_resolved`   | student | `submission_id`     | result detail        |
/// | `retake_requested`  | student | `assignment_id`     | results tab (notice) |
/// | `grades_published`  | student | `course_id`, `classroom_id` | that classroom's course grade |
///
/// Only these types are routed; anything else just opens the app. Returns
/// null when the message is not for this user's role.
String? routeForPush(Map<String, String> data, User user) {
  int? id(String key) => int.tryParse(data[key] ?? '');
  final type = data['type'];
  if (user.isTeacher) {
    return switch (type) {
      'grading_done' when id('assignment_id') != null => AppRoutes.review(
        id('assignment_id')!,
      ),
      'appeal_opened' => AppRoutes.appeals,
      // "มีงานใหม่จาก Classroom รออนุมัติเฉลย": the AI-drafted key to check.
      'classroom_work_imported' when id('assignment_id') != null =>
        AppRoutes.answerKey(id('assignment_id')!),
      'google_reconnect' => AppRoutes.settings,
      // Shared homerooms (DESIGN §24.7, §24.20).
      'course_request' => AppRoutes.courseRequests,
      'course_request_decided' => AppRoutes.courseRequestsOutgoing,
      _ => null,
    };
  }
  if (user.isStudent) {
    return switch (type) {
      'results_published' || 'appeal_resolved'
          when id('submission_id') != null =>
        AppRoutes.studentResult(id('submission_id')!),
      'results_published' || 'appeal_resolved' => AppRoutes.student,
      // Google Classroom "ตีกลับให้ถ่ายใหม่" (§18.2): the reason is shown
      // at the top of the results tab.
      'retake_requested' => AppRoutes.student,
      // The course grade was published (§23.7, §23.11).
      // classroom_id picks that classroom's publication when the course is
      // taught in two of the student's classrooms (DESIGN §24.28).
      'grades_published' when id('course_id') != null =>
        AppRoutes.myCourseGrade(
          id('course_id')!,
          classroomId: id('classroom_id'),
        ),
      'grades_published' => AppRoutes.student,
      _ => null,
    };
  }
  return null;
}
