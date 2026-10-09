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

const _one = [SchoolOption(id: 7, name: 'โรงเรียนสาธิต Krucheck')];
const _two = [
  SchoolOption(id: 7, name: 'โรงเรียนสาธิต Krucheck'),
  SchoolOption(id: 9, name: 'โรงเรียนวัดใหม่'),
];

class _FakeAuth extends Fake implements AuthRepository {
  _FakeAuth({this.schoolList = _one});

  List<SchoolOption> schoolList;
  Object? schoolsError;
  int schoolLoads = 0;
  final calls = <Map<String, Object?>>[];
  final tickets = <String?>[];
  Object? error;

  @override
  Future<List<SchoolOption>> schools() async {
    schoolLoads++;
    if (schoolsError != null) {
      final e = schoolsError!;
      schoolsError = null;
      throw e;
    }
    return schoolList;
  }

  @override
  Future<void> register({
    int? schoolId,
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
      'school_id': schoolId,
      'name': name,
      'email': email,
      'password': password,
    });
  }
}

Future<_FakeAuth> _pump(WidgetTester tester, _FakeAuth auth) async {
  tester.view.physicalSize = const Size(800, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await pumpScreen(
    tester,
    const RegisterScreen(),
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
}

Future<void> _submit(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('has no school code field and validates locally first', (
    tester,
  ) async {
    final auth = await _pump(tester, _FakeAuth());
    expect(find.textContaining('รหัสโรงเรียน'), findsNothing);
    expect(find.textContaining('school_code'), findsNothing);
    expect(find.byType(TextFormField), findsNWidgets(3));

    await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
    await tester.pump();
    expect(find.text('กรอกชื่อ'), findsOneWidget);
    expect(find.text('กรอกอีเมลให้ถูกต้อง'), findsOneWidget);
    expect(find.text('รหัสผ่านต้องยาวอย่างน้อย 8 ตัว'), findsOneWidget);
    expect(auth.calls, isEmpty);
  });

  testWidgets(
    'one school: its name as plain text, sent as school_id, then the pending-approval message',
    (tester) async {
      final auth = await _pump(tester, _FakeAuth());
      expect(find.byKey(const ValueKey('register_school_single')), findsOne);
      expect(find.text('โรงเรียนสาธิต Krucheck'), findsOneWidget);
      expect(find.byType(DropdownButtonFormField<int>), findsNothing);

      await _fillTeacher(tester);
      await _submit(tester);

      expect(auth.calls, [
        {
          'school_id': 7,
          'name': 'ครูสมศรี ใจดี',
          'email': 'somsri@example.com',
          'password': 'secret-pass-1',
        },
      ]);
      expect(find.text('สมัครสำเร็จ'), findsOneWidget);
      expect(
        find.textContaining('รอผู้ดูแลโรงเรียนอนุมัติ'),
        findsOneWidget,
        reason: 'new accounts are pending until a school admin approves',
      );

      await tester.tap(find.text('รับทราบ'));
      await tester.pumpAndSettle();
      expect(find.text('login-stub'), findsOneWidget);
    },
  );

  testWidgets('several schools: a required dropdown, the pick is sent', (
    tester,
  ) async {
    final auth = await _pump(tester, _FakeAuth(schoolList: _two));
    expect(find.byKey(const ValueKey('register_school')), findsOneWidget);
    expect(find.byKey(const ValueKey('register_school_single')), findsNothing);

    await _fillTeacher(tester);
    await _submit(tester);
    expect(find.text('กรุณาเลือกโรงเรียน'), findsOneWidget);
    expect(auth.calls, isEmpty);

    await tester.tap(find.byKey(const ValueKey('register_school')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('โรงเรียนวัดใหม่').last);
    await tester.pumpAndSettle();
    await _submit(tester);

    expect(auth.calls.single['school_id'], 9);
    expect(find.text('สมัครสำเร็จ'), findsOneWidget);
  });

  testWidgets('a failed school list can be retried; sign-up still works', (
    tester,
  ) async {
    final auth = _FakeAuth()..schoolsError = _apiError(500, 'server_error');
    await _pump(tester, auth);
    expect(find.textContaining('โหลดรายชื่อโรงเรียนไม่สำเร็จ'), findsOneWidget);

    await tester.tap(find.text('ลองอีกครั้ง'));
    await tester.pumpAndSettle();
    expect(auth.schoolLoads, 2);
    expect(find.text('โรงเรียนสาธิต Krucheck'), findsOneWidget);
  });

  testWidgets('without a list the server picks; school_required reloads it', (
    tester,
  ) async {
    final auth = _FakeAuth()..schoolsError = _apiError(500, 'server_error');
    await _pump(tester, auth);
    await _fillTeacher(tester);
    auth
      ..schoolList = _two
      ..error = _apiError(
        422,
        'school_required',
        message: 'กรุณาเลือกโรงเรียน',
      );
    await _submit(tester);

    expect(auth.tickets, [null], reason: 'sent without a school_id');
    expect(find.text('กรุณาเลือกโรงเรียน'), findsOneWidget);
    expect(auth.schoolLoads, 2);
    expect(find.byKey(const ValueKey('register_school')), findsOneWidget);
  });

  testWidgets('no school at all is explained', (tester) async {
    await _pump(tester, _FakeAuth(schoolList: const []));
    expect(find.textContaining('ยังไม่มีโรงเรียนในระบบ'), findsOneWidget);
  });

  group('from a Google sign-in (DESIGN §24.9.5)', () {
    const google = GoogleRegistration(
      linkTicket: 'tkt',
      name: 'ครูใหม่ ใจดี',
      email: 'new@school.ac.th',
    );

    Future<_FakeAuth> pumpGoogle(WidgetTester tester) async {
      tester.view.physicalSize = const Size(800, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final auth = _FakeAuth(schoolList: _two);
      await pumpScreen(
        tester,
        const RegisterScreen(google: google),
        overrides: [
          authRepositoryProvider.overrideWithValue(auth),
          tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
        ],
        extraRoutes: [
          GoRoute(path: '/login', builder: (_, _) => const Text('login-stub')),
        ],
      );
      await tester.tap(find.byKey(const ValueKey('register_school')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('โรงเรียนวัดใหม่').last);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextFormField, 'รหัสผ่าน (อย่างน้อย 8 ตัว)'),
        'secret-pass-1',
      );
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
      expect(auth.calls.single['school_id'], 9);
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

    testWidgets('a domain the school does not allow is explained', (
      tester,
    ) async {
      final auth = await pumpGoogle(tester);
      auth.error = _apiError(403, 'google_domain_not_allowed');
      await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
      await tester.pumpAndSettle();
      expect(find.textContaining('โดเมนนี้'), findsOneWidget);
    });
  });
}

DioException _apiError(int status, String code, {String message = 'x'}) {
  final options = RequestOptions(path: '/auth/teacher/register');
  return DioException(
    requestOptions: options,
    response: Response(
      requestOptions: options,
      statusCode: status,
      data: {'message': message, 'errors': <String, dynamic>{}, 'code': code},
    ),
  );
}
