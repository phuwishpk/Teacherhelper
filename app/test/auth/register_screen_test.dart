import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/features/auth/register_screen.dart';
import 'package:eduvision/features/google_signin/google_signin_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';

class _FakeAuth extends Fake implements AuthRepository {
  final calls = <Map<String, Object?>>[];
  final tickets = <String?>[];
  Object? error;

  @override
  Future<void> register({
    String? schoolName,
    required String name,
    required String email,
    required String password,
    String? googleLinkTicket,
  }) async {
    tickets.add(googleLinkTicket);
    if (error != null) {
      final e = error!;
      error = null;
      throw e;
    }
    calls.add({
      'school_name': schoolName,
      'name': name,
      'email': email,
      'password': password,
    });
  }
}

Finder get _confirmField =>
    find.byKey(const ValueKey('register_password_confirm'));

Future<_FakeAuth> _pump(
  WidgetTester tester,
  _FakeAuth auth, {
  GoogleRegistration? google,
}) async {
  tester.view.physicalSize = const Size(800, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await pumpScreen(
    tester,
    RegisterScreen(google: google),
    overrides: [
      authRepositoryProvider.overrideWithValue(auth),
      tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
    ],
    extraRoutes: [
      GoRoute(path: '/login', builder: (_, _) => const Text('login-stub')),
    ],
  );
  return auth;
}

Future<void> _fillTeacher(WidgetTester tester) async {
  await tester.enterText(
    find.widgetWithText(TextFormField, 'ชื่อ-นามสกุล'),
    'ครูสมศรี ใจดี',
  );
  await tester.enterText(
    find.widgetWithText(TextFormField, 'อีเมล'),
    'somsri@example.com',
  );
  await tester.enterText(
    find.widgetWithText(TextFormField, 'รหัสผ่าน (อย่างน้อย 8 ตัว)'),
    'secret-pass-1',
  );
  await tester.enterText(_confirmField, 'secret-pass-1');
}

Future<void> _submit(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
  await tester.pumpAndSettle();
}

/// DESIGN §29.13.2: no school to pick, only the teacher's own school name.
void main() {
  testWidgets('has no school fields and validates locally first', (
    tester,
  ) async {
    final auth = await _pump(tester, _FakeAuth());
    expect(find.textContaining('รหัสโรงเรียน'), findsNothing);
    expect(find.textContaining('โรงเรียนในระบบ'), findsNothing);
    expect(find.byType(DropdownButtonFormField<int>), findsNothing);
    expect(find.byType(TextFormField), findsNWidgets(5));

    await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
    await tester.pump();
    expect(find.text('กรอกชื่อ'), findsOneWidget);
    expect(find.text('กรอกอีเมลให้ถูกต้อง'), findsOneWidget);
    expect(find.text('รหัสผ่านต้องยาวอย่างน้อย 8 ตัว'), findsOneWidget);
    expect(auth.calls, isEmpty);
  });

  testWidgets(
    'the typed school name is sent, then the pending-approval message',
    (tester) async {
      final auth = await _pump(tester, _FakeAuth());

      await tester.enterText(
        find.byKey(const ValueKey('register_school_name')),
        '  โรงเรียนบ้านหนองบัว ',
      );
      await _fillTeacher(tester);
      await _submit(tester);

      expect(auth.calls, [
        {
          'school_name': 'โรงเรียนบ้านหนองบัว',
          'name': 'ครูสมศรี ใจดี',
          'email': 'somsri@example.com',
          'password': 'secret-pass-1',
        },
      ]);
      expect(find.text('สมัครสำเร็จ'), findsOneWidget);
      expect(
        find.textContaining('รอผู้ดูแลระบบอนุมัติ'),
        findsOneWidget,
        reason: 'new accounts are pending until an admin approves',
      );

      await tester.tap(find.text('รับทราบ'));
      await tester.pumpAndSettle();
      expect(find.text('login-stub'), findsOneWidget);
    },
  );

  testWidgets('an empty school name is left out', (tester) async {
    final auth = await _pump(tester, _FakeAuth());
    await _fillTeacher(tester);
    await _submit(tester);
    expect(auth.calls.single['school_name'], isNull);
    expect(find.text('สมัครสำเร็จ'), findsOneWidget);
  });

  testWidgets('the repeated password must match before anything is sent', (
    tester,
  ) async {
    final auth = await _pump(tester, _FakeAuth());
    await _fillTeacher(tester);
    await tester.enterText(_confirmField, 'secret-pass-2');
    await _submit(tester);
    expect(find.text('รหัสผ่านไม่ตรงกัน'), findsOneWidget);
    expect(auth.calls, isEmpty);

    await tester.enterText(_confirmField, 'secret-pass-1');
    await _submit(tester);
    expect(find.text('สมัครสำเร็จ'), findsOneWidget);
    expect(auth.calls.single['password'], 'secret-pass-1');
  });

  group('from a Google sign-in (DESIGN §24.9.5)', () {
    const google = GoogleRegistration(
      linkTicket: 'tkt',
      name: 'ครูใหม่ ใจดี',
      email: 'new@school.ac.th',
    );

    Future<_FakeAuth> pumpGoogle(WidgetTester tester) async {
      final auth = await _pump(tester, _FakeAuth(), google: google);
      await tester.enterText(
        find.widgetWithText(TextFormField, 'รหัสผ่าน (อย่างน้อย 8 ตัว)'),
        'secret-pass-1',
      );
      await tester.enterText(_confirmField, 'secret-pass-1');
      return auth;
    }

    testWidgets('prefills the Google name and e-mail and sends the ticket', (
      tester,
    ) async {
      final auth = await pumpGoogle(tester);
      expect(find.text('สมัครพร้อมเชื่อมบัญชี Google'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
      await tester.pumpAndSettle();

      expect(auth.tickets, ['tkt']);
      expect(auth.calls.single['name'], 'ครูใหม่ ใจดี');
      expect(auth.calls.single['email'], 'new@school.ac.th');
      expect(
        find.textContaining('ปุ่ม "เข้าสู่ระบบด้วย Google"'),
        findsOneWidget,
      );
    });

    testWidgets('an expired ticket can be dropped to register without Google', (
      tester,
    ) async {
      final auth = await pumpGoogle(tester);
      auth.error = _apiError(422, 'link_ticket_invalid');
      await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
      await tester.pumpAndSettle();
      expect(find.textContaining('สมัครโดยไม่เชื่อม Google'), findsOneWidget);
      expect(find.text('สมัครพร้อมเชื่อมบัญชี Google'), findsNothing);

      await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
      await tester.pumpAndSettle();
      expect(auth.tickets, ['tkt', null]);
      expect(find.text('สมัครสำเร็จ'), findsOneWidget);
    });
  });
}

DioException _apiError(int status, String code, {String message = 'x'}) {
  final options = RequestOptions(path: '/auth/teacher/register');
  return DioException(
    requestOptions: options,
    type: DioExceptionType.badResponse,
    response: Response(
      requestOptions: options,
      statusCode: status,
      data: {'message': message, 'errors': <String, dynamic>{}, 'code': code},
    ),
  );
}
