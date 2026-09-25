import 'package:dio/dio.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/push/push_routes.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_providers.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/assignment_google_section.dart';
import 'package:eduvision/features/google_classroom/classroom_google_section.dart';
import 'package:eduvision/features/google_classroom/course_picker_screen.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_classroom/google_config.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:eduvision/features/settings/settings_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';
import 'google_fakes.dart';

const _course = ClassroomGoogleLink(courseId: '6210', courseName: 'คณิต ป.5/1');

Classroom _classroom({ClassroomGoogleLink? link}) => Classroom(
  id: 7,
  name: 'ป.5/1',
  gradeLevel: 5,
  academicYear: 2569,
  classCode: 'ABC123',
  googleLink: link,
);

class _Classrooms extends Fake implements ClassroomsRepository {
  _Classrooms({this.link});

  final ClassroomGoogleLink? link;

  @override
  Future<List<Classroom>> list() async => [_classroom(link: link)];
}

class _Assignments extends Fake implements AssignmentsRepository {
  _Assignments(this.assignment);

  final Assignment assignment;

  @override
  Future<Assignment> get(int id) async => assignment;
}

Future<void> _pumpSettings(
  WidgetTester tester, {
  required bool enabled,
  FakeGoogleRepository? google,
  FakeGoogleAuth? auth,
}) async {
  tester.view.physicalSize = const Size(1000, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await pumpScreen(
    tester,
    const SettingsScreen(),
    overrides: [
      aiKeyRepositoryProvider.overrideWithValue(
        FakeAiKeyRepository(const AiKeyStatus(configured: false)),
      ),
      googleClassroomEnabledProvider.overrideWithValue(enabled),
      googleClassroomRepositoryProvider.overrideWithValue(
        google ?? FakeGoogleRepository(),
      ),
      googleAuthProvider.overrideWithValue(auth ?? FakeGoogleAuth()),
    ],
  );
}

Finder _inCard(Finder matching) => find.descendant(
  of: find.byKey(const ValueKey('google_classroom_card')),
  matching: matching,
);

void main() {
  group('settings card (DESIGN §18.7)', () {
    testWidgets('hidden without GOOGLE_SERVER_CLIENT_ID', (tester) async {
      await _pumpSettings(tester, enabled: false);
      expect(find.byKey(const ValueKey('google_classroom_card')), findsNothing);
      expect(find.text('Gemini API key'), findsOneWidget);
    });

    testWidgets('connect: account picker -> POST /google/connect', (
      tester,
    ) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      final auth = FakeGoogleAuth();
      await _pumpSettings(tester, enabled: true, google: google, auth: auth);
      expect(_inCard(find.text('ยังไม่เชื่อม')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('google_connect')));
      await tester.pumpAndSettle();

      expect(auth.serverCodes, 1);
      expect(google.connected, ['4/0server-code']);
      expect(_inCard(find.text('เชื่อมแล้ว')), findsOneWidget);
      expect(_inCard(find.text('บัญชี: kru@school.ac.th')), findsOneWidget);
      expect(find.byKey(const ValueKey('google_connect')), findsNothing);
      expect(
        find.textContaining('เชื่อม Google Classroom กับ kru@'),
        findsOneWidget,
      );
    });

    testWidgets('a cancelled picker says nothing', (tester) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      await _pumpSettings(
        tester,
        enabled: true,
        google: google,
        auth: FakeGoogleAuth(error: const GoogleAuthCanceled()),
      );
      await tester.tap(find.byKey(const ValueKey('google_connect')));
      await tester.pumpAndSettle();
      expect(google.connected, isEmpty);
      expect(find.byType(SnackBar), findsNothing);
    });

    testWidgets('a missing scope is explained', (tester) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      await _pumpSettings(tester, enabled: true, google: google);
      google.error = DioException(
        requestOptions: RequestOptions(path: '/google/connect'),
        response: Response(
          requestOptions: RequestOptions(path: '/google/connect'),
          statusCode: 422,
          data: {
            'message': 'x',
            'errors': null,
            'code': 'google_scope_missing',
          },
        ),
      );
      await tester.tap(find.byKey(const ValueKey('google_connect')));
      await tester.pumpAndSettle();
      expect(find.textContaining('ต้องติ๊กอนุญาตทุกสิทธิ์'), findsOneWidget);
    });

    testWidgets('needs reconnect after invalid_grant', (tester) async {
      await _pumpSettings(
        tester,
        enabled: true,
        google: FakeGoogleRepository(
          statusValue: const GoogleStatus(
            connected: true,
            email: 'kru@school.ac.th',
            needsReconnect: true,
          ),
        ),
      );
      expect(_inCard(find.text('ต้องเชื่อมใหม่')), findsOneWidget);
      expect(_inCard(find.textContaining('7 วัน')), findsOneWidget);
      expect(_inCard(find.text('เชื่อมใหม่')), findsOneWidget);
    });

    testWidgets('disconnect revokes on the server and signs out locally', (
      tester,
    ) async {
      final google = FakeGoogleRepository();
      final auth = FakeGoogleAuth();
      await _pumpSettings(tester, enabled: true, google: google, auth: auth);

      await tester.tap(_inCard(find.text('ยกเลิกการเชื่อม')));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'ยกเลิกการเชื่อม'));
      await tester.pumpAndSettle();

      expect(google.disconnects, 1);
      expect(auth.signOuts, 1);
      expect(_inCard(find.text('ยังไม่เชื่อม')), findsOneWidget);
    });
  });

  group('classroom card', () {
    Future<void> pumpSection(
      WidgetTester tester, {
      required bool enabled,
      ClassroomGoogleLink? link,
      FakeGoogleRepository? google,
    }) async {
      final router = GoRouter(
        routes: [
          GoRoute(
            path: '/',
            builder: (_, _) => Scaffold(
              body: ClassroomGoogleSection(classroom: _classroom(link: link)),
            ),
          ),
          GoRoute(
            path: '/classrooms/:id/google-link',
            builder: (_, _) => const Text('course-picker'),
          ),
          GoRoute(
            path: '/classrooms/:id/google-roster',
            builder: (_, _) => const Text('roster-screen'),
          ),
          GoRoute(path: '/settings', builder: (_, _) => const Text('settings')),
        ],
      );
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            googleClassroomEnabledProvider.overrideWithValue(enabled),
            googleClassroomRepositoryProvider.overrideWithValue(
              google ?? FakeGoogleRepository(),
            ),
            classroomsRepositoryProvider.overrideWithValue(
              _Classrooms(link: link),
            ),
          ],
          child: MaterialApp.router(routerConfig: router),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('hidden without a client id', (tester) async {
      await pumpSection(tester, enabled: false);
      expect(find.text('Google Classroom'), findsNothing);
    });

    testWidgets('not linked: explains the grade limit and opens the picker', (
      tester,
    ) async {
      await pumpSection(tester, enabled: true);
      expect(find.text(gradeReturnNote), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('google_link_course')));
      await tester.pumpAndSettle();
      expect(find.text('course-picker'), findsOneWidget);
    });

    testWidgets('not connected: sends the teacher to settings', (tester) async {
      await pumpSection(
        tester,
        enabled: true,
        google: FakeGoogleRepository(statusValue: GoogleStatus.disconnected),
      );
      expect(find.byKey(const ValueKey('google_link_course')), findsNothing);
      await tester.tap(find.text('ไปที่ตั้งค่า'));
      await tester.pumpAndSettle();
      expect(find.text('settings'), findsOneWidget);
    });

    testWidgets('linked: course name and student matching', (tester) async {
      await pumpSection(tester, enabled: true, link: _course);
      expect(find.text('ผูกกับคอร์ส: คณิต ป.5/1'), findsOneWidget);
      await tester.tap(find.text('จับคู่นักเรียน'));
      await tester.pumpAndSettle();
      expect(find.text('roster-screen'), findsOneWidget);
    });
  });

  testWidgets('course picker links the course and moves on to matching', (
    tester,
  ) async {
    final google = FakeGoogleRepository(
      courseRows: const [
        GoogleCourse(courseId: '6210', name: 'คณิต ป.5/1', section: 'เทอม 1'),
        GoogleCourse(courseId: '6211', name: 'คณิต ป.5/2'),
      ],
    );
    await pumpScreen(
      tester,
      const GoogleCoursePickerScreen(classroomId: 7),
      overrides: [
        googleClassroomEnabledProvider.overrideWithValue(true),
        googleClassroomRepositoryProvider.overrideWithValue(google),
        classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
      ],
      extraRoutes: [
        GoRoute(
          path: '/classrooms/:id/google-roster',
          builder: (_, _) => const Text('roster-screen'),
        ),
      ],
    );
    expect(find.text('เทอม 1'), findsOneWidget);
    await tester.tap(find.text('คณิต ป.5/2'));
    await tester.pumpAndSettle();
    expect(google.linked, [(7, '6211')]);
    expect(find.text('roster-screen'), findsOneWidget);
  });

  group('assignment card', () {
    Assignment assignment({
      String status = 'ready',
      AssignmentGoogleLink? link,
    }) => Assignment(
      id: 12,
      classroomId: 7,
      subjectId: 1,
      title: 'เศษส่วน',
      status: status,
      currentLayoutVersion: 1,
      googleLink: link,
    );

    Future<FakeGoogleRepository> pumpSection(
      WidgetTester tester,
      Assignment a, {
      ClassroomGoogleLink? course = _course,
    }) async {
      tester.view.physicalSize = const Size(1000, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final google = FakeGoogleRepository();
      final router = GoRouter(
        routes: [
          GoRoute(
            path: '/',
            builder: (_, _) => Scaffold(
              body: Consumer(
                builder: (context, ref, _) => AssignmentGoogleSection(
                  // The live detail, so setGoogleLink shows up.
                  assignment:
                      ref.watch(assignmentDetailProvider(a.id)).value ?? a,
                  classroom: _classroom(link: course),
                ),
              ),
            ),
          ),
          GoRoute(
            path: '/assignments/:id/google-submissions',
            builder: (_, _) => const Text('submissions-screen'),
          ),
        ],
      );
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            googleClassroomEnabledProvider.overrideWithValue(true),
            googleClassroomRepositoryProvider.overrideWithValue(google),
            assignmentsRepositoryProvider.overrideWithValue(_Assignments(a)),
          ],
          child: MaterialApp.router(routerConfig: router),
        ),
      );
      await tester.pumpAndSettle();
      return google;
    }

    testWidgets('post with the spare worksheet, then the link shows', (
      tester,
    ) async {
      final google = await pumpSection(tester, assignment());
      expect(find.text(gradeReturnNote), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('google_post')));
      await tester.pumpAndSettle();
      final checkbox = tester.widget<CheckboxListTile>(
        find.byKey(const ValueKey('google_attach_blank')),
      );
      expect(checkbox.value, isTrue);
      await tester.tap(find.byKey(const ValueKey('google_post_confirm')));
      await tester.pumpAndSettle();

      expect(google.posts.single, (12, true, null));
      expect(find.text('โพสต์แล้ว'), findsWidgets);
      expect(
        find.byKey(const ValueKey('google_alternate_link')),
        findsOneWidget,
      );
      expect(find.textContaining('แนบใบงานสำรองแล้ว'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('google_fetch_submissions')));
      await tester.pumpAndSettle();
      expect(find.text('submissions-screen'), findsOneWidget);
    });

    testWidgets('a draft cannot be posted', (tester) async {
      await pumpSection(tester, assignment(status: 'draft'));
      final button = tester.widget<FilledButton>(
        find.byKey(const ValueKey('google_post')),
      );
      expect(button.onPressed, isNull);
      expect(find.textContaining('สถานะ "พร้อมใช้"'), findsOneWidget);
    });

    testWidgets('an unlinked room points to the classroom first', (
      tester,
    ) async {
      await pumpSection(tester, assignment(), course: null);
      expect(find.byKey(const ValueKey('google_post')), findsNothing);
      expect(find.text('ไปหน้าห้องเรียน'), findsOneWidget);
    });
  });

  test('the retake push opens the student results tab', () {
    const student = User(id: 9, name: 'สมหญิง', role: 'student');
    expect(
      routeForPush({
        'type': 'retake_requested',
        'assignment_id': '12',
      }, student),
      '/student',
    );
  });
}
