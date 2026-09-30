import 'package:drift/drift.dart' show DatabaseConnection;
import 'package:drift/native.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/core/db/database_provider.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/upload_queue/upload_worker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../google_classroom/google_fakes.dart';

class _FakeAuth extends Fake implements AuthRepository {
  @override
  Future<User> me() async =>
      const User(id: 1, name: 'ครูสมศรี', role: 'teacher', status: 'active');
}

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [];
}

/// The import routes sit beside `/classrooms/:id`, which must not take
/// "import-google" as a classroom id (DESIGN §19.2).
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

  Future<FakeGoogleRepository> openAt(
    WidgetTester tester,
    String location,
  ) async {
    final google =
        FakeGoogleRepository(
            courseRows: const [GoogleCourse(courseId: '6210', name: 'คณิต')],
          )
          ..previews['6210'] = const ClassroomImportPreview(
            courseId: '6210',
            name: 'คณิต',
            suggestedName: 'คณิต ป.5/1',
            gradeLevelGuess: 5,
            academicYear: 2569,
            students: [],
          );
    final container = ProviderContainer(
      overrides: [
        authRepositoryProvider.overrideWithValue(_FakeAuth()),
        tokenStorageProvider.overrideWithValue(
          InMemoryTokenStorage(token: 'tok'),
        ),
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        googleClassroomRepositoryProvider.overrideWithValue(google),
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
    return google;
  }

  testWidgets('/classrooms/import-google opens the course picker', (
    tester,
  ) async {
    await openAt(tester, AppRoutes.classroomImportGoogle);
    expect(find.text('นำเข้าจาก Google Classroom'), findsOneWidget);
    expect(find.text('คณิต'), findsOneWidget);
  });

  testWidgets('/classrooms/import-google/{course} opens its preview', (
    tester,
  ) async {
    final google = await openAt(
      tester,
      AppRoutes.classroomImportPreview('6210'),
    );
    expect(google.previewCalls, ['6210']);
    expect(find.widgetWithText(TextFormField, 'คณิต ป.5/1'), findsOneWidget);
  });
}
