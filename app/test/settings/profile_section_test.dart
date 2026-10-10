import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/settings/profile_section.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';

class _Auth extends Fake implements AuthRepository {
  String name = 'ครูสมศรี';
  String? school;
  final passwords = <Map<String, String?>>[];

  @override
  Future<String> login({
    required String email,
    required String password,
  }) async => 'teacher-token';

  @override
  Future<User> me() async => User(
    id: 3,
    name: name,
    role: 'teacher',
    email: 'kru@example.com',
    schoolName: school,
  );

  @override
  Future<User> updateProfile({
    required String name,
    required String schoolName,
  }) async {
    this.name = name;
    school = schoolName.isEmpty ? null : schoolName;
    return me();
  }

  @override
  Future<void> changePassword({
    required String password,
    String? currentPassword,
  }) async => passwords.add({'password': password, 'current': currentPassword});
}

class _SignedIn extends ConsumerStatefulWidget {
  const _SignedIn();

  @override
  ConsumerState<_SignedIn> createState() => _SignedInState();
}

class _SignedInState extends ConsumerState<_SignedIn> {
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    ref.read(sessionProvider.notifier).signIn(email: 'a', password: 'b').then((
      _,
    ) {
      if (mounted) setState(() => _ready = true);
    });
  }

  @override
  Widget build(BuildContext context) =>
      Scaffold(body: _ready ? const ProfileSection() : const SizedBox.shrink());
}

/// DESIGN §29.1: the teacher edits their own name, school name and password.
void main() {
  Future<_Auth> pump(WidgetTester tester) async {
    final auth = _Auth();
    await pumpScreen(
      tester,
      const _SignedIn(),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      ],
    );
    return auth;
  }

  testWidgets('edits the name and the school name', (tester) async {
    final auth = await pump(tester);
    expect(find.text('kru@example.com · ยังไม่ระบุโรงเรียน'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('profile_edit')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('profile_name')), ' ');
    await tester.tap(find.byKey(const ValueKey('profile_save')));
    await tester.pumpAndSettle();
    expect(find.text('กรอกชื่อ'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('profile_name')),
      'ครูสมศรี ใจดี',
    );
    await tester.enterText(
      find.byKey(const ValueKey('profile_school')),
      'โรงเรียนบ้านหนองน้ำใส',
    );
    await tester.tap(find.byKey(const ValueKey('profile_save')));
    await tester.pumpAndSettle();

    expect(auth.school, 'โรงเรียนบ้านหนองน้ำใส');
    expect(find.text('ครูสมศรี ใจดี'), findsOneWidget);
    expect(
      find.text('kru@example.com · โรงเรียนบ้านหนองน้ำใส'),
      findsOneWidget,
    );
  });

  testWidgets('changes the password', (tester) async {
    final auth = await pump(tester);
    await tester.tap(find.byKey(const ValueKey('profile_password')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('teacher_password_new')),
      'short',
    );
    await tester.tap(find.byKey(const ValueKey('teacher_password_save')));
    await tester.pumpAndSettle();
    expect(find.text('อย่างน้อย 8 ตัว'), findsWidgets);
    expect(auth.passwords, isEmpty);

    await tester.enterText(
      find.byKey(const ValueKey('teacher_password_current')),
      'old-password',
    );
    await tester.enterText(
      find.byKey(const ValueKey('teacher_password_new')),
      'new-password',
    );
    await tester.enterText(
      find.byKey(const ValueKey('teacher_password_again')),
      'new-password',
    );
    await tester.tap(find.byKey(const ValueKey('teacher_password_save')));
    await tester.pumpAndSettle();
    expect(auth.passwords.single, {
      'password': 'new-password',
      'current': 'old-password',
    });
  });

  test('the API calls', () async {
    final adapter = FakeHttpAdapter(
      (o) async => o.method == 'PUT'
          ? jsonResponse(204, null)
          : jsonResponse(200, {
              'data': {
                'id': 3,
                'name': 'ครู ก',
                'role': 'teacher',
                'school': {'id': 1, 'name': 'โรงเรียน ข'},
              },
            }),
    );
    final repo = ApiAuthRepository(fakeDio(adapter));
    final user = await repo.updateProfile(
      name: 'ครู ก',
      schoolName: 'โรงเรียน ข',
    );
    expect(adapter.requests.last.method, 'PATCH');
    expect(adapter.requests.last.path, '/me');
    expect(adapter.requests.last.data, {
      'name': 'ครู ก',
      'school_name': 'โรงเรียน ข',
    });
    expect(user.schoolName, 'โรงเรียน ข');
    await repo.changePassword(password: 'new-password');
    expect(adapter.requests.last.path, '/me/password');
    expect(adapter.requests.last.data, {'password': 'new-password'});
  });
}
