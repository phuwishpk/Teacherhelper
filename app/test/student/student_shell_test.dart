import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/core/live/live_token_api.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/analysis/analysis_repository.dart';
import 'package:eduvision/features/attendance/attendance_models.dart';
import 'package:eduvision/features/attendance/attendance_repository.dart';
import 'package:eduvision/features/mastery/course_mastery.dart';
import 'package:eduvision/features/mastery/mastery_models.dart';
import 'package:eduvision/features/mastery/mastery_repository.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:eduvision/features/student/student_shell.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../class_posts/class_post_fakes.dart';
import '../hand_in/hand_in_fakes.dart';
import '../helpers/pump_screen.dart';
import 'student_fixtures.dart';

/// The student's home is "วิชาของฉัน" (DESIGN §24.13); grades moved under
/// it, so the shell keeps five tabs.
void main() {
  _bellTests();
  _liveRefreshTests();
  testWidgets('opens on "วิชาของฉัน" and switches tabs', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.7;
    addTearDown(tester.view.reset);
    await pumpScreen(
      tester,
      const StudentShell(),
      overrides: [
        ...studentViewOverrides(),
        currentUserProvider.overrideWithValue(
          const User(id: 11, name: 'ด.ญ. เอ', role: 'student'),
        ),
        practiceRecommendationsProvider.overrideWith((ref) async => const []),
        myMasteryProvider.overrideWith(
          (ref) async => const MasteryList(rows: []),
        ),
        myAnalysesProvider.overrideWith((ref) async => const []),
        myCoursesProvider.overrideWith((ref) async => const []),
      ],
    );

    final bar = find.byType(NavigationBar);
    for (final label in [
      'วิชาของฉัน',
      'ส่งงาน',
      'ผลการบ้าน',
      'แบบฝึก',
      'ทักษะ',
    ]) {
      expect(
        find.descendant(of: bar, matching: find.text(label)),
        findsOneWidget,
      );
    }
    expect(find.descendant(of: bar, matching: find.text('เกรด')), findsNothing);
    expect(find.text('ต้องส่งอีก 3 งาน'), findsOneWidget);

    await tester.tap(find.descendant(of: bar, matching: find.text('ส่งงาน')));
    await tester.pumpAndSettle();
    expect(find.text('เศษส่วน ชุดที่ 1'), findsOneWidget);
  });
}

/// The bell of the live layer (DESIGN §38 step 2): shown only when Firebase
/// is on in this build, with the unread count as its badge.
class _Live implements LiveSession {
  _Live(this.db);

  final FirebaseFirestore db;

  @override
  bool get enabled => true;
  @override
  FirebaseFirestore? get firestore => db;
  @override
  String? get currentUid => 'u11';
  @override
  Stream<String?> get uidChanges => Stream.value('u11');
  @override
  Future<LiveSignInOutcome> signIn(
    int userId,
    FetchFirebaseToken fetchToken,
  ) async => LiveSignInOutcome.alreadySignedIn;
  @override
  Future<void> signOut() async {}
}

class _FakeAttendance extends Fake implements AttendanceRepository {
  int mineCalls = 0;

  @override
  Future<List<MyAttendanceCourse>> mine() async {
    mineCalls++;
    return const [];
  }
}

void _liveRefreshTests() {
  testWidgets('a hand-in that changes status refetches the lists', (
    tester,
  ) async {
    final db = FakeFirebaseFirestore();
    await db.doc('users/u11/submissions/5').set({'status': 'grading'});
    final handIn = FakeHandIn(assignments: assignmentsFixture());
    await pumpScreen(
      tester,
      const StudentShell(),
      overrides: [
        ...studentViewOverrides(handIn: handIn),
        currentUserProvider.overrideWithValue(
          const User(id: 11, name: 'ด.ญ. เอ', role: 'student'),
        ),
        practiceRecommendationsProvider.overrideWith((ref) async => const []),
        myMasteryProvider.overrideWith(
          (ref) async => const MasteryList(rows: []),
        ),
        myAnalysesProvider.overrideWith((ref) async => const []),
        myCoursesProvider.overrideWith((ref) async => const []),
        liveSessionProvider.overrideWithValue(_Live(db)),
      ],
    );
    await tester.pumpAndSettle();
    final before = handIn.listCalls;

    await db.doc('users/u11/submissions/5').set({'status': 'published'});
    await tester.pumpAndSettle();

    expect(handIn.listCalls, greaterThan(before));
  });

  testWidgets('class_feed signal refetches the student feed', (tester) async {
    final db = FakeFirebaseFirestore();
    final classPosts = FakeClassPosts();
    await pumpScreen(
      tester,
      const StudentShell(),
      overrides: [
        ...studentViewOverrides(classPosts: classPosts),
        currentUserProvider.overrideWithValue(
          const User(id: 11, name: 'ด.ญ. เอ', role: 'student'),
        ),
        practiceRecommendationsProvider.overrideWith((ref) async => const []),
        myMasteryProvider.overrideWith(
          (ref) async => const MasteryList(rows: []),
        ),
        myAnalysesProvider.overrideWith((ref) async => const []),
        myCoursesProvider.overrideWith((ref) async => const []),
        liveSessionProvider.overrideWithValue(_Live(db)),
      ],
    );
    await tester.pumpAndSettle();
    final before = classPosts.feedKeys.length;

    // Write class_feed signal for this user
    await db.doc('users/u11/class_feed/7').set({'classroom_id': 7});
    await tester.pumpAndSettle();

    expect(classPosts.feedKeys.length, greaterThan(before));
  });

  testWidgets('attendance signal refetches the attendance data', (tester) async {
    final db = FakeFirebaseFirestore();
    final attendance = _FakeAttendance();
    await pumpScreen(
      tester,
      const StudentShell(),
      overrides: [
        ...studentViewOverrides(attendance: attendance),
        currentUserProvider.overrideWithValue(
          const User(id: 11, name: 'ด.ญ. เอ', role: 'student'),
        ),
        practiceRecommendationsProvider.overrideWith((ref) async => const []),
        myMasteryProvider.overrideWith(
          (ref) async => const MasteryList(rows: []),
        ),
        myAnalysesProvider.overrideWith((ref) async => const []),
        myCoursesProvider.overrideWith((ref) async => const []),
        liveSessionProvider.overrideWithValue(_Live(db)),
      ],
    );
    await tester.pumpAndSettle();

    final container = ProviderScope.containerOf(
      tester.element(find.byType(StudentShell)),
    );
    final subscription = container.listen(myAttendanceProvider, (_, _) {});
    addTearDown(subscription.close);
    await tester.pumpAndSettle();
    final before = attendance.mineCalls;

    // Write attendance signal for this user
    await db.doc('users/u11/attendance/3_7').set({
      'course_id': 3,
      'classroom_id': 7,
    });
    await tester.pumpAndSettle();

    expect(attendance.mineCalls, greaterThan(before));
  });
}

void _bellTests() {
  List<Override> base() => [
    ...studentViewOverrides(),
    currentUserProvider.overrideWithValue(
      const User(id: 11, name: 'ด.ญ. เอ', role: 'student'),
    ),
    practiceRecommendationsProvider.overrideWith((ref) async => const []),
    myMasteryProvider.overrideWith((ref) async => const MasteryList(rows: [])),
    myAnalysesProvider.overrideWith((ref) async => const []),
    myCoursesProvider.overrideWith((ref) async => const []),
  ];

  testWidgets('no bell without Firebase in this build', (tester) async {
    await pumpScreen(tester, const StudentShell(), overrides: base());
    expect(find.byKey(const ValueKey('notices_button')), findsNothing);
  });

  testWidgets('the bell carries the unread count and opens the inbox', (
    tester,
  ) async {
    final db = FakeFirebaseFirestore();
    for (final (id, read) in [('01a', false), ('01b', false), ('01c', true)]) {
      await db.collection('users/u11/notices').doc(id).set({
        'kind': 'class_post',
        'title': 'Krucheck',
        'body': 'โพสต์ $id',
        'data': {'type': 'class_post'},
        'read': read,
        'created_at': Timestamp.fromDate(DateTime.utc(2026, 10, 21)),
      });
    }
    final opened = <String>[];
    await pumpScreen(
      tester,
      const StudentShell(),
      overrides: [...base(), liveSessionProvider.overrideWithValue(_Live(db))],
      extraRoutes: [
        GoRoute(
          path: AppRoutes.studentNotices,
          builder: (context, state) {
            opened.add(state.uri.path);
            return const Scaffold(body: Text('notices-stub'));
          },
        ),
      ],
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('notices_button')), findsOneWidget);
    expect(find.text('2'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('notices_button')));
    await tester.pumpAndSettle();
    expect(opened, [AppRoutes.studentNotices]);
  });
}
