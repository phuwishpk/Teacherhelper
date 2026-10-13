import 'package:eduvision/core/router/app_router.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §29.13.3: the join link is public (a signed-out pupil reads the
/// page first) and part of the student area once signed in.
void main() {
  test('join links are public and part of the student area', () {
    expect(AppRoutes.join('ABC234'), '/join/ABC234');
    expect(AppRoutes.isJoinLink('/join/ABC234'), isTrue);
    expect(AppRoutes.isJoinLink(AppRoutes.studentJoin), isFalse);
    expect(AppRoutes.isPublic('/join/ABC234'), isTrue);
    expect(AppRoutes.isStudentArea('/join/ABC234'), isTrue);
    expect(AppRoutes.isStudentArea(AppRoutes.studentJoin), isTrue);
    expect(AppRoutes.isPublic(AppRoutes.studentJoin), isFalse);
  });
}
