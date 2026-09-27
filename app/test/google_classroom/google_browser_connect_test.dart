import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/assignment_google_section.dart';
import 'package:eduvision/features/google_classroom/classroom_google_section.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_classroom/google_browser_connect.dart';
import 'package:eduvision/features/google_classroom/google_config.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:eduvision/features/settings/settings_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';
import 'google_fakes.dart';

const _connected = GoogleStatus(connected: true, email: 'kru@school.ac.th');

/// Records the pages the app asked to open outside itself.
class _Opener {
  _Opener({this.result = true});

  bool result;
  final opened = <Uri>[];

  Future<bool> call(Uri url) async {
    opened.add(url);
    return result;
  }
}

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [];
}

class _Assignments extends Fake implements AssignmentsRepository {}

/// The settings page with the REAL visibility rule (no override of
/// googleClassroomEnabledProvider): only the fake server's `configured`
/// decides, and this test build has no GOOGLE_SERVER_CLIENT_ID.
Future<void> _pumpSettings(
  WidgetTester tester, {
  required FakeGoogleRepository google,
  FakeGoogleAuth? auth,
  _Opener? opener,
}) async {
  tester.view.physicalSize = const Size(1000, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await pumpScreen(
    tester,
    const SettingsScreen(),
    overrides: [
      aiKeyRepositoryProvider.overrideWithValue(
        FakeAiKeyRepository(const AiKeyStatus(configured: false)),
      ),
      googleClassroomRepositoryProvider.overrideWithValue(google),
      googleAuthProvider.overrideWithValue(
        auth ?? FakeGoogleAuth(native: false),
      ),
      externalUrlOpenerProvider.overrideWithValue((opener ?? _Opener()).call),
    ],
  );
}

final _card = find.byKey(const ValueKey('google_classroom_card'));
final _dialog = find.byKey(const ValueKey('google_browser_connect_dialog'));

Finder _inCard(Finder matching) =>
    find.descendant(of: _card, matching: matching);

/// Taps "เชื่อม Google Classroom" and lets the dialog open (it animates a
/// progress bar, so never pumpAndSettle while it is up).
Future<void> _tapConnect(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('google_connect')));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 300));
}

void main() {
  test('this test build has no native Google Sign-In', () {
    expect(googleServerClientId, isEmpty);
    expect(googleNativeSignInBuild, isFalse);
  });

  group('visibility follows GET /google/status configured', () {
    testWidgets('settings card shows without GOOGLE_SERVER_CLIENT_ID', (
      tester,
    ) async {
      await _pumpSettings(
        tester,
        google: FakeGoogleRepository(statusValue: GoogleStatus.disconnected),
      );
      expect(_card, findsOneWidget);
      expect(_inCard(find.text('ยังไม่เชื่อม')), findsOneWidget);
      expect(find.byKey(const ValueKey('google_connect')), findsOneWidget);
    });

    testWidgets('a server without the OAuth client hides the card', (
      tester,
    ) async {
      await _pumpSettings(
        tester,
        google: FakeGoogleRepository(
          statusValue: const GoogleStatus(connected: false, configured: false),
        ),
      );
      expect(_card, findsNothing);
      expect(find.text('Gemini API key'), findsOneWidget);
    });

    testWidgets('an unreadable status hides the card in this build', (
      tester,
    ) async {
      final google = FakeGoogleRepository()
        ..error = DioException(
          requestOptions: RequestOptions(path: '/google/status'),
          response: Response(
            requestOptions: RequestOptions(path: '/google/status'),
            statusCode: 404,
          ),
        );
      await _pumpSettings(tester, google: google);
      expect(_card, findsNothing);
    });

    test('the provider: build flag while loading, then the server', () async {
      final google = FakeGoogleRepository(
        statusValue: const GoogleStatus(connected: false, configured: false),
      );
      final container = ProviderContainer(
        overrides: [
          googleClassroomRepositoryProvider.overrideWithValue(google),
        ],
      );
      addTearDown(container.dispose);
      final sub = container.listen(googleClassroomEnabledProvider, (_, _) {});
      expect(sub.read(), googleNativeSignInBuild, reason: 'still loading');
      await container.read(googleStatusProvider.future);
      expect(sub.read(), isFalse);

      google.statusValue = const GoogleStatus(connected: false);
      container.invalidate(googleStatusProvider);
      await container.read(googleStatusProvider.future);
      expect(sub.read(), isTrue);
    });

    testWidgets('classroom and assignment sections follow the same rule', (
      tester,
    ) async {
      Future<void> pump(bool configured) async {
        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              googleClassroomRepositoryProvider.overrideWithValue(
                FakeGoogleRepository(
                  statusValue: GoogleStatus(
                    connected: true,
                    email: 'kru@school.ac.th',
                    configured: configured,
                  ),
                ),
              ),
              classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
              assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
            ],
            child: MaterialApp(
              home: Scaffold(
                body: ListView(
                  children: const [
                    ClassroomGoogleSection(
                      classroom: Classroom(
                        id: 7,
                        name: 'ป.5/1',
                        gradeLevel: 5,
                        academicYear: 2569,
                        classCode: 'ABC123',
                      ),
                    ),
                    AssignmentGoogleSection(
                      assignment: Assignment(
                        id: 12,
                        classroomId: 7,
                        subjectId: 1,
                        title: 'เศษส่วน',
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
      }

      await pump(true);
      expect(
        find.byKey(const ValueKey('classroom_google_section')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('assignment_google_section')),
        findsOneWidget,
      );

      await pump(false);
      expect(
        find.byKey(const ValueKey('classroom_google_section')),
        findsNothing,
      );
      expect(
        find.byKey(const ValueKey('assignment_google_section')),
        findsNothing,
      );
    });
  });

  group('browser connect flow (no native sign-in)', () {
    testWidgets('opens Google, polls every 3 s and closes once connected', (
      tester,
    ) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      final auth = FakeGoogleAuth(native: false);
      final opener = _Opener();
      await _pumpSettings(tester, google: google, auth: auth, opener: opener);
      final callsBefore = google.statusCalls;

      await _tapConnect(tester);

      expect(google.oauthUrls, 1);
      expect(opener.opened, [
        Uri.parse('https://accounts.google.com/o/oauth2/v2/auth?state=s1'),
      ]);
      expect(auth.serverCodes, 0, reason: 'no native sign-in');
      expect(_dialog, findsOneWidget);
      expect(
        find.text('ทำขั้นตอนในหน้าต่าง Google ให้เสร็จ แล้วกดตรวจสอบ'),
        findsOneWidget,
      );
      expect(find.text('ตรวจสอบการเชื่อม'), findsOneWidget);

      // Two polls while the teacher is still on Google's page.
      await tester.pump(const Duration(seconds: 3));
      await tester.pump(const Duration(seconds: 3));
      expect(google.statusCalls, callsBefore + 2);
      expect(_dialog, findsOneWidget);

      // The server finished the connection (GET /google/oauth/callback).
      google.statusValue = _connected;
      await tester.pump(const Duration(seconds: 3));
      await tester.pumpAndSettle();

      expect(_dialog, findsNothing);
      expect(google.statusCalls, callsBefore + 3);
      expect(_inCard(find.text('เชื่อมแล้ว')), findsOneWidget);
      expect(_inCard(find.text('บัญชี: kru@school.ac.th')), findsOneWidget);
      expect(find.byKey(const ValueKey('google_connect')), findsNothing);
      expect(
        find.textContaining('เชื่อม Google Classroom กับ kru@'),
        findsOneWidget,
      );
      expect(google.connected, isEmpty, reason: 'POST /google/connect unused');

      // Polling stopped with the dialog.
      await tester.pump(const Duration(seconds: 30));
      expect(google.statusCalls, callsBefore + 3);
    });

    testWidgets('"ตรวจสอบการเชื่อม" checks at once', (tester) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      await _pumpSettings(tester, google: google);
      await _tapConnect(tester);

      await tester.tap(find.byKey(const ValueKey('google_browser_check')));
      await tester.pump();
      expect(find.textContaining('ยังไม่พบการเชื่อม'), findsOneWidget);
      expect(_dialog, findsOneWidget);

      google.statusValue = _connected;
      await tester.tap(find.byKey(const ValueKey('google_browser_check')));
      await tester.pump();
      await tester.pumpAndSettle();
      expect(_dialog, findsNothing);
      expect(_inCard(find.text('เชื่อมแล้ว')), findsOneWidget);
    });

    testWidgets('a reconnect waits until needs_reconnect is gone', (
      tester,
    ) async {
      final google = FakeGoogleRepository(
        statusValue: const GoogleStatus(
          connected: true,
          email: 'kru@school.ac.th',
          needsReconnect: true,
        ),
      );
      await _pumpSettings(tester, google: google);
      expect(_inCard(find.text('ต้องเชื่อมใหม่')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('google_connect')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      await tester.pump(const Duration(seconds: 3));
      expect(_dialog, findsOneWidget, reason: 'still the expired grant');

      google.statusValue = _connected;
      await tester.pump(const Duration(seconds: 3));
      await tester.pumpAndSettle();
      expect(_dialog, findsNothing);
      expect(_inCard(find.text('เชื่อมแล้ว')), findsOneWidget);
    });

    testWidgets('stops polling after the time limit and says so', (
      tester,
    ) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      await _pumpSettings(tester, google: google);
      await _tapConnect(tester);
      final callsBefore = google.statusCalls;

      // 3 minutes at one poll every 3 seconds.
      for (var i = 0; i < 60; i++) {
        await tester.pump(const Duration(seconds: 3));
      }
      expect(google.statusCalls, callsBefore + 60);
      expect(find.textContaining('ยังไม่พบการเชื่อมบัญชี'), findsOneWidget);
      expect(
        find.descendant(
          of: _dialog,
          matching: find.byType(LinearProgressIndicator),
        ),
        findsNothing,
      );

      await tester.pump(const Duration(minutes: 5));
      expect(google.statusCalls, callsBefore + 60, reason: 'no more polls');

      // The teacher can still check by hand.
      google.statusValue = _connected;
      await tester.tap(find.byKey(const ValueKey('google_browser_check')));
      await tester.pump();
      await tester.pumpAndSettle();
      expect(_dialog, findsNothing);
      expect(_inCard(find.text('เชื่อมแล้ว')), findsOneWidget);
    });

    testWidgets('cancel closes the dialog and stops polling', (tester) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      await _pumpSettings(tester, google: google);
      await _tapConnect(tester);

      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(_dialog, findsNothing);
      final calls = google.statusCalls;
      await tester.pump(const Duration(seconds: 30));
      expect(google.statusCalls, calls);
      expect(_inCard(find.text('ยังไม่เชื่อม')), findsOneWidget);
      expect(find.byType(SnackBar), findsNothing);
    });

    testWidgets('a blocked page is explained and can be opened again', (
      tester,
    ) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      final opener = _Opener(result: false);
      await _pumpSettings(tester, google: google, opener: opener);
      await _tapConnect(tester);
      expect(find.textContaining('เปิดหน้าต่าง Google ไม่ได้'), findsOneWidget);

      opener.result = true;
      await tester.tap(find.byKey(const ValueKey('google_browser_reopen')));
      await tester.pump();
      expect(google.oauthUrls, 2, reason: 'a fresh state for the new page');
      expect(opener.opened.last.queryParameters['state'], 's2');
      expect(find.byKey(const ValueKey('google_browser_note')), findsNothing);
    });

    testWidgets('a failed url request is explained without a dialog', (
      tester,
    ) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      final opener = _Opener();
      await _pumpSettings(tester, google: google, opener: opener);
      google.error = DioException(
        requestOptions: RequestOptions(path: '/google/oauth/url'),
        response: Response(
          requestOptions: RequestOptions(path: '/google/oauth/url'),
          statusCode: 503,
          data: {
            'message': 'x',
            'errors': <String, dynamic>{},
            'code': 'google_not_configured',
          },
        ),
      );
      await tester.tap(find.byKey(const ValueKey('google_connect')));
      await tester.pumpAndSettle();
      expect(_dialog, findsNothing);
      expect(opener.opened, isEmpty);
      expect(
        find.text(
          'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่า Google Classroom กรุณาแจ้งผู้ดูแลระบบ',
        ),
        findsOneWidget,
      );
    });

    testWidgets('a device with native sign-in keeps POST /google/connect', (
      tester,
    ) async {
      final google = FakeGoogleRepository(
        statusValue: GoogleStatus.disconnected,
      );
      final auth = FakeGoogleAuth();
      final opener = _Opener();
      await _pumpSettings(tester, google: google, auth: auth, opener: opener);
      await tester.tap(find.byKey(const ValueKey('google_connect')));
      await tester.pumpAndSettle();
      expect(auth.serverCodes, 1);
      expect(google.connected, ['4/0server-code']);
      expect(google.oauthUrls, 0);
      expect(opener.opened, isEmpty);
      expect(_dialog, findsNothing);
    });
  });
}
