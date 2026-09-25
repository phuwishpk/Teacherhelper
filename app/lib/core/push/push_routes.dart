import '../auth/user.dart';
import '../router/app_router.dart';

/// Where a tapped notification leads (DESIGN §9.9). The server sends a
/// `data` map with `type` and the ids as strings:
///
/// | type                | who     | data                | opens                |
/// |---------------------|---------|---------------------|----------------------|
/// | `grading_done`      | teacher | `assignment_id`     | review queue         |
/// | `appeal_opened`     | teacher | -                   | appeals list         |
/// | `results_published` | student | `submission_id`     | result detail        |
/// | `appeal_resolved`   | student | `submission_id`     | result detail        |
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
      _ => null,
    };
  }
  if (user.isStudent) {
    return switch (type) {
      'results_published' || 'appeal_resolved'
          when id('submission_id') != null =>
        AppRoutes.studentResult(id('submission_id')!),
      'results_published' || 'appeal_resolved' => AppRoutes.student,
      _ => null,
    };
  }
  return null;
}
