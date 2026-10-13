import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/widgets/impersonation_banner.dart';
import 'package:eduvision/features/auth/impersonate_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../google_signin/google_signin_fakes.dart';
import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';

class _Auth extends Fake implements AuthRepository {
  Object? error;
  final tickets = <String>[];
  int logouts = 0;

  @override
  Future<String> redeemImpersonation(String ticket) async {
    tickets.add(ticket);
    if (error case final e?) throw e;
    return 'imp-token';
  }

  @override
  Future<User> me() async => const User(
    id: 4,
    name: 'ครูสมศรี',
    role: 'teacher',
    impersonatedBy: 'แอดมินใหญ่',
  );

  @override
  Future<void> logout() async => logouts++;
}

/// DESIGN §29.14: an admin signed in as a user.
void main() {
  test('impersonated_by round-trips through the user payload', () {
    final user = User.fromJson({
      'id': 4,
      'name': 'ครูสมศรี',
      'role': 'teacher',
      'impersonated_by': {'id': 1, 'name': 'แอดมินใหญ่'},
    });
    expect(user.impersonatedBy, 'แอดมินใหญ่');
    expect(User.fromJson(user.toJson()).impersonatedBy, 'แอดมินใหญ่');
    expect(
      User.fromJson({
        'id': 4,
        'name': 'x',
        'role': 'teacher',
        'impersonated_by': null,
      }).impersonatedBy,
      isNull,
    );
  });

  test('the ticket is redeemed at POST /auth/impersonate/ticket', () async {
    final adapter = FakeHttpAdapter(
      (o) async => jsonResponse(200, {
        'token': 'imp-token',
        'user': {'id': 4},
      }),
    );
    final token = await ApiAuthRepository(
      fakeDio(adapter),
    ).redeemImpersonation('t' * 48);
    expect(token, 'imp-token');
    expect(adapter.requests.single.path, '/auth/impersonate/ticket');
    expect(adapter.requests.single.data, {'ticket': 't' * 48});
  });

  testWidgets('the screen signs the admin in as the user', (tester) async {
    final auth = _Auth();
    final container = await pumpScreen(
      tester,
      const ImpersonateScreen(ticket: 'abc'),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      ],
    );
    await tester.pumpAndSettle();
    expect(auth.tickets, ['abc']);
    final session = container.read(sessionProvider);
    expect(session, isA<SignedIn>());
    expect((session as SignedIn).user.impersonatedBy, 'แอดมินใหญ่');
  });

  testWidgets('a spent ticket is explained', (tester) async {
    final auth = _Auth()
      ..error = apiError(
        422,
        'impersonation_ticket_invalid',
        message: 'ลิงก์เข้าใช้แทนใช้ไม่ได้แล้ว',
      );
    await pumpScreen(
      tester,
      const ImpersonateScreen(ticket: 'old'),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      ],
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('impersonate_message')), findsOneWidget);
    expect(find.text('ลิงก์เข้าใช้แทนใช้ไม่ได้แล้ว'), findsOneWidget);
    expect(find.text('กลับไปหน้าเข้าสู่ระบบ'), findsOneWidget);
  });

  testWidgets('the banner names both people and signs out', (tester) async {
    final auth = _Auth();
    final container = await pumpScreen(
      tester,
      const _SignedIn(
        child: Scaffold(body: Column(children: [ImpersonationBanner()])),
      ),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      ],
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('impersonation_banner')), findsOneWidget);
    expect(
      find.text('กำลังเข้าใช้ในฐานะ ครูสมศรี โดยผู้ดูแลระบบ แอดมินใหญ่'),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('impersonation_exit')));
    await tester.pumpAndSettle();
    // The token is revoked on the server; the local wipe that follows (sign_out_test) is not pumped here.
    expect(auth.logouts, 1);
    expect(container.read(sessionProvider), isA<SignedIn>());
  });
}

/// Signs in through a ticket before showing [child].
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
        .signInWithToken(
          ref.read(authRepositoryProvider).redeemImpersonation('abc'),
        )
        .then((_) {
          if (mounted) setState(() => _ready = true);
        });
  }

  @override
  Widget build(BuildContext context) =>
      _ready ? widget.child : const SizedBox.shrink();
}
