import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/student/join_classroom_screen.dart';
import 'package:eduvision/features/student/join_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../google_signin/google_signin_fakes.dart';
import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';

const _seats = [
  JoinSeat(
    studentId: 501,
    studentNumber: 3,
    name: 'ด.ช. ก้อง ใจดี',
    needsCode: true,
  ),
  JoinSeat(
    studentId: 502,
    studentNumber: 4,
    name: 'ด.ญ. แก้ว',
    needsCode: false,
  ),
];

JoinInfo _info({
  bool open = true,
  bool member = false,
  List<JoinSeat>? seats,
}) => JoinInfo(
  name: 'ป.5/1',
  gradeLevel: 5,
  academicYear: 2569,
  open: open,
  teacherName: 'ครูสมศรี',
  schoolName: 'โรงเรียนบ้านหนองบัว',
  member: member,
  seats: seats ?? _seats,
);

class _FakeJoin extends Fake implements JoinRepository {
  _FakeJoin({JoinInfo? info}) : info = info ?? _info();

  JoinInfo info;
  final joins = <(String, int?, String?)>[];
  Object? joinError;

  @override
  Future<JoinInfo> publicInfo(String code) async => info;

  @override
  Future<JoinInfo> preview(String code) async => info;

  @override
  Future<JoinResult> join(
    String code, {
    int? seatId,
    String? studentCode,
  }) async {
    joins.add((code, seatId, studentCode));
    if (joinError case final e?) throw e;
    return JoinResult(
      member: true,
      joined: true,
      studentNumber: seatId == null ? 5 : 3,
    );
  }
}

class _Auth extends Fake implements AuthRepository {
  @override
  Future<String> loginStudent({
    required String username,
    required String password,
  }) async => 'student-token';

  @override
  Future<User> me() async =>
      const User(id: 7, name: 'ด.ช. บี', role: 'student', username: 's1234567');
}

/// Restores the session (no token: signed out) before showing [child].
class _SignedOut extends ConsumerStatefulWidget {
  const _SignedOut({required this.child});

  final Widget child;

  @override
  ConsumerState<_SignedOut> createState() => _SignedOutState();
}

class _SignedOutState extends ConsumerState<_SignedOut> {
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    ref.read(sessionProvider.notifier).restore().then((_) {
      if (mounted) setState(() => _ready = true);
    });
  }

  @override
  Widget build(BuildContext context) =>
      _ready ? widget.child : const SizedBox.shrink();
}

/// Signs the fake student in before showing [child].
class _SignedIn extends ConsumerStatefulWidget {
  const _SignedIn({required this.child});

  final Widget child;

  @override
  ConsumerState<_SignedIn> createState() => _SignedInState();
}

class _SignedInState extends ConsumerState<_SignedIn> {
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    ref
        .read(sessionProvider.notifier)
        .signInStudent(username: 's1234567', password: 'x')
        .then((_) {
          if (mounted) setState(() => _ready = true);
        });
  }

  @override
  Widget build(BuildContext context) =>
      _ready ? widget.child : const SizedBox.shrink();
}

List<GoRoute> _stubs() => [
  GoRoute(
    path: AppRoutes.student,
    builder: (_, _) => const Scaffold(body: Text('student-home-stub')),
  ),
  GoRoute(
    path: '/login',
    builder: (_, state) => Scaffold(body: Text('login-stub ${state.uri}')),
  ),
  GoRoute(
    path: '/join/:code',
    builder: (_, state) =>
        Scaffold(body: Text('join ${state.pathParameters['code']}')),
  ),
];

/// DESIGN §29.13.3: joining a classroom with its class code.
void main() {
  group('repository', () {
    test('the API calls', () async {
      final adapter = FakeHttpAdapter((o) async {
        if (o.path == '/auth/google/join') {
          return jsonResponse(200, {
            'token': 'tok-join',
            'user': {'id': 9},
          });
        }
        if (o.path == '/student/join') {
          return jsonResponse(200, {
            'data': {'member': true, 'joined': true, 'student_number': 5},
          });
        }
        return jsonResponse(200, {
          'data': {
            'name': 'ป.5/1',
            'grade_level': 5,
            'academic_year': 2569,
            'teacher_name': 'ครูสมศรี',
            'school_name': null,
            'open': true,
            'member': false,
            'seats': [
              {
                'student_id': 501,
                'student_number': 3,
                'name': 'ก้อง',
                'needs_code': true,
              },
            ],
          },
        });
      });
      final repo = ApiJoinRepository(fakeDio(adapter));

      final info = await repo.publicInfo('abc 234');
      expect(adapter.requests.last.path, '/join/ABC234');
      expect(info.name, 'ป.5/1');
      expect(info.teacherName, 'ครูสมศรี');
      expect(info.schoolName, isNull);
      expect(info.seats.single.needsCode, isTrue);

      await repo.preview('abc234');
      expect(adapter.requests.last.path, '/student/join/ABC234');

      final result = await repo.join(
        'abc234',
        seatId: 501,
        studentCode: '12345',
      );
      expect(adapter.requests.last.method, 'POST');
      expect(adapter.requests.last.data, {
        'code': 'ABC234',
        'student_id': 501,
        'student_code': '12345',
      });
      expect(result.studentNumber, 5);
      await repo.join('ABC234');
      expect(adapter.requests.last.data, {'code': 'ABC234'});

      expect(
        await repo.googleJoin(linkTicket: 't' * 48, code: 'abc234'),
        'tok-join',
      );
      expect(adapter.requests.last.path, '/auth/google/join');
      expect(adapter.requests.last.data, {
        'link_ticket': 't' * 48,
        'join_code': 'ABC234',
        'accept_notice': true,
      });
    });

    test('normalizeJoinCode', () {
      expect(normalizeJoinCode(' abc 234 '), 'ABC234');
    });
  });

  group('signed out', () {
    testWidgets('names the classroom and leads to the student sign-in', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(800, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final fake = _FakeJoin();
      await pumpScreen(
        tester,
        const _SignedOut(child: JoinClassroomScreen(code: 'abc234')),
        overrides: [
          joinRepositoryProvider.overrideWithValue(fake),
          tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
        ],
        extraRoutes: _stubs(),
      );
      await tester.pumpAndSettle();

      expect(find.text('ห้อง ป.5/1'), findsOneWidget);
      expect(find.textContaining('ครู ครูสมศรี'), findsOneWidget);
      expect(find.byKey(const ValueKey('seat_501')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('join_login')));
      await tester.pumpAndSettle();
      expect(find.text('login-stub /login?tab=student'), findsOneWidget);
    });

    testWidgets('a closed room says so', (tester) async {
      await pumpScreen(
        tester,
        const _SignedOut(child: JoinClassroomScreen(code: 'abc234')),
        overrides: [
          joinRepositoryProvider.overrideWithValue(
            _FakeJoin(info: _info(open: false)),
          ),
          tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
        ],
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('join_closed')), findsOneWidget);
      expect(find.byKey(const ValueKey('join_login')), findsNothing);
    });
  });

  group('a signed-in pupil', () {
    Future<_FakeJoin> pump(
      WidgetTester tester, {
      _FakeJoin? fake,
      String code = 'ABC234',
    }) async {
      tester.view.physicalSize = const Size(800, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final repo = fake ?? _FakeJoin();
      await pumpScreen(
        tester,
        _SignedIn(child: JoinClassroomScreen(code: code)),
        overrides: [
          joinRepositoryProvider.overrideWithValue(repo),
          authRepositoryProvider.overrideWithValue(_Auth()),
          tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
        ],
        extraRoutes: _stubs(),
      );
      await tester.pumpAndSettle();
      return repo;
    }

    testWidgets('claims a seat, giving the student code the teacher typed', (
      tester,
    ) async {
      final fake = await pump(tester);
      expect(find.text('เลือกชื่อของคุณ'), findsOneWidget);
      expect(find.byKey(const ValueKey('seat_501')), findsOneWidget);
      expect(find.byKey(const ValueKey('seat_502')), findsOneWidget);
      expect(find.byKey(const ValueKey('join_student_code')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('join_submit')));
      await tester.pumpAndSettle();
      expect(find.textContaining('เลือกชื่อของคุณ หรือเลือก'), findsOneWidget);
      expect(fake.joins, isEmpty);

      await tester.tap(find.byKey(const ValueKey('seat_501')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('join_student_code')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('join_submit')));
      await tester.pumpAndSettle();
      expect(find.text('กรอกเลขประจำตัวนักเรียนตามที่ครูมี'), findsOneWidget);
      expect(fake.joins, isEmpty);

      await tester.enterText(
        find.byKey(const ValueKey('join_student_code')),
        ' 12345 ',
      );
      await tester.tap(find.byKey(const ValueKey('join_submit')));
      await tester.pumpAndSettle();
      expect(fake.joins, [('ABC234', 501, '12345')]);
      expect(find.text('student-home-stub'), findsOneWidget);
      expect(find.text('เข้าห้อง ป.5/1 แล้ว (เลขที่ 3)'), findsOneWidget);
    });

    testWidgets('joins under the own name when the roster has no seat', (
      tester,
    ) async {
      final fake = await pump(
        tester,
        fake: _FakeJoin(info: _info(seats: const [])),
      );
      expect(find.text('เข้าห้องด้วยชื่อตัวเอง'), findsOneWidget);
      expect(find.text('ด.ช. บี'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('join_own_name')));
      await tester.tap(find.byKey(const ValueKey('join_submit')));
      await tester.pumpAndSettle();
      expect(fake.joins, [('ABC234', null, null)]);
      expect(find.text('เข้าห้อง ป.5/1 แล้ว (เลขที่ 5)'), findsOneWidget);
    });

    testWidgets('a server refusal is shown', (tester) async {
      final fake = await pump(
        tester,
        fake: _FakeJoin()
          ..joinError = apiError(
            409,
            'seat_taken',
            message: 'ที่นั่งนี้มีคนใช้แล้ว',
          ),
      );
      await tester.tap(find.byKey(const ValueKey('seat_502')));
      await tester.tap(find.byKey(const ValueKey('join_submit')));
      await tester.pumpAndSettle();
      expect(fake.joins, [('ABC234', 502, null)]);
      expect(find.text('ที่นั่งนี้มีคนใช้แล้ว'), findsOneWidget);
      expect(find.text('student-home-stub'), findsNothing);
    });

    testWidgets('a member is told so', (tester) async {
      await pump(tester, fake: _FakeJoin(info: _info(member: true)));
      expect(find.byKey(const ValueKey('join_member')), findsOneWidget);
      expect(find.byKey(const ValueKey('join_submit')), findsNothing);
    });

    testWidgets('typing the code opens its page', (tester) async {
      await pumpScreen(
        tester,
        const _SignedIn(child: JoinClassroomScreen()),
        overrides: [
          joinRepositoryProvider.overrideWithValue(_FakeJoin()),
          authRepositoryProvider.overrideWithValue(_Auth()),
          tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
        ],
        extraRoutes: _stubs(),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('join_code_next')));
      await tester.pump();
      expect(find.text('รหัสห้องมี 6 ตัวอักษร'), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('join_code_field')),
        'abc 234',
      );
      await tester.tap(find.byKey(const ValueKey('join_code_next')));
      await tester.pumpAndSettle();
      expect(find.text('join ABC234'), findsOneWidget);
    });
  });
}
