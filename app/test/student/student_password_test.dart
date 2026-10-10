import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/one_time_pins_view.dart';
import 'package:eduvision/features/student/student_password_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';

class _Auth extends Fake implements AuthRepository {
  _Auth({required this.forced});

  bool forced;
  Object? failWith;
  final changes = <Map<String, String?>>[];

  @override
  Future<String> loginStudent({
    required String username,
    required String password,
  }) async => 'student-token';

  @override
  Future<User> me() async => User(
    id: 7,
    name: 'ด.ช. ก้อง',
    role: 'student',
    username: 's1234567',
    mustChangePassword: forced,
  );

  @override
  Future<User> changeStudentPassword({
    required String password,
    String? currentPassword,
  }) async {
    if (failWith case final e?) throw e;
    changes.add({'password': password, 'current': currentPassword});
    forced = false;
    return me();
  }
}

/// DESIGN §29.10: the student's own password.
void main() {
  Future<({_Auth auth, dynamic container})> pump(
    WidgetTester tester, {
    required bool forced,
  }) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    final auth = _Auth(forced: forced);
    final container = await pumpScreen(
      tester,
      const _SignedIn(child: StudentPasswordScreen()),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      ],
    );
    return (auth: auth, container: container);
  }

  testWidgets('the initial password is replaced without asking for it', (
    tester,
  ) async {
    final r = await pump(tester, forced: true);
    expect(find.text('ตั้งรหัสผ่านของคุณ'), findsOneWidget);
    expect(find.byKey(const ValueKey('password_forced_note')), findsOneWidget);
    expect(find.text('ชื่อผู้ใช้ของคุณ: s1234567'), findsOneWidget);
    expect(find.byKey(const ValueKey('password_current')), findsNothing);

    await tester.enterText(
      find.byKey(const ValueKey('password_new')),
      '123456',
    );
    await tester.enterText(
      find.byKey(const ValueKey('password_again')),
      '123456',
    );
    await tester.tap(find.byKey(const ValueKey('password_save')));
    await tester.pumpAndSettle();
    expect(find.text('ห้ามใช้ 123456'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('password_new')),
      'ปลาทอง99',
    );
    await tester.enterText(find.byKey(const ValueKey('password_again')), 'x');
    await tester.tap(find.byKey(const ValueKey('password_save')));
    await tester.pumpAndSettle();
    expect(find.text('รหัสผ่านสองช่องไม่ตรงกัน'), findsOneWidget);
    expect(r.auth.changes, isEmpty);

    await tester.enterText(
      find.byKey(const ValueKey('password_again')),
      'ปลาทอง99',
    );
    await tester.tap(find.byKey(const ValueKey('password_save')));
    await tester.pumpAndSettle();
    expect(r.auth.changes, [
      {'password': 'ปลาทอง99', 'current': null},
    ]);
    final session = r.container.read(sessionProvider) as SignedIn;
    expect(session.user.mustChangePassword, isFalse);
  });

  testWidgets('a later change asks for the current password', (tester) async {
    final r = await pump(tester, forced: false);
    expect(find.text('เปลี่ยนรหัสผ่าน'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('password_save')));
    await tester.pumpAndSettle();
    expect(find.text('กรอกรหัสผ่านปัจจุบัน'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('password_current')),
      'เก่า1234',
    );
    await tester.enterText(
      find.byKey(const ValueKey('password_new')),
      'ใหม่5678',
    );
    await tester.enterText(
      find.byKey(const ValueKey('password_again')),
      'ใหม่5678',
    );
    await tester.tap(find.byKey(const ValueKey('password_save')));
    await tester.pumpAndSettle();
    expect(r.auth.changes.single, {
      'password': 'ใหม่5678',
      'current': 'เก่า1234',
    });
  });

  test('the API calls and the user payload', () async {
    final adapter = FakeHttpAdapter(
      (o) async => o.path == '/student/password'
          ? jsonResponse(200, {
              'user': {
                'id': 7,
                'name': 'ก้อง',
                'role': 'student',
                'username': 's1234567',
                'must_change_password': false,
              },
            })
          : jsonResponse(200, {'token': 'tok'}),
    );
    final repo = ApiAuthRepository(fakeDio(adapter));

    expect(await repo.loginStudent(username: 's1', password: 'p'), 'tok');
    expect(adapter.requests.last.path, '/auth/student/login');
    expect(adapter.requests.last.data, {'username': 's1', 'password': 'p'});

    final user = await repo.changeStudentPassword(password: 'ใหม่5678');
    expect(adapter.requests.last.method, 'PUT');
    expect(adapter.requests.last.data, {'password': 'ใหม่5678'});
    expect(user.username, 's1234567');
    expect(User.fromJson(user.toJson()).mustChangePassword, isFalse);
    await repo.changeStudentPassword(password: 'a12345', currentPassword: 'b');
    expect(adapter.requests.last.data, {
      'password': 'a12345',
      'current_password': 'b',
    });
  });

  test('the copied list carries the username and the initial password', () {
    expect(
      pinsAsText(const [
        EnrolledStudent(
          studentId: 1,
          studentNumber: 3,
          name: 'ก้อง',
          pin: '123456',
          username: 's1234567',
        ),
      ]),
      'เลขที่\tชื่อ\tชื่อผู้ใช้\tรหัสผ่านเริ่มต้น\n3\tก้อง\ts1234567\t123456',
    );
  });
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
