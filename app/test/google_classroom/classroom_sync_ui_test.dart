import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/assignments/answer_key_providers.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/answer_key_screen.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_page.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/assignment_google_section.dart';
import 'package:eduvision/features/google_classroom/classroom_google_section.dart';
import 'package:eduvision/features/google_classroom/google_browser_connect.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_reconnect_banner.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/google_classroom/grade_conflicts_screen.dart';
import 'package:eduvision/features/google_classroom/submissions_screen.dart';
import 'package:eduvision/features/home/dashboard_page.dart';
import 'package:eduvision/features/home/teacher_attention.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../assignments/answer_key_fixtures.dart';
import '../helpers/home_fakes.dart';
import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';
import 'google_fakes.dart';

/// Phase 8 build step 4 in the app (DESIGN §19.3, §19.11): "ซิงก์ตอนนี้"
/// and the last sync time, mirrors of Classroom website courseWork,
/// "คะแนนไม่ตรงกัน", the reconnect banner, late hand-ins and the home
/// "รอดำเนินการ" card.

const _webLink = AssignmentGoogleLink(
  courseWorkId: 'cw-9',
  alternateLink: 'https://classroom.google.com/c/abc/a/cw-9/details',
  origin: AssignmentGoogleLink.originClassroomWeb,
  materials: [
    CourseWorkMaterial(
      title: 'ใบงาน.pdf',
      mimeType: 'application/pdf',
      supported: true,
    ),
    CourseWorkMaterial(
      title: 'โจทย์',
      mimeType: 'application/vnd.google-apps.document',
      supported: false,
    ),
  ],
);

const _mirror = Assignment(
  id: 9,
  classroomId: 7,
  subjectId: null,
  title: 'งานจากเว็บ',
  mode: AssignmentMode.freeform,
  source: 'classroom_web',
  googleLink: _webLink,
);

Classroom _classroom({ClassroomGoogleLink? link}) => Classroom(
  id: 7,
  name: 'ป.5/1',
  gradeLevel: 5,
  academicYear: 2569,
  classCode: 'ABC123',
  googleLink: link,
);

class _Classrooms extends Fake implements ClassroomsRepository {
  _Classrooms({this.link});

  final ClassroomGoogleLink? link;

  @override
  Future<List<Classroom>> list() async => [_classroom(link: link)];
}

class _Assignments extends Fake implements AssignmentsRepository {
  _Assignments(this.items);

  final List<Assignment> items;

  @override
  Future<Assignment> get(int id) async => items.firstWhere((a) => a.id == id);

  @override
  Future<List<Assignment>> list({int? classroomId}) async => items;

  @override
  Future<List<Subject>> subjects() async => const [
    Subject(id: 1, code: 'MATH', name: 'คณิตศาสตร์'),
    Subject(id: 2, code: 'SCI', name: 'วิทยาศาสตร์'),
  ];
}

/// Records what "เปิดใน Classroom" tried to open.
class _Opener {
  final opened = <Uri>[];

  Future<bool> call(Uri url) async {
    opened.add(url);
    return true;
  }
}

/// Captures Clipboard.setData.
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

void _tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1000, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

void main() {
  group('classroom card: sync now (DESIGN §19.3)', () {
    Future<FakeGoogleRepository> pumpSection(
      WidgetTester tester, {
      required ClassroomGoogleLink link,
      FakeGoogleRepository? google,
    }) async {
      _tall(tester);
      final repo = google ?? FakeGoogleRepository();
      final router = GoRouter(
        routes: [
          GoRoute(
            path: '/',
            builder: (_, _) => Scaffold(
              body: ListView(
                children: [
                  ClassroomGoogleSection(classroom: _classroom(link: link)),
                ],
              ),
            ),
          ),
          GoRoute(path: '/settings', builder: (_, _) => const Text('settings')),
        ],
      );
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            googleClassroomEnabledProvider.overrideWithValue(true),
            googleClassroomRepositoryProvider.overrideWithValue(repo),
            classroomsRepositoryProvider.overrideWithValue(
              _Classrooms(link: link),
            ),
          ],
          child: MaterialApp.router(routerConfig: router),
        ),
      );
      await tester.pumpAndSettle();
      return repo;
    }

    testWidgets('shows the last sync and queues a round', (tester) async {
      final google = await pumpSection(
        tester,
        link: ClassroomGoogleLink(
          courseId: 'c1',
          courseName: 'คณิต ป.5/1',
          workSyncedAt: DateTime.utc(2026, 9, 30, 3, 5),
        ),
      );
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('classroom_last_synced')))
            .data,
        startsWith('ซิงก์งานล่าสุด '),
      );
      expect(
        find.byKey(const ValueKey('google_reconnect_banner')),
        findsNothing,
      );

      await tester.tap(find.byKey(const ValueKey('google_sync_now')));
      await tester.pumpAndSettle();
      expect(google.syncNows, [7]);
      expect(
        find.textContaining('เริ่มซิงก์กับ Classroom แล้ว'),
        findsOneWidget,
      );
    });

    testWidgets('never synced says so', (tester) async {
      await pumpSection(
        tester,
        link: const ClassroomGoogleLink(courseId: 'c1', courseName: 'คณิต'),
      );
      expect(find.textContaining('ยังไม่ได้ซิงก์งาน'), findsOneWidget);
    });

    testWidgets('a dropped grant: banner, sync now off, reconnect opens '
        'settings', (tester) async {
      await pumpSection(
        tester,
        link: const ClassroomGoogleLink(courseId: 'c1', courseName: 'คณิต'),
        google: FakeGoogleRepository(
          statusValue: const GoogleStatus(
            connected: true,
            email: 'kru@school.ac.th',
            needsReconnect: true,
          ),
        ),
      );
      expect(
        find.byKey(const ValueKey('google_reconnect_banner')),
        findsOneWidget,
      );
      final sync = tester.widget<FilledButton>(
        find.byKey(const ValueKey('google_sync_now')),
      );
      expect(sync.onPressed, isNull);
      await tester.tap(find.byKey(const ValueKey('google_reconnect_open')));
      await tester.pumpAndSettle();
      expect(find.text('settings'), findsOneWidget);
    });

    testWidgets('409 google_reconnect_required marks the account', (
      tester,
    ) async {
      final google = FakeGoogleRepository();
      await pumpSection(
        tester,
        link: const ClassroomGoogleLink(courseId: 'c1', courseName: 'คณิต'),
        google: google,
      );
      // The server says why (here: the announcements scope, §19.7).
      google.error = apiError(409, {
        'message': announcementsReconnectMessage,
        'code': 'google_reconnect_required',
      });
      await tester.tap(find.byKey(const ValueKey('google_sync_now')));
      await tester.pumpAndSettle();
      expect(
        find.text(
          '$announcementsReconnectMessage ไปที่ ตั้งค่า → Google Classroom '
          'แล้วกด "เชื่อมใหม่"',
        ),
        findsOneWidget,
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
    });
  });

  group('assignment card of a Classroom website mirror', () {
    Future<_Opener> pumpMirror(WidgetTester tester, Assignment a) async {
      _tall(tester);
      final opener = _Opener();
      final router = GoRouter(
        routes: [
          GoRoute(
            path: '/',
            builder: (_, _) => Scaffold(
              body: ListView(
                children: [
                  AssignmentGoogleSection(
                    assignment: a,
                    classroom: _classroom(
                      link: const ClassroomGoogleLink(
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
            path: '/assignments/:id/answer-key',
            builder: (_, s) => Text('key-${s.pathParameters['id']}'),
          ),
          GoRoute(
            path: '/assignments/:id/grade-conflicts',
            builder: (_, s) => Text('conflicts-${s.pathParameters['id']}'),
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
            externalUrlOpenerProvider.overrideWithValue(opener.call),
          ],
          child: MaterialApp.router(routerConfig: router),
        ),
      );
      await tester.pumpAndSettle();
      return opener;
    }

    testWidgets('says grades cannot go back and offers the key and Classroom', (
      tester,
    ) async {
      final opener = await pumpMirror(tester, _mirror);
      expect(find.byKey(const ValueKey('web_coursework_chip')), findsOneWidget);
      expect(find.text(webCourseWorkNote), findsOneWidget);
      expect(find.byKey(const ValueKey('google_docs_note')), findsOneWidget);
      expect(find.textContaining('โจทย์ (อ่านไม่ได้)'), findsOneWidget);
      expect(find.byKey(const ValueKey('google_post')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('open_in_classroom')));
      await tester.pumpAndSettle();
      expect(opener.opened.single.toString(), _webLink.alternateLink);

      await tester.tap(find.byKey(const ValueKey('web_coursework_key')));
      await tester.pumpAndSettle();
      expect(find.text('key-9'), findsOneWidget);
    });

    testWidgets('app courseWork keeps "โพสต์แล้ว" and opens the conflicts', (
      tester,
    ) async {
      await pumpMirror(
        tester,
        Assignment(
          id: 12,
          classroomId: 7,
          subjectId: 1,
          title: 'เศษส่วน',
          status: 'ready',
          googleLink: AssignmentGoogleLink(
            courseWorkId: 'cw',
            alternateLink: 'https://classroom.google.com/x',
            postedAt: DateTime.utc(2026, 9, 1),
            lastSyncedAt: DateTime.utc(2026, 9, 30, 3),
          ),
        ),
      );
      expect(find.byKey(const ValueKey('web_coursework_chip')), findsNothing);
      expect(find.text('โพสต์แล้ว'), findsOneWidget);
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('assignment_last_synced')))
            .data,
        startsWith('ซิงก์งานที่ส่งล่าสุด '),
      );
      await tester.tap(find.byKey(const ValueKey('open_grade_conflicts')));
      await tester.pumpAndSettle();
      expect(find.text('conflicts-12'), findsOneWidget);
    });
  });

  group('submissions: late hand-ins and copying scores', () {
    SubmissionStudent student(int id, int number) => SubmissionStudent(
      id: id,
      name: 'นักเรียน $number',
      studentNumber: number,
    );

    Future<FakeGoogleRepository> pumpList(
      WidgetTester tester,
      List<GoogleSubmission> rows, {
      bool web = false,
      Map<int, SubmissionSummary> submissions = const {},
    }) async {
      _tall(tester);
      final google = FakeGoogleRepository(submissionRows: rows);
      await pumpScreen(
        tester,
        Scaffold(
          body: Consumer(
            builder: (context, ref, _) => SubmissionsList(
              assignmentId: 12,
              rows: ref.watch(googleSubmissionsProvider(12)).value ?? const [],
              submissions: submissions,
              onRefresh: () async {},
              fromClassroomWeb: web,
              courseWorkLink: web ? _webLink.alternateLink : null,
            ),
          ),
        ),
        overrides: [
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(google),
          reviewRepositoryProvider.overrideWithValue(FakeReviewRepository()),
          teacherAttentionRepositoryProvider.overrideWithValue(
            FakeTeacherAttentionRepository(),
          ),
          externalUrlOpenerProvider.overrideWithValue(_Opener().call),
        ],
        extraRoutes: [
          GoRoute(
            path: '/assignments/:id/grade-conflicts',
            builder: (_, _) => const Text('conflicts-screen'),
          ),
        ],
      );
      return google;
    }

    testWidgets('a refused late hand-in can be accepted', (tester) async {
      final google = await pumpList(tester, [
        GoogleSubmission(
          id: 41,
          googleSubmissionId: 'Cg41',
          state: SubmissionImportState.rejectedLate,
          student: student(4001, 3),
          late: true,
        ),
      ]);
      expect(find.text('ส่งช้า'), findsOneWidget);
      expect(find.textContaining('กด "รับงานส่งช้า"'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('accept_late_41')));
      await tester.pumpAndSettle();
      expect(find.textContaining('รับงานส่งช้าของ นักเรียน 3'), findsOneWidget);
      await tester.tap(find.text('รับงาน'));
      await tester.pumpAndSettle();

      expect(google.acceptedLate, [41]);
      expect(find.byKey(const ValueKey('accept_late_41')), findsNothing);
      expect(find.text('รอดาวน์โหลด'), findsWidgets);
      expect(find.text('ส่งช้า'), findsOneWidget, reason: 'still labelled');
    });

    testWidgets('web courseWork: copy one score or all, no grade retry', (
      tester,
    ) async {
      final copied = _mockClipboard(tester);
      await pumpList(
        tester,
        [
          GoogleSubmission(
            id: 51,
            googleSubmissionId: 'a',
            state: SubmissionImportState.imported,
            student: student(5001, 2),
            classroomGrade: 5,
          ),
          GoogleSubmission(
            id: 52,
            googleSubmissionId: 'b',
            state: SubmissionImportState.gradeFailed,
            student: student(5002, 1),
          ),
        ],
        web: true,
        submissions: const {
          5001: SubmissionSummary(
            id: 71,
            status: 'published',
            responseCount: 3,
            reviewedCount: 3,
            totalScore: 7.5,
            totalOverridden: true,
          ),
          5002: SubmissionSummary(
            id: 72,
            status: 'published',
            responseCount: 3,
            reviewedCount: 3,
            totalScore: 9,
          ),
        },
      );
      expect(find.byKey(const ValueKey('web_coursework_note')), findsOneWidget);
      expect(find.byKey(const ValueKey('retry_grades')), findsNothing);
      expect(find.text('คะแนนใน Classroom: 5'), findsOneWidget);
      expect(find.textContaining('(รับจาก Classroom)'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('copy_score_51')));
      await tester.pumpAndSettle();
      expect(copied.last, '7.5');

      await tester.tap(find.byKey(const ValueKey('copy_scores')));
      await tester.pumpAndSettle();
      expect(copied.last, '1\tนักเรียน 1\t9\n2\tนักเรียน 2\t7.5');
      expect(find.textContaining('คัดลอกคะแนน 2 คนแล้ว'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('open_grade_conflicts')));
      await tester.pumpAndSettle();
      expect(find.text('conflicts-screen'), findsOneWidget);
    });

    testWidgets('app courseWork keeps "ส่งคะแนนกลับอีกครั้ง"', (tester) async {
      await pumpList(tester, [
        GoogleSubmission(
          id: 61,
          googleSubmissionId: 'a',
          state: SubmissionImportState.gradeFailed,
          student: student(6001, 1),
        ),
      ]);
      expect(find.byKey(const ValueKey('retry_grades')), findsOneWidget);
      expect(find.byKey(const ValueKey('copy_scores')), findsNothing);
      expect(find.byKey(const ValueKey('copy_score_61')), findsNothing);
    });
  });

  group('คะแนนไม่ตรงกัน (DESIGN §19.3)', () {
    GradeConflict conflict(int id, {bool canPushApp = true}) => GradeConflict(
      id: id,
      submissionId: 40 + id,
      status: GradeConflictStatus.open,
      student: SubmissionStudent(
        id: 100 + id,
        name: 'นักเรียน $id',
        studentNumber: id,
      ),
      appScore: 8,
      classroomScore: 6.5,
      canPushApp: canPushApp,
      detectedAt: DateTime.utc(2026, 9, 30, 2),
    );

    Future<FakeGoogleRepository> pumpConflicts(
      WidgetTester tester,
      List<GradeConflict> rows,
    ) async {
      _tall(tester);
      final google = FakeGoogleRepository()..conflictRows = rows;
      await pumpScreen(
        tester,
        const GradeConflictsScreen(assignmentId: 12),
        overrides: [
          googleClassroomRepositoryProvider.overrideWithValue(google),
          assignmentsRepositoryProvider.overrideWithValue(
            _Assignments([
              const Assignment(
                id: 12,
                classroomId: 7,
                subjectId: 1,
                title: 'เศษส่วน',
              ),
            ]),
          ),
          reviewRepositoryProvider.overrideWithValue(FakeReviewRepository()),
          teacherAttentionRepositoryProvider.overrideWithValue(
            FakeTeacherAttentionRepository(),
          ),
          externalUrlOpenerProvider.overrideWithValue(_Opener().call),
        ],
      );
      return google;
    }

    testWidgets('push, accept (after a warning) and dismiss', (tester) async {
      final google = await pumpConflicts(tester, [
        conflict(1),
        conflict(2),
        conflict(3),
      ]);
      expect(find.text('รอเลือก 3 รายการ'), findsOneWidget);
      expect(find.text('คะแนนไม่ตรงกัน: เศษส่วน'), findsOneWidget);
      expect(find.text('6.5'), findsNWidgets(3));

      await tester.tap(find.byKey(const ValueKey('conflict_push_1')));
      await tester.pumpAndSettle();
      expect(google.resolved.last, (1, GradeConflictAction.pushApp));
      expect(find.textContaining('กำลังส่งคะแนน 8'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('conflict_accept_2')));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('คะแนนรายข้อและระดับความเข้าใจไม่เปลี่ยน'),
        findsWidgets,
      );
      await tester.tap(
        find.widgetWithText(FilledButton, 'ใช้คะแนนจาก Classroom'),
      );
      await tester.pumpAndSettle();
      expect(google.resolved.last, (2, GradeConflictAction.acceptClassroom));

      await tester.tap(find.byKey(const ValueKey('conflict_dismiss_3')));
      await tester.pumpAndSettle();
      expect(google.resolved.last, (3, GradeConflictAction.dismiss));

      expect(find.text('ไม่มีรายการรอเลือก'), findsOneWidget);
      expect(find.text('ส่งคะแนนจากแอปแล้ว'), findsOneWidget);
      expect(find.text('ใช้คะแนนจาก Classroom แล้ว'), findsOneWidget);
      expect(find.text('ไม่สนใจแล้ว'), findsOneWidget);
      expect(find.textContaining('รับคะแนนจาก Classroom'), findsWidgets);
    });

    testWidgets('a cancelled warning changes nothing', (tester) async {
      final google = await pumpConflicts(tester, [conflict(1)]);
      await tester.tap(find.byKey(const ValueKey('conflict_accept_1')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(google.resolved, isEmpty);
    });

    testWidgets('web courseWork cannot push the app score', (tester) async {
      await pumpConflicts(tester, [conflict(1, canPushApp: false)]);
      expect(find.byKey(const ValueKey('conflict_push_1')), findsNothing);
      expect(find.byKey(const ValueKey('conflict_accept_1')), findsOneWidget);
      expect(find.textContaining('แอปส่งคะแนนกลับให้ไม่ได้'), findsOneWidget);
    });

    testWidgets('an already settled row reloads the list', (tester) async {
      final google = await pumpConflicts(tester, [conflict(1)]);
      google
        ..resolveError = apiError(409, {
          'message': 'ตัดสินแล้ว',
          'code': 'conflict_resolved',
        })
        // Settled on another device meanwhile.
        ..conflictRows = [
          GradeConflict(
            id: 1,
            submissionId: 41,
            status: GradeConflictStatus.pushedApp,
            appScore: 8,
            classroomScore: 8,
          ),
        ];
      await tester.tap(find.byKey(const ValueKey('conflict_dismiss_1')));
      await tester.pumpAndSettle();
      expect(find.textContaining('ตัดสินไปแล้ว'), findsOneWidget);
      expect(find.text('ส่งคะแนนจากแอปแล้ว'), findsOneWidget);
      expect(find.byKey(const ValueKey('conflict_dismiss_1')), findsNothing);
    });

    testWidgets('empty: both sides agree', (tester) async {
      await pumpConflicts(tester, const []);
      expect(find.byKey(const ValueKey('conflicts_empty')), findsOneWidget);
    });
  });

  group('home "รอดำเนินการ" (DESIGN §19.11)', () {
    Future<List<int>> pumpHome(
      WidgetTester tester, {
      TeacherAttention attention = const TeacherAttention(),
      GoogleStatus google = const GoogleStatus(
        connected: false,
        configured: false,
      ),
    }) async {
      tester.view.physicalSize = const Size(420, 2600);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final tabs = <int>[];
      await pumpScreen(
        tester,
        Scaffold(
          body: DashboardPage(
            user: const User(id: 1, name: 'สมศรี', role: 'teacher'),
            onNavigate: tabs.add,
          ),
        ),
        overrides: [
          ...homeOverrides(attention: attention, google: google),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: true)),
          ),
          classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
          assignmentsRepositoryProvider.overrideWithValue(_Assignments([])),
          reviewRepositoryProvider.overrideWithValue(FakeReviewRepository()),
        ],
        extraRoutes: [
          GoRoute(path: '/settings', builder: (_, _) => const Text('settings')),
        ],
      );
      return tabs;
    }

    testWidgets('lists what waits and opens its tab', (tester) async {
      final tabs = await pumpHome(
        tester,
        attention: const TeacherAttention(
          keysPending: 2,
          gradeConflicts: 1,
          gradeFailed: 3,
          regradePending: 4,
        ),
      );
      expect(find.text('เฉลยรออนุมัติ 2 งาน'), findsOneWidget);
      expect(find.text('คะแนนไม่ตรงกับ Classroom 1 รายการ'), findsOneWidget);
      expect(
        find.text('ส่งคะแนนกลับ Classroom ไม่สำเร็จ 3 คน'),
        findsOneWidget,
      );
      expect(find.text('งานส่งใหม่รอกดตรวจ 4 งาน'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('attention_feedback_failed')),
        findsNothing,
      );

      await tester.tap(find.byKey(const ValueKey('attention_keys')));
      await tester.tap(find.byKey(const ValueKey('attention_regrade')));
      expect(tabs, [2, 3]);
    });

    testWidgets('nothing waiting', (tester) async {
      await pumpHome(tester);
      expect(find.byKey(const ValueKey('attention_empty')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('google_reconnect_banner')),
        findsNothing,
      );
    });

    testWidgets('a dropped Google grant shows the banner', (tester) async {
      await pumpHome(
        tester,
        attention: const TeacherAttention(needsReconnect: true),
        google: const GoogleStatus(
          connected: true,
          email: 'kru@school.ac.th',
          needsReconnect: true,
        ),
      );
      expect(
        find.byKey(const ValueKey('google_reconnect_banner')),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('google_reconnect_open')));
      await tester.pumpAndSettle();
      expect(find.text('settings'), findsOneWidget);
    });
  });

  testWidgets('assignment list labels mirrors and keys to approve', (
    tester,
  ) async {
    _tall(tester);
    await pumpScreen(
      tester,
      const Scaffold(body: AssignmentsPage()),
      overrides: [
        assignmentsRepositoryProvider.overrideWithValue(
          _Assignments([
            _mirror,
            const Assignment(
              id: 10,
              classroomId: 7,
              subjectId: 1,
              title: 'ใบงานปกติ',
              status: 'ready',
            ),
          ]),
        ),
        classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
      ],
    );
    final mirror = find.byKey(const ValueKey('assignment_card_9'));
    expect(
      find.descendant(of: mirror, matching: find.text('สร้างในเว็บ Classroom')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: mirror, matching: find.text('รออนุมัติเฉลย')),
      findsOneWidget,
    );
    final plain = find.byKey(const ValueKey('assignment_card_10'));
    expect(
      find.descendant(of: plain, matching: find.text('สร้างในเว็บ Classroom')),
      findsNothing,
    );
  });

  testWidgets('approving a mirror asks for its subject first', (tester) async {
    _tall(tester);
    final keys = FakeAnswerKeys(
      answerKeyState(
        keyOrigin: 'ai_draft',
        complete: true,
        questions: [
          questionJson(
            1,
            'short',
            answerKey: {
              'accepted': ['4'],
            },
          ),
        ],
      ),
    );
    await pumpScreen(
      tester,
      const AnswerKeyScreen(assignmentId: 9),
      overrides: [
        answerKeyRepositoryProvider.overrideWithValue(keys),
        assignmentsRepositoryProvider.overrideWithValue(
          _Assignments([_mirror]),
        ),
        classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
        answerKeyPollIntervalProvider.overrideWithValue(
          const Duration(seconds: 3),
        ),
        teacherAttentionRepositoryProvider.overrideWithValue(
          FakeTeacherAttentionRepository(),
        ),
      ],
    );
    expect(
      find.byKey(const ValueKey('classroom_web_key_note')),
      findsOneWidget,
    );
    expect(find.textContaining('อ่านไฟล์ Google Docs ไม่ได้'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('approve_key')));
    await tester.pumpAndSettle();
    expect(find.text('เลือกวิชาแล้วอนุมัติเฉลย'), findsOneWidget);
    final confirm = tester.widget<FilledButton>(
      find.byKey(const ValueKey('approve_subject_confirm')),
    );
    expect(confirm.onPressed, isNull, reason: 'a subject is required');

    await tester.tap(find.byKey(const ValueKey('approve_subject')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('วิทยาศาสตร์').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('approve_subject_confirm')));
    await tester.pumpAndSettle();

    expect(keys.approvals, 1);
    expect(keys.approvedSubjects, [2]);
    expect(find.text('อนุมัติเฉลยแล้ว'), findsWidgets);
  });

  testWidgets('approving an app assignment keeps the plain confirmation', (
    tester,
  ) async {
    _tall(tester);
    final keys = FakeAnswerKeys(
      answerKeyState(
        complete: true,
        questions: [
          questionJson(
            1,
            'short',
            answerKey: {
              'accepted': ['4'],
            },
          ),
        ],
      ),
    );
    await pumpScreen(
      tester,
      const AnswerKeyScreen(assignmentId: 12),
      overrides: [
        answerKeyRepositoryProvider.overrideWithValue(keys),
        assignmentsRepositoryProvider.overrideWithValue(
          _Assignments([
            const Assignment(
              id: 12,
              classroomId: 7,
              subjectId: 1,
              title: 'เรียงความ',
              mode: AssignmentMode.freeform,
            ),
          ]),
        ),
        classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
        teacherAttentionRepositoryProvider.overrideWithValue(
          FakeTeacherAttentionRepository(),
        ),
      ],
    );
    expect(find.byKey(const ValueKey('classroom_web_key_note')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('approve_key')));
    await tester.pumpAndSettle();
    expect(find.text('อนุมัติเฉลย?'), findsOneWidget);
    await tester.tap(find.text('อนุมัติ'));
    await tester.pumpAndSettle();
    expect(keys.approvedSubjects, [null]);
  });
}
