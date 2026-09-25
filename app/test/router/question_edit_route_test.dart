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
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/upload_queue/upload_worker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

class _FakeAuth extends Fake implements AuthRepository {
  @override
  Future<User> me() async =>
      const User(id: 1, name: 'ครูสมศรี', role: 'teacher', status: 'active');
}

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 7,
      name: 'ป.5/2',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'ABC123',
    ),
  ];
}

class _FakeAssignments extends Fake implements AssignmentsRepository {
  final updated = <(int, QuestionDraft)>[];
  final added = <QuestionDraft>[];

  static const _question = Question(
    id: 501,
    position: 2,
    type: QuestionType.short,
    promptText: 'ห้องสมุดมีหนังสือทั้งหมดกี่เล่ม',
    maxPoints: 2,
    answerKey: {
      'accepted': ['1,000', 'หนึ่งพัน'],
    },
  );

  @override
  Future<List<Assignment>> list({int? classroomId}) async => const [];

  @override
  Future<Assignment> get(int id) async => Assignment(
    id: id,
    classroomId: 7,
    subjectId: 1,
    title: 'จำนวนนับ',
    questions: const [_question],
  );

  @override
  Future<Question> updateQuestion(int questionId, QuestionDraft draft) async {
    updated.add((questionId, draft));
    return _question;
  }

  @override
  Future<Question> addQuestion(int assignmentId, QuestionDraft draft) async {
    added.add(draft);
    return _question;
  }
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

  /// The real app router with a signed-in teacher, opened directly at
  /// [location] the way a deep link or a restored process would open it:
  /// no route `extra`.
  Future<_FakeAssignments> openAt(WidgetTester tester, String location) async {
    final assignments = _FakeAssignments();
    final container = ProviderContainer(
      overrides: [
        authRepositoryProvider.overrideWithValue(_FakeAuth()),
        tokenStorageProvider.overrideWithValue(
          InMemoryTokenStorage(token: 'tok'),
        ),
        assignmentsRepositoryProvider.overrideWithValue(assignments),
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
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
    return assignments;
  }

  testWidgets('edit route without extra loads the question and PATCHes it', (
    tester,
  ) async {
    final repo = await openAt(tester, AppRoutes.questionEdit(3, 501));

    expect(find.text('แก้ไขข้อ 2'), findsOneWidget);
    expect(find.text('เพิ่มคำถาม'), findsNothing);
    expect(find.text('ห้องสมุดมีหนังสือทั้งหมดกี่เล่ม'), findsOneWidget);
    final accepted = tester.widget<TextFormField>(
      find.widgetWithText(TextFormField, 'คำตอบที่ยอมรับ (บรรทัดละคำตอบ)'),
    );
    expect(
      accepted.controller!.text,
      '1,000\nหนึ่งพัน',
      reason: 'one accepted answer per line; the comma stays inside 1,000',
    );

    final save = find.widgetWithText(FilledButton, 'บันทึก');
    await tester.ensureVisible(save);
    await tester.tap(save);
    await tester.pumpAndSettle();

    expect(repo.added, isEmpty, reason: 'must not create a new question');
    final (id, draft) = repo.updated.single;
    expect(id, 501);
    expect(draft.type, QuestionType.short);
    expect(draft.answerKey, {
      'accepted': ['1,000', 'หนึ่งพัน'],
    });
    await unmountScreen(tester);
  });

  testWidgets('edit route for an unknown question says so', (tester) async {
    final repo = await openAt(tester, AppRoutes.questionEdit(3, 999));
    expect(find.text('ไม่พบคำถามนี้ในการบ้าน'), findsOneWidget);
    expect(find.text('เพิ่มคำถาม'), findsNothing);
    expect(repo.updated, isEmpty);
    await unmountScreen(tester);
  });
}
