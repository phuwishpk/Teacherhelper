import 'package:drift/drift.dart' show DatabaseConnection;
import 'package:drift/native.dart';
import 'package:eduvision/app.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/core/db/database_provider.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/core/util/thai_date.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/upload_queue/upload_worker.dart';
import 'package:eduvision/ml/ml_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/fake_api_server.dart';
import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';

/// The whole app booted the way `main.dart` boots it, with only the seams
/// that leave the process replaced: the network (a [FakeApiServer] behind
/// the real `createDio` interceptor), secure storage (in memory), drift (in
/// memory), WorkManager (no-op) and TFLite (off, as on any non-Android
/// host). Shared by `test/flows` (runs on the host, counts for coverage)
/// and `integration_test/` (runs on a device).
class TeacherFlowApp {
  TeacherFlowApp._(this.server, this.storage, this.container);

  final FakeApiServer server;
  final InMemoryTokenStorage storage;
  final ProviderContainer container;

  GoRouter get router => container.read(routerProvider);

  /// The screen on top, including pages pushed imperatively (a push does
  /// not change `currentConfiguration.uri`, only its last match).
  String get location =>
      router.routerDelegate.currentConfiguration.last.matchedLocation;

  static Future<TeacherFlowApp> start(
    WidgetTester tester, {
    FakeApiServer? server,
    String? storedToken,
  }) async {
    final fake = server ?? FakeApiServer();
    final storage = InMemoryTokenStorage(token: storedToken);
    final db = AppDatabase(
      DatabaseConnection(
        NativeDatabase.memory(),
        closeStreamsSynchronously: true,
      ),
    );
    final container = ProviderContainer(
      overrides: [
        tokenStorageProvider.overrideWithValue(storage),
        dioProvider.overrideWith((ref) {
          final dio = createDio(
            tokenStorage: ref.watch(tokenStorageProvider),
            onUnauthorized: () =>
                ref.read(sessionProvider.notifier).forceSignOut(),
            baseUrl: FakeApiServer.origin,
          );
          dio.httpClientAdapter = fake.adapter;
          return dio;
        }),
        appDatabaseProvider.overrideWithValue(db),
        uploadSchedulerProvider.overrideWithValue(const NoopUploadScheduler()),
        digitModelRunnerFactoryProvider.overrideWithValue(null),
        cropLoaderProvider.overrideWithValue(NoCropLoader()),
      ],
    );
    // Tear-downs run last-registered first: dispose providers (and their
    // drift streams) before the database closes.
    addTearDown(db.close);
    addTearDown(container.dispose);

    // A tap whose target is covered (e.g. a FAB under a SnackBar) or off
    // screen fails the flow instead of only printing a warning.
    final fatalBefore = WidgetController.hitTestWarningShouldBeFatal;
    WidgetController.hitTestWarningShouldBeFatal = true;
    addTearDown(
      () => WidgetController.hitTestWarningShouldBeFatal = fatalBefore,
    );

    // A phone: bottom navigation bar, single-pane review queue.
    tester.view.physicalSize = const Size(480, 1280);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: const EduVisionApp(),
      ),
    );
    await tester.pumpAndSettle();
    return TeacherFlowApp._(fake, storage, container);
  }

  /// Takes the app down inside the test body (see [unmountScreen]).
  Future<void> stop(WidgetTester tester) => unmountScreen(tester);

  RecordedRequest lastRequest(String method, String path) =>
      server.requests.lastWhere((r) => r.method == method && r.path == path);
}

Finder _navDestination(String label) =>
    find.descendant(of: find.byType(NavigationBar), matching: find.text(label));

Future<void> _tapVisible(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder);
  await tester.pumpAndSettle();
  await tester.tap(finder);
  await tester.pumpAndSettle();
}

/// The success SnackBar is on screen exactly once: the shell tabs have no
/// Scaffold of their own, so the root messenger has one Scaffold to show it
/// on, and the next tap (a FAB the SnackBar lifts, a tab of the bottom bar)
/// goes ahead while it is still visible.
void _expectMessageOnce(String text) {
  expect(find.textContaining(text), findsOneWidget);
  expect(find.byType(SnackBar), findsOneWidget);
}

/// Splash -> login -> `POST /auth/teacher/login` -> `GET /me` -> teacher home.
Future<void> signInAsTeacher(WidgetTester tester, TeacherFlowApp app) async {
  expect(app.location, AppRoutes.login);
  expect(find.text('เข้าสู่ระบบสำหรับครู'), findsOneWidget);

  await tester.enterText(
    find.widgetWithText(TextFormField, 'อีเมล'),
    app.server.teacherEmail,
  );
  await tester.enterText(
    find.widgetWithText(TextFormField, 'รหัสผ่าน'),
    app.server.teacherPassword,
  );
  await tester.tap(find.widgetWithText(FilledButton, 'เข้าสู่ระบบ'));
  await tester.pumpAndSettle();

  expect(app.location, AppRoutes.home);
  expect(find.text('สวัสดี คุณครู ครูสมศรี'), findsOneWidget);
  expect(app.storage.token, app.server.teacherToken);
  expect(app.storage.user?['role'], 'teacher');

  final login = app.lastRequest('POST', '/auth/teacher/login');
  expect(login.authorization, isNull);
  expect(login.jsonBody, {
    'email': app.server.teacherEmail,
    'password': app.server.teacherPassword,
  });
  expect(app.lastRequest('GET', '/me').status, 200);
}

/// "ห้องเรียน" tab -> FAB -> form -> `POST /classrooms` -> back to the list.
Future<int> createClassroom(
  WidgetTester tester,
  TeacherFlowApp app, {
  String name = 'ป.5/2',
  int gradeLevel = 5,
}) async {
  await tester.tap(_navDestination('ห้องเรียน'));
  await tester.pumpAndSettle();
  expect(find.text('ยังไม่มีห้องเรียน'), findsOneWidget);

  await tester.tap(find.widgetWithText(FloatingActionButton, 'สร้างห้องเรียน'));
  await tester.pumpAndSettle();
  expect(app.location, AppRoutes.classroomNew);

  await tester.enterText(find.widgetWithText(TextFormField, 'ชื่อห้อง'), name);
  await tester.tap(
    find.widgetWithText(DropdownButtonFormField<int>, 'ระดับชั้น'),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text(gradeLevelLabel(gradeLevel)).last);
  await tester.pumpAndSettle();
  await _tapVisible(
    tester,
    find.widgetWithText(FilledButton, 'สร้างห้องเรียน'),
  );

  expect(app.location, AppRoutes.home);
  _expectMessageOnce('สร้างห้องเรียนแล้ว');
  expect(find.text(name), findsOneWidget);
  final created = app.lastRequest('POST', '/classrooms');
  expect(created.status, 201);
  expect(created.jsonBody, {
    'name': name,
    'grade_level': gradeLevel,
    'academic_year': currentThaiYear(),
  });
  return app.server.classrooms.single['id'] as int;
}

/// "การบ้าน" tab -> FAB -> form -> "สร้างรายวิชา" (`POST /courses`, back
/// with the course picked) -> `POST /assignments` -> assignment detail.
Future<int> createAssignment(
  WidgetTester tester,
  TeacherFlowApp app, {
  required int classroomId,
  String title = 'เศษส่วน ชุดที่ 1',
  String courseCode = 'ค15101',
}) async {
  final classroom = app.server.classrooms.singleWhere(
    (c) => c['id'] == classroomId,
  );
  await tester.tap(_navDestination('การบ้าน'));
  await tester.pumpAndSettle();
  expect(find.text('ยังไม่มีการบ้าน'), findsOneWidget);

  await tester.tap(find.widgetWithText(FloatingActionButton, 'สร้างการบ้าน'));
  await tester.pumpAndSettle();
  expect(app.location, AppRoutes.assignmentNew);

  await tester.enterText(
    find.widgetWithText(TextFormField, 'ชื่อการบ้าน'),
    title,
  );
  await tester.tap(
    find.widgetWithText(DropdownButtonFormField<int>, 'ห้องเรียน'),
  );
  await tester.pumpAndSettle();
  await tester.tap(
    find
        .text(
          '${classroom['name']} '
          '(${gradeLevelLabel(classroom['grade_level'] as int)})',
        )
        .last,
  );
  await tester.pumpAndSettle();
  // A new assignment needs a course of the classroom (DESIGN §20.1): the
  // room has none yet, so the teacher creates one from the form.
  expect(
    find.text('ห้องนี้ยังไม่มีรายวิชา สร้างรายวิชาและผูกกับห้องนี้ก่อน'),
    findsOneWidget,
  );
  await _tapVisible(
    tester,
    find.byKey(const ValueKey('course_create_for_classroom')),
  );
  expect(app.location, AppRoutes.courseNew);
  await tester.enterText(find.byKey(const ValueKey('course_code')), courseCode);
  await tester.enterText(
    find.byKey(const ValueKey('course_name')),
    'คณิตศาสตร์ 5',
  );
  await tester.tap(find.byKey(const ValueKey('course_subject')));
  await tester.pumpAndSettle();
  await tester.tap(find.text('คณิตศาสตร์').last);
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('course_grade')));
  await tester.pumpAndSettle();
  await tester.tap(
    find.text(gradeLevelLabel(classroom['grade_level'] as int)).last,
  );
  await tester.pumpAndSettle();
  final roomChip = find.byKey(ValueKey('course_classroom_$classroomId'));
  await tester.ensureVisible(roomChip);
  expect(tester.widget<FilterChip>(roomChip).selected, isTrue);
  await _tapVisible(tester, find.byKey(const ValueKey('course_save')));

  expect(app.location, AppRoutes.assignmentNew);
  final course = app.lastRequest('POST', '/courses');
  expect(course.status, 201);
  expect(course.jsonBody, {
    'code': courseCode,
    'name': 'คณิตศาสตร์ 5',
    'subject_id': 1,
    'grade_level': classroom['grade_level'],
    'semester': 1,
    'academic_year': currentThaiYear(),
    'hours': null,
    'description': null,
    'classroom_ids': [classroomId],
    'skill_ids': <int>[],
  });
  final courseId = app.server.courses.single['id'] as int;
  expect(find.text('$courseCode คณิตศาสตร์ 5'), findsOneWidget);
  await _tapVisible(tester, find.widgetWithText(FilledButton, 'สร้างการบ้าน'));

  final id = app.server.assignments.single['id'] as int;
  expect(app.location, AppRoutes.assignment(id));
  _expectMessageOnce('สร้างการบ้านแล้ว เพิ่มคำถามได้เลย');
  expect(find.text('เพิ่มคำถามก่อน จึงจะสร้าง layout ได้'), findsOneWidget);
  final created = app.lastRequest('POST', '/assignments');
  expect(created.status, 201);
  expect(created.jsonBody, {
    'classroom_id': classroomId,
    'course_id': courseId,
    'title': title,
    'strictness': 'normal',
    'due_at': null,
    'mode': 'worksheet',
    'accept_late': true,
    'score_only': false,
  });
  return id;
}

/// FAB "เพิ่มคำถาม" -> a short-answer question -> `POST .../questions`.
Future<void> addShortQuestion(
  WidgetTester tester,
  TeacherFlowApp app,
  int assignmentId, {
  String prompt = '100 + 25 = ?',
  String answer = '125',
}) async {
  await tester.tap(find.widgetWithText(FloatingActionButton, 'เพิ่มคำถาม'));
  await tester.pumpAndSettle();
  expect(app.location, AppRoutes.questionNew(assignmentId));

  await tester.tap(find.text(QuestionType.short.label));
  await tester.pumpAndSettle();
  await tester.enterText(find.widgetWithText(TextFormField, 'โจทย์'), prompt);
  final accepted = find.widgetWithText(
    TextFormField,
    'คำตอบที่ยอมรับ (บรรทัดละคำตอบ)',
  );
  await tester.ensureVisible(accepted);
  await tester.enterText(accepted, answer);
  await _tapVisible(tester, find.widgetWithText(FilledButton, 'เพิ่มข้อ'));

  expect(app.location, AppRoutes.assignment(assignmentId));
  _expectMessageOnce('บันทึกข้อแล้ว');
  expect(find.text(prompt), findsOneWidget);
  final posted = app.lastRequest(
    'POST',
    '/assignments/$assignmentId/questions',
  );
  expect(posted.status, 201);
  expect(posted.jsonBody['type'], 'short');
  expect(posted.jsonBody['prompt_text'], prompt);
  expect(posted.jsonBody['answer_key'], containsPair('accepted', [answer]));
}

/// "สร้าง layout" -> `POST .../layout` -> the assignment is `ready` and the
/// review entry appears.
Future<void> createLayout(
  WidgetTester tester,
  TeacherFlowApp app,
  int assignmentId,
) async {
  expect(find.text('พร้อมสร้าง layout แล้ว'), findsOneWidget);
  await _tapVisible(tester, find.text('สร้าง layout'));

  expect(
    app.lastRequest('POST', '/assignments/$assignmentId/layout').status,
    201,
  );
  expect(app.server.assignments.single['status'], 'ready');
  _expectMessageOnce('สร้าง layout เวอร์ชัน 1 แล้ว');
  expect(find.text('ตรวจทานและเผยแพร่'), findsOneWidget);
}

/// "ตรวจทานและเผยแพร่" -> `GET .../review-queue` -> the queue with its tabs.
Future<void> openReviewQueue(
  WidgetTester tester,
  TeacherFlowApp app,
  int assignmentId, {
  required String title,
}) async {
  await _tapVisible(tester, find.text('ตรวจทานและเผยแพร่'));

  expect(app.location, AppRoutes.review(assignmentId));
  expect(find.text('ตรวจทาน: $title'), findsOneWidget);
  expect(find.text('ต้องตรวจ (1)'), findsOneWidget);
  expect(find.text('มั่นใจ (1)'), findsOneWidget);
  expect(find.textContaining('สมหญิง'), findsWidgets);
  final queue = app.lastRequest(
    'GET',
    '/assignments/$assignmentId/review-queue',
  );
  expect(queue.status, 200);
  expect(queue.authorization, 'Bearer ${app.server.teacherToken}');
}

/// Back to the shell, then "ออกจากระบบ" -> `POST /auth/logout` -> login.
Future<void> signOutFromShell(WidgetTester tester, TeacherFlowApp app) async {
  while (app.location != AppRoutes.home) {
    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
  }
  await tester.tap(find.byTooltip('ออกจากระบบ'));
  await tester.pumpAndSettle();

  expect(app.location, AppRoutes.login);
  expect(app.storage.token, isNull);
  expect(app.storage.user, isNull);
  expect(app.server.logouts, 1);
}

/// Every protected call carried the bearer token, nothing was answered 401
/// and nothing reached a route the fake (and so the API) does not have.
void expectApiContractHeld(TeacherFlowApp app) {
  for (final r in app.server.requests) {
    if (r.path == '/auth/teacher/login') continue;
    expect(
      r.authorization,
      'Bearer ${app.server.teacherToken}',
      reason: '${r.method} ${r.path} was sent without the session token',
    );
    expect(r.status, isNot(401), reason: '${r.method} ${r.path}');
  }
  expect(app.server.unrouted, isEmpty);
}

/// The teacher loop of DESIGN §4 up to the review queue.
Future<void> runTeacherFlow(WidgetTester tester) async {
  final app = await TeacherFlowApp.start(tester);
  await signInAsTeacher(tester, app);
  final classroomId = await createClassroom(tester, app);
  final assignmentId = await createAssignment(
    tester,
    app,
    classroomId: classroomId,
  );
  // Scans and grading happen elsewhere; the queue just has to be there.
  app.server.reviewRows[assignmentId] = [
    queueRow(id: 11),
    queueRow(id: 12, position: 2, band: 'confident', studentNumber: 7),
  ];
  await addShortQuestion(tester, app, assignmentId);
  await createLayout(tester, app, assignmentId);
  await openReviewQueue(tester, app, assignmentId, title: 'เศษส่วน ชุดที่ 1');
  await signOutFromShell(tester, app);
  expectApiContractHeld(app);
  await app.stop(tester);
}
