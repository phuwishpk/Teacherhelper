import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/auth/login_screen.dart';
import 'package:eduvision/features/auth/student_login_form.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';

class _FakeAuth extends Fake implements AuthRepository {
  _FakeAuth({this.pinError});

  final Object? pinError;
  final calls = <Map<String, Object>>[];

  @override
  Future<String> loginStudentPin({
    required String classCode,
    required int studentNumber,
    required String pin,
  }) async {
    calls.add({
      'class_code': classCode,
      'student_number': studentNumber,
      'pin': pin,
    });
    if (pinError != null) throw pinError!;
    return 'student-token';
  }

  @override
  Future<User> me() async =>
      const User(id: 4567, name: 'ด.ญ. สมหญิง', role: 'student');
}

DioException _error(int status, Map<String, Object?> body) {
  final req = RequestOptions(path: '/auth/student/pin');
  return DioException(
    requestOptions: req,
    response: Response(requestOptions: req, statusCode: status, data: body),
  );
}

DioException _lockedError() => _error(423, {
  'message': 'ล็อก 15 นาที',
  'errors': {},
  'code': 'pin_locked',
});

Future<void> _tapLogin(WidgetTester tester) async {
  final button = find.widgetWithText(FilledButton, 'เข้าสู่ระบบ');
  await tester.ensureVisible(button);
  await tester.pumpAndSettle();
  await tester.tap(button);
}

Future<void> _fill(WidgetTester tester) async {
  await tester.enterText(
    find.widgetWithText(TextFormField, 'รหัสห้อง'),
    'abc123',
  );
  await tester.enterText(find.widgetWithText(TextFormField, 'เลขที่'), '12');
  await tester.enterText(
    find.widgetWithText(TextFormField, 'PIN 6 หลัก'),
    '123456',
  );
  await _tapLogin(tester);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('validates the PIN form locally', (tester) async {
    final auth = _FakeAuth();
    await pumpScreen(
      tester,
      const LoginScreen(initialTab: LoginTab.student),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      ],
    );
    await _tapLogin(tester);
    await tester.pump();
    expect(find.text('รหัสห้องมี 6 ตัวอักษร'), findsOneWidget);
    expect(find.text('กรอกเลขที่'), findsOneWidget);
    expect(find.text('PIN ต้องมี 6 หลัก'), findsOneWidget);
    expect(auth.calls, isEmpty);
  });

  testWidgets('signs the student in with class code, number and PIN', (
    tester,
  ) async {
    final auth = _FakeAuth();
    final storage = InMemoryTokenStorage();
    final container = await pumpScreen(
      tester,
      const LoginScreen(initialTab: LoginTab.student),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(storage),
      ],
    );
    await _fill(tester);

    expect(auth.calls, [
      {'class_code': 'ABC123', 'student_number': 12, 'pin': '123456'},
    ]);
    expect(await storage.read(), 'student-token');
    final session = container.read(sessionProvider);
    expect(session, isA<SignedIn>());
    expect((session as SignedIn).user.isStudent, isTrue);
  });

  testWidgets('the PIN tab posts to the student endpoint, then reads /me', (
    tester,
  ) async {
    final adapter = FakeHttpAdapter(
      (o) async => o.path == '/me'
          ? jsonResponse(200, {
              'data': {'id': 4567, 'name': 'ด.ญ. สมหญิง', 'role': 'student'},
            })
          : jsonResponse(200, {'token': 'student-token'}),
    );
    final storage = InMemoryTokenStorage();
    await pumpScreen(
      tester,
      const LoginScreen(initialTab: LoginTab.student),
      overrides: [
        authRepositoryProvider.overrideWithValue(
          ApiAuthRepository(fakeDio(adapter)),
        ),
        tokenStorageProvider.overrideWithValue(storage),
      ],
    );
    await _fill(tester);

    final login = adapter.requests.first;
    expect(login.method, 'POST');
    expect(login.path, '/auth/student/pin');
    expect(login.data, {
      'class_code': 'ABC123',
      'student_number': 12,
      'pin': '123456',
    });
    expect(
      adapter.requests.map((r) => r.path),
      isNot(contains('/auth/teacher/login')),
    );
    expect(await storage.read(), 'student-token');
  });

  group('studentPinErrorMessage', () {
    test('423 uses the remaining time the server sends', () {
      final message = studentPinErrorMessage(
        _error(423, {
          'message': 'ใส่ PIN ผิดหลายครั้ง ระบบล็อกชั่วคราว 15 นาที',
          'errors': {
            'pin': ['ล็อกชั่วคราว ลองใหม่ในอีก 12 นาที'],
          },
          'code': 'pin_locked',
        }),
      );
      expect(message, contains('ลองใหม่ในอีก 12 นาที'));
      expect(message, contains('ให้ครูรีเซ็ต PIN'));
      expect(message, isNot(contains('15 นาที')));
    });

    test('429 from the per-IP limiter is not a PIN lockout', () {
      final message = studentPinErrorMessage(
        _error(429, {'message': 'Too Many Attempts.'}),
      );
      expect(message, 'มีการเข้าสู่ระบบถี่เกินไป รอสักครู่แล้วลองใหม่');
      expect(message, isNot(contains('ล็อก')));
      expect(message, isNot(contains('รีเซ็ต PIN')));
    });

    test('422 invalid_credentials is a wrong code / number / PIN', () {
      expect(
        studentPinErrorMessage(
          _error(422, {
            'message': 'x',
            'errors': {},
            'code': 'invalid_credentials',
          }),
        ),
        'รหัสห้อง เลขที่ หรือ PIN ไม่ถูกต้อง',
      );
    });
  });

  testWidgets('shows the lockout message on 423 pin_locked', (tester) async {
    final auth = _FakeAuth(pinError: _lockedError());
    final container = await pumpScreen(
      tester,
      const LoginScreen(initialTab: LoginTab.student),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      ],
    );
    await _fill(tester);

    expect(find.textContaining('ล็อกชั่วคราว 15 นาที'), findsOneWidget);
    expect(container.read(sessionProvider), isNot(isA<SignedIn>()));
  });
}
