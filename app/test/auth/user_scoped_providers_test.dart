import 'dart:async';

import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/local_user_data.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_providers.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/home/ai_key_card.dart';
import 'package:eduvision/features/results/results_repository.dart';
import 'package:eduvision/features/results/student_result.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:eduvision/features/review/review_providers.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// A shared school device: one person signs out, the next signs in on the
/// same ProviderContainer (the app keeps one for its whole life). Nothing
/// cached for the first user may reach the second (DESIGN §9).

const _teacherA = User(id: 1, name: 'ครูเอ', role: 'teacher');
const _teacherB = User(id: 2, name: 'ครูบี', role: 'teacher');
const _studentA = User(id: 11, name: 'ด.ญ. เอ', role: 'student');
const _studentB = User(id: 12, name: 'ด.ช. บี', role: 'student');

/// The server: answers for whoever signed in last.
class _Server extends Fake implements AuthRepository {
  User? user;

  @override
  Future<String> login({
    required String email,
    required String password,
  }) async => 'tok-${user!.id}';

  @override
  Future<User> me() async => user!;

  @override
  Future<void> logout() async {}
}

class _NoLocalData extends Fake implements LocalUserData {
  @override
  Future<void> wipe() async {}
}

class _Keys extends Fake implements AiKeyRepository {
  _Keys(this.server);

  final _Server server;
  final calls = <int>[];

  /// Per user id: the answer (AiKeyStatus), an error to throw, or a
  /// Completer to hold the request open.
  final answers = <int, Object>{};

  @override
  Future<AiKeyStatus> status() async {
    final id = server.user!.id;
    calls.add(id);
    return switch (answers[id]) {
      AiKeyStatus s => s,
      Completer<AiKeyStatus> c => c.future,
      final Object e => throw e,
      null => const AiKeyStatus(configured: false),
    };
  }
}

class _Review extends Fake implements ReviewRepository {
  _Review(this.server);

  final _Server server;
  final calls = <int>[];

  @override
  Future<List<Appeal>> appeals({String status = 'open'}) async {
    final id = server.user!.id;
    calls.add(id);
    return [
      if (id == _teacherA.id)
        const Appeal(id: 5, status: 'open', reason: 'ครูเอ: ข้อ 2 ถูกแล้ว'),
    ];
  }
}

class _Classrooms extends Fake implements ClassroomsRepository {
  _Classrooms(this.server);

  final _Server server;
  final calls = <int>[];

  @override
  Future<List<Classroom>> list() async {
    final id = server.user!.id;
    calls.add(id);
    return [
      if (id == _teacherA.id)
        const Classroom(
          id: 7,
          name: 'ม.1/1 ของครูเอ',
          gradeLevel: 7,
          academicYear: 2569,
          classCode: 'ABC123',
        ),
    ];
  }
}

class _Results extends Fake implements ResultsRepository {
  _Results(this.server);

  final _Server server;
  final calls = <int>[];

  /// When set for a user id, list() fails for that user (offline).
  final failFor = <int>{};

  @override
  Future<List<StudentResult>> list() async {
    final id = server.user!.id;
    calls.add(id);
    if (failFor.contains(id)) throw StateError('offline');
    return [
      if (id == _studentA.id)
        const StudentResult(
          submissionId: 31,
          title: 'บวกเลขของเอ',
          totalScore: 3,
          maxScore: 10,
        ),
    ];
  }
}

class _Device {
  _Device() {
    container = ProviderContainer(
      overrides: [
        authRepositoryProvider.overrideWithValue(server),
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
        localUserDataProvider.overrideWithValue(_NoLocalData()),
        aiKeyRepositoryProvider.overrideWithValue(keys),
        reviewRepositoryProvider.overrideWithValue(review),
        classroomsRepositoryProvider.overrideWithValue(classrooms),
        resultsRepositoryProvider.overrideWithValue(results),
      ],
    );
    addTearDown(container.dispose);
  }

  final server = _Server();
  late final keys = _Keys(server);
  late final review = _Review(server);
  late final classrooms = _Classrooms(server);
  late final results = _Results(server);
  late final ProviderContainer container;

  SessionNotifier get session => container.read(sessionProvider.notifier);

  Future<void> signIn(User user) async {
    server.user = user;
    await session.signIn(email: 'x@example.com', password: 'pw');
    expect(container.read(currentUserProvider)?.id, user.id);
  }

  /// Sign-out as the app does it: the signed-in screens (here: plain
  /// listeners) go away with the navigation to /login.
  Future<void> signOut(List<ProviderSubscription<Object?>> screens) async {
    await session.signOut();
    for (final s in screens) {
      s.close();
    }
    await container.pump();
  }
}

void main() {
  test('teacher B never sees teacher A\'s key status, appeals or classrooms '
      'after A signs out on the same device', () async {
    final d = _Device();
    final c = d.container;
    d.keys.answers[_teacherA.id] = AiKeyStatus(
      configured: true,
      keyLast4: '1111',
      lastVerifiedAt: DateTime.utc(2026, 9, 1),
    );

    await d.signIn(_teacherA);
    final screens = [
      c.listen(aiKeyProvider, (_, _) {}),
      c.listen(openAppealsProvider, (_, _) {}),
      c.listen(classroomsProvider, (_, _) {}),
    ];
    expect((await c.read(aiKeyProvider.future)).keyLast4, '1111');
    expect(await c.read(openAppealsProvider.future), hasLength(1));
    expect(await c.read(classroomsProvider.future), hasLength(1));

    await d.signOut(screens);

    // B's key request is still in flight: nothing from A in the meantime.
    final bKey = Completer<AiKeyStatus>();
    d.keys.answers[_teacherB.id] = bKey;
    await d.signIn(_teacherB);
    c.listen(aiKeyProvider, (_, _) {});
    expect(c.read(aiKeyProvider).isLoading, isTrue);
    expect(c.read(aiKeyProvider).value, isNull);

    bKey.complete(const AiKeyStatus(configured: false));
    final status = await c.read(aiKeyProvider.future);
    expect(status.configured, isFalse);
    expect(status.keyLast4, isNull);
    expect(c.read(aiKeyProvider).value?.canGrade, isFalse);

    expect(await c.read(openAppealsProvider.future), isEmpty);
    expect(await c.read(classroomsProvider.future), isEmpty);

    // Every provider asked the server again, as B.
    expect(d.keys.calls, [_teacherA.id, _teacherB.id]);
    expect(d.review.calls, [_teacherA.id, _teacherB.id]);
    expect(d.classrooms.calls, [_teacherA.id, _teacherB.id]);
  });

  test('a failed fetch for the next teacher does not fall back to the '
      'previous teacher\'s key', () async {
    final d = _Device();
    final c = d.container;
    d.keys.answers[_teacherA.id] = const AiKeyStatus(
      configured: true,
      keyLast4: '1111',
    );

    await d.signIn(_teacherA);
    final screen = c.listen(aiKeyProvider, (_, _) {});
    await c.read(aiKeyProvider.future);
    await d.signOut([screen]);

    d.keys.answers[_teacherB.id] = StateError('offline');
    await d.signIn(_teacherB);
    c.listen(aiKeyProvider, (_, _) {});
    await expectLater(c.read(aiKeyProvider.future), throwsStateError);
    expect(c.read(aiKeyProvider).hasError, isTrue);
    expect(c.read(aiKeyProvider).value, isNull);
  });

  test('the next student on a shared tablet does not see the previous '
      'student\'s results', () async {
    final d = _Device();
    final c = d.container;

    await d.signIn(_studentA);
    final screen = c.listen(studentResultsProvider, (_, _) {});
    expect(
      (await c.read(studentResultsProvider.future)).single.title,
      'บวกเลขของเอ',
    );
    await d.signOut([screen]);

    await d.signIn(_studentB);
    c.listen(studentResultsProvider, (_, _) {});
    expect(c.read(studentResultsProvider).value, isNull);
    expect(await c.read(studentResultsProvider.future), isEmpty);
    expect(d.results.calls, [_studentA.id, _studentB.id]);

    // Offline for B after a refresh: still nothing of A's.
    d.results.failFor.add(_studentB.id);
    await expectLater(
      c.read(studentResultsProvider.notifier).refresh(),
      throwsStateError,
    );
    expect(c.read(studentResultsProvider).value, isEmpty);
  });

  test('signed out: a screen still on its way out gets no data and no '
      'unauthenticated request is sent', () async {
    final d = _Device();
    final c = d.container;
    d.keys.answers[_teacherA.id] = const AiKeyStatus(
      configured: true,
      keyLast4: '1111',
    );

    await d.signIn(_teacherA);
    // The dashboard is still mounted while the router leaves for /login.
    c.listen(aiKeyProvider, (_, _) {});
    c.listen(openAppealsProvider, (_, _) {});
    await c.read(aiKeyProvider.future);
    await c.read(openAppealsProvider.future);

    await d.session.signOut();
    await c.pump();

    expect(c.read(aiKeyProvider).error, isA<SignedOutError>());
    expect(c.read(openAppealsProvider).error, isA<SignedOutError>());
    expect(d.keys.calls, [_teacherA.id]);
    expect(d.review.calls, [_teacherA.id]);
  });

  testWidgets('home key card: teacher B sees B\'s status, never A\'s masked '
      'key', (tester) async {
    final server = _Server();
    final keys = _Keys(server)
      ..answers[_teacherA.id] = const AiKeyStatus(
        configured: true,
        keyLast4: '1111',
      );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          authRepositoryProvider.overrideWithValue(server),
          tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
          localUserDataProvider.overrideWithValue(_NoLocalData()),
          aiKeyRepositoryProvider.overrideWithValue(keys),
        ],
        // Stand-in for the router: the card only exists while signed in.
        child: MaterialApp(
          home: Scaffold(
            body: Consumer(
              builder: (context, ref, _) =>
                  ref.watch(currentUserProvider) == null
                  ? const Text('login')
                  : AiKeyCard(onOpenSettings: () {}),
            ),
          ),
        ),
      ),
    );
    final container = ProviderScope.containerOf(
      tester.element(find.byType(Scaffold)),
    );
    final session = container.read(sessionProvider.notifier);
    Future<void> signIn(User user) => tester.runAsync(() async {
      server.user = user;
      await session.signIn(email: 'x@example.com', password: 'pw');
    });

    await session.forceSignOut();
    await tester.pumpAndSettle();
    expect(find.text('login'), findsOneWidget);

    await signIn(_teacherA);
    await tester.pumpAndSettle();
    expect(find.text('ใส่แล้ว (••••1111)'), findsOneWidget);

    await tester.runAsync(session.signOut);
    await tester.pumpAndSettle();
    expect(find.text('login'), findsOneWidget);

    final bKey = Completer<AiKeyStatus>();
    keys.answers[_teacherB.id] = bKey;
    await signIn(_teacherB);
    await tester.pump();
    expect(find.byType(AiKeyCard), findsOneWidget);
    expect(find.textContaining('1111'), findsNothing);
    expect(find.byType(LinearProgressIndicator), findsOneWidget);

    bKey.complete(const AiKeyStatus(configured: false));
    await tester.pumpAndSettle();
    expect(find.text('ยังไม่ได้ใส่'), findsOneWidget);
    expect(find.textContaining('1111'), findsNothing);
    expect(keys.calls, [_teacherA.id, _teacherB.id]);
  });
}
