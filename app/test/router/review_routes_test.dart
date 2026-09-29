import 'package:drift/drift.dart' show DatabaseConnection;
import 'package:drift/native.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/core/db/database_provider.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/hand_in/hand_in_models.dart';
import 'package:eduvision/features/hand_in/hand_in_repository.dart';
import 'package:eduvision/features/results/results_repository.dart';
import 'package:eduvision/features/results/student_result.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:eduvision/features/upload_queue/upload_worker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../hand_in/hand_in_fakes.dart';
import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';

class _FakeAuth extends Fake implements AuthRepository {
  _FakeAuth(this.user);

  final User user;

  @override
  Future<User> me() async => user;
}

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [];
}

class _FakeAssignments extends Fake implements AssignmentsRepository {
  @override
  Future<List<Assignment>> list({int? classroomId}) async => const [];

  @override
  Future<Assignment> get(int id) async =>
      Assignment(id: id, classroomId: 1, subjectId: 1, title: 'บวกเลข');
}

class _FakeResults extends Fake implements ResultsRepository {
  @override
  Future<List<StudentResult>> list() async => const [];

  @override
  Future<StudentResultDetail> detail(int submissionId) async =>
      StudentResultDetail.fromJson({
        'submission_id': submissionId,
        'title': 'ผลบวกเลข',
        'total_score': 2,
        'responses': <Object>[],
      });
}

void main() {
  late AppDatabase db;

  setUp(() {
    db = AppDatabase(
      DatabaseConnection(
        NativeDatabase.memory(),
        closeStreamsSynchronously: true,
      ),
    );
  });

  tearDown(() => db.close());

  /// Pumps the app router for [user] (signed out when [storage] holds no
  /// token) and goes to [location].
  Future<(ProviderContainer, GoRouter)> pumpAt(
    WidgetTester tester,
    User user,
    String location, {
    InMemoryTokenStorage? storage,
  }) async {
    tester.view.physicalSize = const Size(800, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final container = ProviderContainer(
      overrides: [
        authRepositoryProvider.overrideWithValue(_FakeAuth(user)),
        tokenStorageProvider.overrideWithValue(
          storage ?? InMemoryTokenStorage(token: 'tok'),
        ),
        assignmentsRepositoryProvider.overrideWithValue(_FakeAssignments()),
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        resultsRepositoryProvider.overrideWithValue(_FakeResults()),
        handInRepositoryProvider.overrideWithValue(
          FakeHandIn(
            assignments: const [StudentAssignment(id: 7, title: 'ส่งรูป')],
          ),
        ),
        reviewRepositoryProvider.overrideWithValue(
          FakeReviewRepository(rows: [queueRow(id: 11)]),
        ),
        aiKeyRepositoryProvider.overrideWithValue(
          FakeAiKeyRepository(const AiKeyStatus(configured: true)),
        ),
        cropLoaderProvider.overrideWithValue(NoCropLoader()),
        appDatabaseProvider.overrideWithValue(db),
        uploadSchedulerProvider.overrideWithValue(const NoopUploadScheduler()),
      ],
    );
    addTearDown(container.dispose);
    await container.read(sessionProvider.notifier).restore();
    final router = container.read(routerProvider);
    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    router.go(location);
    await tester.pumpAndSettle();
    return (container, router);
  }

  Future<String> openAt(WidgetTester tester, User user, String location) async {
    final (_, router) = await pumpAt(tester, user, location);
    final at = router.routerDelegate.currentConfiguration.uri.path;
    await unmountScreen(tester);
    return at;
  }

  const teacher = User(id: 1, name: 'ครู', role: 'teacher', status: 'active');
  const student = User(id: 2, name: 'นักเรียน', role: 'student');

  testWidgets('a student may open a result detail', (tester) async {
    expect(
      await openAt(tester, student, '/student/results/70'),
      '/student/results/70',
    );
  });

  testWidgets('a student is kept out of teacher routes', (tester) async {
    expect(await openAt(tester, student, '/assignments/5/review'), '/student');
    expect(await openAt(tester, student, '/settings'), '/student');
    expect(await openAt(tester, student, '/hand-ins/upload'), '/student');
  });

  testWidgets('a student opens the hand-in of an assignment', (tester) async {
    expect(
      await openAt(tester, student, '/student/assignments/7/hand-in'),
      '/student/assignments/7/hand-in',
    );
  });

  testWidgets('a teacher opens "อัปโหลดรูปเพื่อตรวจ" but not a hand-in', (
    tester,
  ) async {
    expect(
      await openAt(tester, teacher, '/hand-ins/upload?assignment=5'),
      '/hand-ins/upload',
    );
    expect(
      await openAt(tester, teacher, '/student/assignments/7/hand-in'),
      '/',
    );
  });

  testWidgets('a teacher opens review, settings and appeals but not the '
      'student area', (tester) async {
    expect(
      await openAt(tester, teacher, '/assignments/5/review'),
      '/assignments/5/review',
    );
    expect(
      await openAt(tester, teacher, '/assignments/5/review/11'),
      '/assignments/5/review/11',
    );
    expect(await openAt(tester, teacher, '/settings'), '/settings');
    expect(await openAt(tester, teacher, '/student/results/70'), '/');
  });

  group('result link from a Classroom announcement (DESIGN §19.7)', () {
    test('only eduvision://r/{id} and /r/{id} are result links', () {
      String? of(String link) => AppRoutes.fromResultLink(Uri.parse(link));
      expect(of('eduvision://r/70'), '/student/results/70');
      expect(of('eduvision://r/70/'), '/student/results/70');
      expect(of('/r/70'), '/student/results/70');
      expect(of('eduvision://r/abc'), isNull);
      expect(of('eduvision://r/0'), isNull);
      expect(of('eduvision://r/70/extra'), isNull);
      expect(of('eduvision://x/70'), isNull);
      expect(of('https://example.com/r/70'), isNull);
      expect(of('/student/results/70'), isNull);
    });

    testWidgets('a signed-in student lands on the result', (tester) async {
      expect(
        await openAt(tester, student, 'eduvision://r/70'),
        '/student/results/70',
      );
    });

    testWidgets('a teacher goes home', (tester) async {
      expect(await openAt(tester, teacher, 'eduvision://r/70'), '/');
    });

    testWidgets('a signed-out student signs in first, then sees the result', (
      tester,
    ) async {
      final storage = InMemoryTokenStorage();
      final (container, router) = await pumpAt(
        tester,
        student,
        'eduvision://r/70',
        storage: storage,
      );
      String at() => router.routerDelegate.currentConfiguration.uri.path;
      expect(at(), AppRoutes.studentLogin);

      storage.token = 'tok';
      await container.read(sessionProvider.notifier).restore();
      await tester.pumpAndSettle();
      expect(at(), '/student/results/70');

      // The link is used once: home goes home afterwards.
      router.go(AppRoutes.student);
      await tester.pumpAndSettle();
      expect(at(), AppRoutes.student);
      await unmountScreen(tester);
    });
  });
}
