import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/admin/admin_home_screen.dart';
import 'package:eduvision/features/admin/admin_repository.dart';
import 'package:eduvision/features/google_classroom/google_browser_connect.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

class _FakeAdmin implements AdminRepository {
  _FakeAdmin({this.error});

  final Object? error;
  int calls = 0;

  @override
  Future<AdminHandoff> handoff() async {
    calls++;
    if (error != null) throw error!;
    return AdminHandoff(
      url: Uri.parse('https://eduvision.test/admin/handoff/${'a' * 48}'),
      expiresAt: DateTime.utc(2026, 10, 1, 3, 1),
    );
  }
}

class _FakeAuth extends Fake implements AuthRepository {
  int logouts = 0;

  @override
  Future<User> me() async =>
      const User(id: 2, name: 'ผู้ดูแลระบบ', role: 'admin');

  @override
  Future<void> logout() async => logouts++;
}

void main() {
  late List<Uri> opened;

  Future<_FakeAuth> pump(
    WidgetTester tester, {
    required AdminRepository admin,
    bool opens = true,
  }) async {
    opened = [];
    final auth = _FakeAuth();
    final container = await pumpScreen(
      tester,
      const AdminHomeScreen(),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(
          InMemoryTokenStorage(token: 'admin-token'),
        ),
        adminRepositoryProvider.overrideWithValue(admin),
        externalUrlOpenerProvider.overrideWithValue((url) async {
          opened.add(url);
          return opens;
        }),
      ],
    );
    await container.read(sessionProvider.notifier).restore();
    await tester.pumpAndSettle();
    return auth;
  }

  testWidgets('greets the admin and opens the panel with a one-time link', (
    tester,
  ) async {
    final admin = _FakeAdmin();
    await pump(tester, admin: admin);

    expect(find.text('สวัสดี คุณผู้ดูแลระบบ'), findsOneWidget);
    expect(find.textContaining('ทำในหน้าเว็บผู้ดูแลระบบ'), findsOneWidget);

    await tester.tap(find.text('เปิดหน้าผู้ดูแลระบบ'));
    await tester.pumpAndSettle();

    expect(admin.calls, 1);
    expect(opened, [
      Uri.parse('https://eduvision.test/admin/handoff/${'a' * 48}'),
    ]);
  });

  testWidgets('says so when the browser cannot be opened', (tester) async {
    await pump(tester, admin: _FakeAdmin(), opens: false);

    await tester.tap(find.text('เปิดหน้าผู้ดูแลระบบ'));
    await tester.pumpAndSettle();
    expect(find.textContaining('เปิดเบราว์เซอร์ไม่ได้'), findsOneWidget);
  });

  testWidgets('shows the API error of a failed handoff', (tester) async {
    final request = RequestOptions(path: '/auth/admin-handoff');
    await pump(
      tester,
      admin: _FakeAdmin(
        error: DioException(
          requestOptions: request,
          response: Response(
            requestOptions: request,
            statusCode: 429,
            data: {
              'message': 'มีการเรียกใช้ถี่เกินไป',
              'errors': {},
              'code': 'too_many_requests',
            },
          ),
        ),
      ),
    );

    await tester.tap(find.text('เปิดหน้าผู้ดูแลระบบ'));
    await tester.pumpAndSettle();
    expect(find.text('มีการเรียกใช้ถี่เกินไป'), findsOneWidget);
    expect(opened, isEmpty);
  });

  testWidgets('signs out', (tester) async {
    final auth = await pump(tester, admin: _FakeAdmin());

    await tester.tap(find.text('ออกจากระบบ'));
    await tester.pumpAndSettle();
    expect(auth.logouts, 1);
  });

  test('parses the handoff payload', () {
    final handoff = AdminHandoff.fromJson({
      'url': 'https://eduvision.test/admin/handoff/abc',
      'expires_at': '2026-10-01T03:01:00Z',
    });
    expect(handoff.url.path, '/admin/handoff/abc');
    expect(handoff.expiresAt, DateTime.utc(2026, 10, 1, 3, 1));
  });
}
