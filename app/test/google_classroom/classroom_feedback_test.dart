import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/google_classroom/assignment_google_section.dart';
import 'package:eduvision/features/google_classroom/classroom_feedback_screen.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_classroom/google_browser_connect.dart';
import 'package:eduvision/features/google_classroom/google_classroom_card.dart';
import 'package:eduvision/features/google_classroom/google_config.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_reconnect_banner.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/home/teacher_attention.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';
import 'google_fakes.dart';

/// Phase 8 build step 6, app side (DESIGN §19.7, §19.9): the private
/// result announcements per student with "ส่งประกาศอีกครั้ง", the
/// reconnect prompt for the new announcements scope, and "เปิดใน
/// Classroom" / "คัดลอกคะแนน" for courseWork created on the website.
/// Nothing here reaches Google.

const _allScopes = [...googleServerScopes];
final _oldScopes = [
  for (final s in googleServerScopes)
    if (s != announcementsScope) s,
];

const _link = 'https://classroom.google.com/c/abc/a/9001/details';

class _Assignments extends Fake implements AssignmentsRepository {
  _Assignments({this.web = false});

  final bool web;

  @override
  Future<Assignment> get(int id) async => Assignment(
    id: id,
    classroomId: 7,
    subjectId: 1,
    title: 'เศษส่วน',
    googleLink: AssignmentGoogleLink(
      courseWorkId: '9001',
      alternateLink: _link,
      origin: web
          ? AssignmentGoogleLink.originClassroomWeb
          : AssignmentGoogleLink.originApp,
    ),
  );
}

class _Opener {
  final opened = <Uri>[];

  Future<bool> call(Uri url) async {
    opened.add(url);
    return true;
  }
}

List<String> _mockClipboard(WidgetTester tester) {
  final copied = <String>[];
  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    SystemChannels.platform,
    (call) async {
      if (call.method == 'Clipboard.setData') {
        copied.add((call.arguments as Map)['text'] as String);
      }
      return null;
    },
  );
  addTearDown(
    () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      null,
    ),
  );
  return copied;
}

SubmissionStudent _student(int id, int number) =>
    SubmissionStudent(id: id, name: 'นักเรียน $number', studentNumber: number);

List<ClassroomFeedbackPost> _rows() => [
  ClassroomFeedbackPost(
    id: 1,
    submissionId: 71,
    state: FeedbackPostState.posted,
    student: _student(4567, 1),
    publishedAt: DateTime.utc(2026, 9, 30, 2),
    postedAt: DateTime.utc(2026, 9, 30, 2, 1),
    announcementId: 'ann-1',
  ),
  ClassroomFeedbackPost(
    id: 2,
    submissionId: 72,
    state: FeedbackPostState.failed,
    student: _student(4568, 2),
    publishedAt: DateTime.utc(2026, 9, 30, 2),
    lastError: 'นักเรียนยังไม่ได้จับคู่บัญชี Google',
  ),
  ClassroomFeedbackPost(
    id: 3,
    submissionId: 73,
    state: FeedbackPostState.queued,
    student: _student(4569, 3),
    publishedAt: DateTime.utc(2026, 9, 30, 2),
  ),
];

Map<String, dynamic> _summary(
  int id,
  int studentId,
  int number,
  String status,
  double total,
) => {
  'id': id,
  'status': status,
  'student': {
    'id': studentId,
    'name': 'นักเรียน $number',
    'student_number': number,
  },
  'response_count': 2,
  'reviewed_count': 2,
  'total_score': total,
};

Map<String, dynamic> _meta() => {
  'submissions': [
    _summary(71, 4567, 1, 'published', 9.5),
    _summary(72, 4568, 2, 'published', 6),
    _summary(73, 4569, 3, 'published', 7),
    // Handed in through the app, no announcement row (not matched).
    _summary(74, 4570, 4, 'published', 8),
    _summary(75, 4571, 5, 'needs_review', 3),
  ],
};

void main() {
  group('API', () {
    ApiGoogleClassroomRepository repoWith(FakeHttpAdapter adapter) =>
        ApiGoogleClassroomRepository(fakeDio(adapter));

    test('feedback reads GET /assignments/{id}/google-feedback', () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(200, {
          'data': [
            {
              'id': 9,
              'submission_id': 40,
              'student': {
                'id': 4567,
                'name': 'ด.ช. สมชาย',
                'student_number': 12,
              },
              'published_at': '2026-09-30T02:00:00+00:00',
              'state': 'failed',
              'last_error': 'ต้องเชื่อมบัญชี Google ใหม่',
              'posted_at': null,
              'announcement_id': null,
            },
            {
              'id': 10,
              'submission_id': 41,
              'student': {'id': null, 'name': null, 'student_number': null},
              'published_at': '2026-09-30T02:00:00+00:00',
              'state': 'posted',
              'last_error': null,
              'posted_at': '2026-09-30T02:03:00+00:00',
              'announcement_id': 12345,
            },
          ],
        }),
      );
      final rows = await repoWith(adapter).feedback(12);
      final req = adapter.requests.single;
      expect(req.method, 'GET');
      expect(req.uri.path, '/api/v1/assignments/12/google-feedback');

      expect(rows, hasLength(2));
      expect(rows[0].state, FeedbackPostState.failed);
      expect(rows[0].failed, isTrue);
      expect(rows[0].studentLabel, 'ด.ช. สมชาย (เลขที่ 12)');
      expect(rows[0].lastError, 'ต้องเชื่อมบัญชี Google ใหม่');
      expect(rows[0].publishedAt, DateTime.utc(2026, 9, 30, 2));
      expect(rows[0].postedAt, isNull);
      expect(rows[1].state, FeedbackPostState.posted);
      expect(rows[1].student, isNull);
      expect(rows[1].studentLabel, 'นักเรียน');
      expect(rows[1].announcementId, '12345');
      expect(rows[1].postedAt, DateTime.utc(2026, 9, 30, 2, 3));
    });

    test('an unknown state reads as queued', () {
      expect(FeedbackPostState.fromApi('weird'), FeedbackPostState.queued);
    });

    test('retryFeedback posts and reads {data: {queued}}', () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(202, {
          'data': {'queued': 2},
        }),
      );
      expect(await repoWith(adapter).retryFeedback(12), 2);
      final req = adapter.requests.single;
      expect(req.method, 'POST');
      expect(req.uri.path, '/api/v1/assignments/12/google-feedback/retry');
    });

    test('retryFeedback without a count answers null', () async {
      final adapter = FakeHttpAdapter((_) async => jsonResponse(204, null));
      expect(await repoWith(adapter).retryFeedback(12), isNull);
    });
  });

  group('the announcements scope', () {
    test('the app asks for it with the others', () {
      expect(googleServerScopes, contains(announcementsScope));
      expect(
        announcementsScope,
        'https://www.googleapis.com/auth/classroom.announcements',
      );
    });

    test('status reads reconnect_message and a missing scope', () {
      final s = GoogleStatus.fromJson({
        'connected': true,
        'email': 'kru@school.ac.th',
        'scopes': _oldScopes,
        'needs_reconnect': true,
        'reconnect_message': announcementsReconnectMessage,
      });
      expect(s.needsReconnect, isTrue);
      expect(s.reconnectMessage, announcementsReconnectMessage);
      expect(s.lacksAnnouncementsScope, isTrue);
      expect(reconnectReason(s), announcementsReconnectMessage);

      final fresh = GoogleStatus.fromJson({
        'connected': true,
        'scopes': _allScopes.join(' '),
        'needs_reconnect': false,
        'reconnect_message': null,
      });
      expect(fresh.lacksAnnouncementsScope, isFalse);
      expect(fresh.reconnectMessage, isNull);
      // A server that lists no scopes says nothing about them.
      expect(
        const GoogleStatus(connected: true).lacksAnnouncementsScope,
        isFalse,
      );
    });

    test('the reason falls back to the scopes, then to an expired grant', () {
      expect(
        reconnectReason(
          GoogleStatus(
            connected: true,
            scopes: _oldScopes,
            needsReconnect: true,
          ),
        ),
        announcementsReconnectMessage,
      );
      expect(
        reconnectReason(
          const GoogleStatus(
            connected: true,
            scopes: _allScopes,
            needsReconnect: true,
          ),
        ),
        contains('หมดอายุ'),
      );
    });

    test('409 google_reconnect_required explains the server reason', () {
      final withReason = apiError(409, {
        'message': announcementsReconnectMessage,
        'code': 'google_reconnect_required',
      });
      expect(isGoogleReconnectError(withReason), isTrue);
      expect(
        googleErrorMessage(withReason),
        '$announcementsReconnectMessage ไปที่ ตั้งค่า → Google Classroom '
        'แล้วกด "เชื่อมใหม่"',
      );
      final expired = apiError(409, {
        'message': 'สิทธิ์หมดอายุ ไปที่ ตั้งค่า แล้วกด "เชื่อมใหม่"',
        'code': 'google_reconnect_required',
      });
      expect(
        googleErrorMessage(expired),
        'สิทธิ์หมดอายุ ไปที่ ตั้งค่า แล้วกด "เชื่อมใหม่"',
      );
      final bare = apiError(409, {'code': 'google_reconnect_required'});
      expect(googleErrorMessage(bare), contains('หมดอายุแล้ว'));
      expect(
        googleErrorMessage(apiError(422, {'code': 'google_scope_missing'})),
        contains('ประกาศถึงนักเรียน'),
      );
    });

    test('the attention card leads to "ส่งประกาศอีกครั้ง"', () {
      final a = TeacherAttention.fromJson({'feedback_failed': 2});
      expect(a.feedbackFailed, 2);
      expect(a.isEmpty, isFalse);
    });
  });

  group('settings and banner', () {
    Future<void> pumpCard(WidgetTester tester, GoogleStatus status) async {
      tester.view.physicalSize = const Size(1000, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            googleClassroomEnabledProvider.overrideWithValue(true),
            googleClassroomRepositoryProvider.overrideWithValue(
              FakeGoogleRepository(statusValue: status),
            ),
            googleAuthProvider.overrideWithValue(FakeGoogleAuth()),
          ],
          child: const MaterialApp(
            home: Scaffold(
              body: Column(
                children: [GoogleReconnectBanner(), GoogleClassroomCard()],
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('an account without the announcements scope must reconnect', (
      tester,
    ) async {
      await pumpCard(
        tester,
        GoogleStatus(
          connected: true,
          email: 'kru@school.ac.th',
          scopes: _oldScopes,
          needsReconnect: true,
          reconnectMessage: announcementsReconnectMessage,
        ),
      );
      expect(
        find.byKey(const ValueKey('google_reconnect_banner')),
        findsOneWidget,
      );
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('google_reconnect_reason')))
            .data,
        announcementsReconnectMessage,
      );
      expect(
        tester
            .widget<Text>(
              find.byKey(const ValueKey('google_card_reconnect_reason')),
            )
            .data,
        '$announcementsReconnectMessage กด "เชื่อมใหม่" แล้วอนุญาตให้ครบทุกข้อ',
      );
      expect(find.text('ต้องเชื่อมใหม่'), findsOneWidget);
      expect(find.text('เชื่อมใหม่'), findsWidgets);
    });

    testWidgets('an expired grant keeps its own text', (tester) async {
      await pumpCard(
        tester,
        const GoogleStatus(
          connected: true,
          email: 'kru@school.ac.th',
          scopes: _allScopes,
          needsReconnect: true,
        ),
      );
      expect(
        find.textContaining('ช่วงทดสอบ Google ให้สิทธิ์ได้ครั้งละ 7 วัน'),
        findsNWidgets(2),
      );
    });

    testWidgets('a working account shows no prompt', (tester) async {
      await pumpCard(
        tester,
        const GoogleStatus(
          connected: true,
          email: 'kru@school.ac.th',
          scopes: _allScopes,
        ),
      );
      expect(
        find.byKey(const ValueKey('google_reconnect_banner')),
        findsNothing,
      );
      expect(find.text('เชื่อมแล้ว'), findsOneWidget);
    });
  });

  group('ประกาศผลรายคน screen', () {
    Future<(FakeGoogleRepository, _Opener)> pump(
      WidgetTester tester, {
      FakeGoogleRepository? google,
      bool web = false,
    }) async {
      tester.view.physicalSize = const Size(1000, 3000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final repo = google ?? (FakeGoogleRepository()..feedbackRows = _rows());
      final opener = _Opener();
      await pumpScreen(
        tester,
        const ClassroomFeedbackScreen(assignmentId: 12),
        overrides: [
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(repo),
          googleAuthProvider.overrideWithValue(FakeGoogleAuth()),
          assignmentsRepositoryProvider.overrideWithValue(
            _Assignments(web: web),
          ),
          reviewRepositoryProvider.overrideWithValue(
            FakeReviewRepository(meta: _meta()),
          ),
          externalUrlOpenerProvider.overrideWithValue(opener.call),
        ],
      );
      return (repo, opener);
    }

    String chip(WidgetTester tester, int id) => tester
        .widgetList<Text>(
          find.descendant(
            of: find.byKey(ValueKey('feedback_state_$id')),
            matching: find.byType(Text),
          ),
        )
        .map((t) => t.data)
        .join();

    testWidgets('shows the delivery state of every student', (tester) async {
      await pump(tester);
      expect(find.text('ประกาศผล: เศษส่วน'), findsOneWidget);
      expect(find.text('ส่งประกาศแล้ว 1/3 คน'), findsOneWidget);
      expect(find.text('รอส่ง 1 · ส่งแล้ว 1 · ส่งไม่สำเร็จ 1'), findsOneWidget);
      expect(chip(tester, 1), 'ส่งแล้ว');
      expect(chip(tester, 2), 'ส่งไม่สำเร็จ');
      expect(chip(tester, 3), 'รอส่ง');
      expect(
        find.text('ส่งไม่สำเร็จ: นักเรียนยังไม่ได้จับคู่บัญชี Google'),
        findsOneWidget,
      );
      expect(find.textContaining('ส่งประกาศ 30 ก.ย.'), findsOneWidget);
      expect(find.textContaining('จะถูกส่งภายในไม่กี่นาที'), findsOneWidget);
      expect(find.text('ส่งประกาศอีกครั้ง (1)'), findsOneWidget);
      // App courseWork: grades go back by themselves, nothing to copy.
      expect(find.byKey(const ValueKey('copy_scores')), findsNothing);
      expect(find.byKey(const ValueKey('feedback_copy_score_1')), findsNothing);
      expect(find.byKey(const ValueKey('web_coursework_note')), findsNothing);
      expect(find.byKey(const ValueKey('open_in_classroom')), findsOneWidget);
    });

    testWidgets('"ส่งประกาศอีกครั้ง" queues the failed rows and reloads', (
      tester,
    ) async {
      final (repo, _) = await pump(tester);
      final loads = repo.feedbackLoads;
      await tester.tap(find.byKey(const ValueKey('retry_feedback')));
      await tester.pumpAndSettle();
      expect(repo.feedbackRetries, [12]);
      expect(repo.feedbackLoads, loads + 1);
      expect(find.text('กำลังส่งประกาศอีกครั้ง 1 คน'), findsOneWidget);
      expect(chip(tester, 2), 'รอส่ง');
      expect(find.byKey(const ValueKey('retry_feedback')), findsNothing);
    });

    testWidgets('nothing queued says why', (tester) async {
      final repo = FakeGoogleRepository()
        ..feedbackRows = _rows()
        ..feedbackQueued = 0;
      await pump(tester, google: repo);
      await tester.tap(find.byKey(const ValueKey('retry_feedback')));
      await tester.pumpAndSettle();
      expect(find.textContaining('ยังส่งใหม่ไม่ได้'), findsOneWidget);
    });

    testWidgets('a reconnect error on retry shows the banner with the reason', (
      tester,
    ) async {
      final repo = FakeGoogleRepository(
        statusValue: const GoogleStatus(
          connected: true,
          email: 'kru@school.ac.th',
          scopes: _allScopes,
        ),
      )..feedbackRows = _rows();
      repo.retryFeedbackError = apiError(409, {
        'message': announcementsReconnectMessage,
        'code': 'google_reconnect_required',
      });
      await pump(tester, google: repo);
      expect(
        find.byKey(const ValueKey('google_reconnect_banner')),
        findsNothing,
      );
      await tester.tap(find.byKey(const ValueKey('retry_feedback')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('google_reconnect_banner')),
        findsOneWidget,
      );
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('google_reconnect_reason')))
            .data,
        announcementsReconnectMessage,
      );
      // Retrying again waits for the new connection.
      final retry = tester.widget<FilledButton>(
        find.byKey(const ValueKey('retry_feedback')),
      );
      expect(retry.onPressed, isNull);
      expect(
        find.byKey(const ValueKey('feedback_reconnect_first')),
        findsOneWidget,
      );
    });

    testWidgets('without the scope the banner asks to reconnect first', (
      tester,
    ) async {
      final repo = FakeGoogleRepository(
        statusValue: GoogleStatus(
          connected: true,
          email: 'kru@school.ac.th',
          scopes: _oldScopes,
          needsReconnect: true,
          reconnectMessage: announcementsReconnectMessage,
        ),
      )..feedbackRows = _rows();
      await pump(tester, google: repo);
      expect(
        find.byKey(const ValueKey('google_reconnect_banner')),
        findsOneWidget,
      );
      expect(find.text(announcementsReconnectMessage), findsOneWidget);
      final retry = tester.widget<FilledButton>(
        find.byKey(const ValueKey('retry_feedback')),
      );
      expect(retry.onPressed, isNull);
    });

    testWidgets('web courseWork: copy scores and open in Classroom', (
      tester,
    ) async {
      final copied = _mockClipboard(tester);
      final (_, opener) = await pump(tester, web: true);
      expect(find.byKey(const ValueKey('web_coursework_note')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('feedback_copy_score_2')));
      await tester.pumpAndSettle();
      expect(copied.last, '6');
      expect(
        find.text('คัดลอกคะแนน 6 ของ นักเรียน 2 (เลขที่ 2) แล้ว'),
        findsOneWidget,
      );

      // Everyone published, app hand-ins included, by number.
      await tester.tap(find.byKey(const ValueKey('copy_scores')));
      await tester.pumpAndSettle();
      expect(
        copied.last,
        '1\tนักเรียน 1\t9.5\n2\tนักเรียน 2\t6\n'
        '3\tนักเรียน 3\t7\n4\tนักเรียน 4\t8',
      );
      expect(
        find.text('คัดลอกคะแนน 4 คนแล้ว (เลขที่ ชื่อ คะแนน)'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const ValueKey('open_in_classroom')));
      await tester.pumpAndSettle();
      expect(opener.opened, [Uri.parse(_link)]);
    });

    testWidgets('an empty list explains when announcements go out', (
      tester,
    ) async {
      await pump(tester, google: FakeGoogleRepository());
      expect(find.byKey(const ValueKey('feedback_empty')), findsOneWidget);
      expect(find.text('ส่งประกาศแล้ว 0/0 คน'), findsOneWidget);
      expect(find.byKey(const ValueKey('retry_feedback')), findsNothing);
    });

    testWidgets('a failed load can be retried', (tester) async {
      final repo = FakeGoogleRepository()
        ..error = apiError(500, {'message': 'ล่ม', 'code': 'server_error'});
      await pump(tester, google: repo);
      expect(find.text('ล่ม'), findsOneWidget);
      repo
        ..error = null
        ..feedbackRows = _rows();
      await tester.tap(find.text('ลองใหม่'));
      await tester.pumpAndSettle();
      expect(find.text('ส่งประกาศแล้ว 1/3 คน'), findsOneWidget);
    });

    testWidgets('refresh reloads the list', (tester) async {
      final (repo, _) = await pump(tester);
      final loads = repo.feedbackLoads;
      await tester.tap(find.byTooltip('โหลดใหม่'));
      await tester.pumpAndSettle();
      expect(repo.feedbackLoads, loads + 1);
    });
  });

  testWidgets('the assignment card opens "ประกาศผลรายคน"', (tester) async {
    tester.view.physicalSize = const Size(1000, 2600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final router = GoRouter(
      routes: [
        GoRoute(
          path: '/',
          builder: (_, _) => Scaffold(
            body: ListView(
              children: [
                AssignmentGoogleSection(
                  assignment: Assignment(
                    id: 12,
                    classroomId: 7,
                    subjectId: 1,
                    title: 'เศษส่วน',
                    status: 'ready',
                    googleLink: const AssignmentGoogleLink(
                      courseWorkId: '9001',
                      alternateLink: _link,
                    ),
                  ),
                  classroom: const Classroom(
                    id: 7,
                    name: 'ป.5/1',
                    gradeLevel: 5,
                    academicYear: 2569,
                    classCode: 'ABC123',
                    googleLink: ClassroomGoogleLink(
                      courseId: 'c1',
                      courseName: 'คณิต',
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
        GoRoute(
          path: '/assignments/:id/google-feedback',
          builder: (_, s) => Text('feedback-${s.pathParameters['id']}'),
        ),
      ],
    );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(
            FakeGoogleRepository(),
          ),
        ],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('ส่งประกาศส่วนตัว'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('open_google_feedback')));
    await tester.pumpAndSettle();
    expect(find.text('feedback-12'), findsOneWidget);
  });
}
