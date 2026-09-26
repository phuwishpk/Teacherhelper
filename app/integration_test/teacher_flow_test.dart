import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

import '../test/flows/teacher_flow.dart';

/// On-device run of the teacher loop (DESIGN §4, §16.1) against the
/// in-process fake API, so it needs no server and no network:
///
///   `flutter test integration_test/teacher_flow_test.dart -d <device>`
///
/// The host run of the same flow is `test/flows/teacher_flow_test.dart`.
void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets(
    'teacher: sign in -> classroom -> assignment -> question -> layout -> '
    'review queue -> sign out',
    runTeacherFlow,
  );
}
