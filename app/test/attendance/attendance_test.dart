import 'package:eduvision/features/attendance/attendance_models.dart';
import 'package:eduvision/features/attendance/attendance_repository.dart';
import 'package:eduvision/features/attendance/attendance_screen.dart';
import 'package:eduvision/features/attendance/attendance_session_screen.dart';
import 'package:eduvision/features/attendance/my_attendance_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../gradebook/gradebook_fakes.dart';
import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';

Map<String, dynamic> _counts({
  int present = 0,
  int late = 0,
  int absent = 0,
  int personal = 0,
  int sick = 0,
}) => {
  'present': present,
  'late': late,
  'absent': absent,
  'personal_leave': personal,
  'sick_leave': sick,
};

Map<String, dynamic> _sessionJson({
  int id = 31,
  String heldOn = '2026-10-05',
  int? periodNo = 2,
  String? note,
  bool records = false,
}) => {
  'id': id,
  'course_id': 4,
  'classroom_id': 7,
  'held_on': heldOn,
  'period_no': periodNo,
  'note': note,
  'counts': _counts(present: 1, late: 1),
  if (records)
    'records': [
      {
        'student_id': 11,
        'student_number': 1,
        'name': 'ก้อง',
        'in_classroom': true,
        'status': 'present',
        'note': null,
      },
      {
        'student_id': 12,
        'student_number': 2,
        'name': 'ขวัญ',
        'in_classroom': true,
        'status': 'late',
        'note': 'รถติด',
      },
    ],
};

Map<String, dynamic> _overviewJson({
  bool sessions = true,
  Map<String, dynamic>? autoItem,
}) => {
  'scores': {'present': 1, 'late': 0.5, 'absent': 0},
  'auto_item': autoItem,
  'sessions': sessions
      ? [
          _sessionJson(id: 32, heldOn: '2026-10-06', periodNo: null),
          _sessionJson(note: 'สอบย่อย'),
        ]
      : const [],
  'students': [
    {
      'student_id': 11,
      'student_number': 1,
      'name': 'ก้อง',
      'in_classroom': true,
      'counts': _counts(present: sessions ? 2 : 0),
      'counted': sessions ? 2 : 0,
      'rate': sessions ? 1.0 : null,
    },
    {
      'student_id': 12,
      'student_number': 2,
      'name': 'ขวัญ',
      'in_classroom': true,
      'counts': _counts(late: sessions ? 1 : 0, absent: sessions ? 1 : 0),
      'counted': sessions ? 2 : 0,
      'rate': sessions ? 0.25 : null,
    },
  ],
};

class FakeAttendance implements AttendanceRepository {
  FakeAttendance({Map<String, dynamic>? overview})
    : overviewBody = overview ?? _overviewJson();

  Map<String, dynamic> overviewBody;
  final calls = <String>[];
  AttendanceDraft? lastDraft;
  AttendanceScores? savedScores;
  Object? failWith;

  @override
  Future<AttendanceOverview> overview(int courseId, int classroomId) async {
    calls.add('overview:$courseId:$classroomId');
    return AttendanceOverview.fromJson(overviewBody);
  }

  @override
  Future<AttendanceSession> session(int sessionId) async =>
      AttendanceSession.fromJson(_sessionJson(id: sessionId, records: true));

  @override
  Future<AttendanceSession> create(
    int courseId,
    int classroomId,
    AttendanceDraft draft,
  ) async {
    if (failWith case final e?) throw e;
    calls.add('create:$courseId:$classroomId');
    lastDraft = draft;
    return AttendanceSession.fromJson(_sessionJson(records: true));
  }

  @override
  Future<AttendanceSession> update(int sessionId, AttendanceDraft draft) async {
    calls.add('update:$sessionId');
    lastDraft = draft;
    return AttendanceSession.fromJson(_sessionJson(records: true));
  }

  @override
  Future<void> delete(int sessionId) async => calls.add('delete:$sessionId');

  @override
  Future<AttendanceScores> saveScores(
    int courseId,
    AttendanceScores scores,
  ) async {
    calls.add('scores:$courseId');
    savedScores = scores;
    overviewBody = {...overviewBody, 'scores': scores.toJson()};
    return scores;
  }

  @override
  Future<void> addAutoItem(
    int courseId,
    int classroomId, {
    required int categoryId,
    required double maxPoints,
  }) async {
    calls.add('auto:$courseId:$classroomId:$categoryId:$maxPoints');
    overviewBody = {
      ...overviewBody,
      'auto_item': {
        'id': 9,
        'category_id': categoryId,
        'max_points': maxPoints,
      },
    };
  }

  @override
  Future<List<MyAttendanceCourse>> mine() async => [
    MyAttendanceCourse.fromJson({
      'course': {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
      'classroom': {'id': 7, 'name': 'ป.5/1', 'academic_year': 2569},
      'counts': _counts(late: 1, sick: 1),
      'counted': 1,
      'rate': 0.5,
      'sessions': [
        {
          'id': 32,
          'held_on': '2026-10-06',
          'period_no': null,
          'status': 'sick_leave',
          'note': 'ไข้หวัด',
        },
        {'id': 31, 'held_on': '2026-10-05', 'period_no': 1, 'status': 'late'},
      ],
    }),
  ];
}

void main() {
  Future<FakeAttendance> pump(
    WidgetTester tester,
    Widget screen, {
    FakeAttendance? repo,
    FakeGradebookRepository? gradebook,
  }) async {
    tall(tester);
    final r = repo ?? FakeAttendance();
    await pumpScreen(
      tester,
      screen,
      overrides: [
        ...overrides(FakeCoursesRepository([course()]), gradebook: gradebook),
        attendanceRepositoryProvider.overrideWithValue(r),
      ],
      extraRoutes: [
        stubRoute('/courses/:id/attendance/new', 'new'),
        stubRoute('/courses/:id/attendance/:sid', 'session'),
        stubRoute('/courses/:id/gradebook', 'gradebook'),
      ],
    );
    return r;
  }

  group('models', () {
    test('a rate, a value and a day are written for people', () {
      expect(formatAttendanceRate(0.846), '85%');
      expect(formatAttendanceRate(null), '–');
      expect(formatAttendanceNumber(0.5), '0.5');
      expect(formatAttendanceNumber(1), '1');
      expect(formatAttendanceNumber(10), '10');
      expect(formatDay(parseDay('2026-01-09')), '2026-01-09');
      expect(periodLabel(null), '');
      expect(AttendanceStatus.parse('holiday'), isNull);
      expect(AttendanceStatus.sickLeave.counted, isFalse);
    });

    test('a draft sends every student with a status', () {
      final draft = AttendanceDraft(
        heldOn: DateTime(2026, 10, 5),
        periodNo: 3,
        records: {
          11: (status: AttendanceStatus.present, note: null),
          12: (status: AttendanceStatus.personalLeave, note: 'ธุระ'),
        },
      );
      expect(draft.toJson(classroomId: 7), {
        'classroom_id': 7,
        'held_on': '2026-10-05',
        'period_no': 3,
        'note': null,
        'records': [
          {'student_id': 11, 'status': 'present', 'note': null},
          {'student_id': 12, 'status': 'personal_leave', 'note': 'ธุระ'},
        ],
      });
      expect(draft.toJson().containsKey('classroom_id'), isFalse);
    });
  });

  group('ApiAttendanceRepository', () {
    test(
      'reads the overview and writes sessions, values and the item',
      () async {
        final adapter = FakeHttpAdapter((options) async {
          final path = options.uri.path;
          if (path == '/api/v1/student/attendance') {
            return jsonResponse(200, {'data': []});
          }
          if (path.endsWith('/attendance')) {
            return jsonResponse(200, {
              'data': _overviewJson(autoItem: {'id': 9, 'max_points': 10}),
            });
          }
          if (path.endsWith('/attendance/scores')) {
            return jsonResponse(200, {
              'data': {
                'scores': {'present': 1, 'late': 1, 'absent': 0},
              },
            });
          }
          if (options.method == 'DELETE') return jsonResponse(204, null);
          return jsonResponse(200, {'data': _sessionJson(records: true)});
        });
        final repo = ApiAttendanceRepository(fakeDio(adapter));

        final overview = await repo.overview(4, 7);
        expect(adapter.requests.last.uri.queryParameters, {
          'classroom_id': '7',
        });
        expect(overview.sessions, hasLength(2));
        expect(overview.sessions.first.periodNo, isNull);
        expect(overview.autoItem?.maxPoints, 10);
        expect(overview.students.last.rate, 0.25);
        expect(overview.students.last.counts.summary, 'สาย 1 · ขาด 1');

        final draft = AttendanceDraft(
          heldOn: DateTime(2026, 10, 5),
          records: {},
        );
        final created = await repo.create(4, 7, draft);
        expect(adapter.requests.last.method, 'POST');
        expect(
          adapter.requests.last.uri.path,
          '/api/v1/courses/4/attendance-sessions',
        );
        expect(created.records.last.status, AttendanceStatus.late);

        await repo.update(31, draft);
        expect(adapter.requests.last.method, 'PUT');
        expect(
          adapter.requests.last.uri.path,
          '/api/v1/attendance-sessions/31',
        );
        expect((await repo.session(31)).records, hasLength(2));
        await repo.delete(31);
        expect(adapter.requests.last.method, 'DELETE');

        final scores = await repo.saveScores(
          4,
          const AttendanceScores(late: 1),
        );
        expect(scores.late, 1);
        await repo.addAutoItem(4, 7, categoryId: 3, maxPoints: 10);
        expect(adapter.requests.last.data, {
          'classroom_ids': [7],
          'category_id': 3,
          'name': 'การเข้าเรียน',
          'max_points': 10.0,
          'auto_attendance': true,
        });
        expect(await repo.mine(), isEmpty);
      },
    );
  });

  group('AttendanceScreen', () {
    testWidgets('lists the checked periods and each student\'s rate', (
      tester,
    ) async {
      await pump(tester, const AttendanceScreen(courseId: 4));
      expect(find.text('เช็คชื่อ ค15101'), findsOneWidget);
      expect(find.text('ประวัติ (2)'), findsOneWidget);
      expect(find.text('6 ต.ค. 2569'), findsOneWidget);
      expect(find.text('5 ต.ค. 2569 · คาบ 2'), findsOneWidget);
      expect(find.textContaining('สอบย่อย'), findsOneWidget);
      expect(find.textContaining('มาสาย 0.5'), findsOneWidget);

      await tester.tap(find.text('สรุปรายคน'));
      await tester.pumpAndSettle();
      expect(find.text('ขวัญ'), findsOneWidget);
      expect(find.text('25%'), findsOneWidget);
      expect(find.text('100%'), findsOneWidget);
    });

    testWidgets('opens a new period and a checked one', (tester) async {
      await pump(tester, const AttendanceScreen(courseId: 4));
      await tester.tap(find.byKey(const ValueKey('attendance_session_31')));
      await tester.pumpAndSettle();
      expect(find.text('session /courses/4/attendance/31'), findsOneWidget);
      await tester.pageBack();
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('attendance_new')));
      await tester.pumpAndSettle();
      expect(
        find.text('new /courses/4/attendance/new?classroom=7'),
        findsOneWidget,
      );
    });

    testWidgets('changes the value of a status', (tester) async {
      final repo = await pump(tester, const AttendanceScreen(courseId: 4));
      await tester.tap(find.byKey(const ValueKey('attendance_scores')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('score_late')), '2');
      await tester.tap(find.byKey(const ValueKey('scores_save')));
      await tester.pumpAndSettle();
      expect(find.text('ใส่ค่า 0 ถึง 1'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('score_late')), '0.75');
      await tester.tap(find.byKey(const ValueKey('scores_save')));
      await tester.pumpAndSettle();
      expect(repo.savedScores?.late, 0.75);
      expect(find.textContaining('มาสาย 0.75'), findsOneWidget);
    });

    testWidgets('adds the gradebook item once the gradebook is set up', (
      tester,
    ) async {
      final gradebook = FakeGradebookRepository(settings: settingsJson());
      final repo = await pump(
        tester,
        const AttendanceScreen(courseId: 4),
        gradebook: gradebook,
      );
      await tester.tap(find.byKey(const ValueKey('attendance_add_auto_item')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('auto_item_max')), '5');
      await tester.tap(find.byKey(const ValueKey('auto_item_save')));
      await tester.pumpAndSettle();
      expect(repo.calls.where((c) => c.startsWith('auto:')), [
        'auto:4:7:10:5.0',
      ]);
      expect(
        find.byKey(const ValueKey('attendance_auto_item')),
        findsOneWidget,
      );
      expect(find.textContaining('เต็ม 5 คะแนน'), findsOneWidget);
    });

    testWidgets('sends the teacher to the gradebook when it is not set up', (
      tester,
    ) async {
      final repo = await pump(tester, const AttendanceScreen(courseId: 4));
      await tester.tap(find.byKey(const ValueKey('attendance_add_auto_item')));
      await tester.pumpAndSettle();
      expect(find.text('ยังไม่ได้ตั้งค่าสมุดคะแนน'), findsOneWidget);
      await tester.tap(find.text('เปิดสมุดคะแนน'));
      await tester.pumpAndSettle();
      expect(find.text('gradebook /courses/4/gradebook'), findsOneWidget);
      expect(repo.calls.where((c) => c.startsWith('auto')), isEmpty);
    });

    testWidgets('says so when nothing was checked yet', (tester) async {
      await pump(
        tester,
        const AttendanceScreen(courseId: 4),
        repo: FakeAttendance(overview: _overviewJson(sessions: false)),
      );
      expect(find.textContaining('ยังไม่เคยเช็คชื่อห้องนี้'), findsOneWidget);
    });
  });

  group('AttendanceSessionScreen', () {
    testWidgets('a new period starts with everyone present', (tester) async {
      final repo = await pump(
        tester,
        const AttendanceSessionScreen(courseId: 4, classroomId: 7),
      );
      expect(find.text('มา 2'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('session_12_absent')));
      await tester.pumpAndSettle();
      expect(find.text('มา 1 · ขาด 1'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('session_note_12')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('student_note')),
        'ไม่แจ้ง',
      );
      await tester.tap(find.byKey(const ValueKey('student_note_save')));
      await tester.pumpAndSettle();
      expect(find.text('ไม่แจ้ง'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('session_period')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('คาบ 3').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('session_save')));
      await tester.pumpAndSettle();

      expect(repo.calls, contains('create:4:7'));
      final draft = repo.lastDraft!;
      expect(draft.periodNo, 3);
      expect(draft.records[11]?.status, AttendanceStatus.present);
      expect(draft.records[12]?.status, AttendanceStatus.absent);
      expect(draft.records[12]?.note, 'ไม่แจ้ง');
      final now = DateTime.now();
      expect(draft.heldOn, DateTime(now.year, now.month, now.day));
      expect(find.text('stub-home'), findsOneWidget);
    });

    testWidgets('"มาทุกคน" resets the statuses', (tester) async {
      await pump(
        tester,
        const AttendanceSessionScreen(courseId: 4, classroomId: 7),
      );
      await tester.tap(find.byKey(const ValueKey('session_11_sick_leave')));
      await tester.pumpAndSettle();
      expect(find.text('มา 1 · ลาป่วย 1'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('session_all_present')));
      await tester.pumpAndSettle();
      expect(find.text('มา 2'), findsOneWidget);
    });

    testWidgets('a checked period is edited and deleted', (tester) async {
      final repo = await pump(
        tester,
        const AttendanceSessionScreen(courseId: 4, sessionId: 31),
      );
      expect(find.text('แก้การเช็คชื่อ'), findsOneWidget);
      expect(find.text('มา 1 · สาย 1'), findsOneWidget);
      expect(find.text('รถติด'), findsOneWidget);
      expect(find.text('5 ต.ค. 2569'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('session_12_present')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('session_save')));
      await tester.pumpAndSettle();
      expect(repo.calls, contains('update:31'));
      expect(repo.lastDraft?.heldOn, DateTime(2026, 10, 5));
      expect(repo.lastDraft?.periodNo, 2);
      expect(repo.lastDraft?.records[12]?.status, AttendanceStatus.present);
    });

    testWidgets('deleting asks first', (tester) async {
      final repo = await pump(
        tester,
        const AttendanceSessionScreen(courseId: 4, sessionId: 31),
      );
      await tester.tap(find.byKey(const ValueKey('session_delete')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ลบ'));
      await tester.pumpAndSettle();
      expect(repo.calls, contains('delete:31'));
      expect(find.text('stub-home'), findsOneWidget);
    });

    testWidgets('a refused save keeps the form open', (tester) async {
      final repo = FakeAttendance()..failWith = Exception('boom');
      await pump(
        tester,
        const AttendanceSessionScreen(courseId: 4, classroomId: 7),
        repo: repo,
      );
      await tester.tap(find.byKey(const ValueKey('session_save')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('session_save')), findsOneWidget);
      expect(repo.calls.where((c) => c.startsWith('create')), isEmpty);
    });
  });

  group('MyAttendanceScreen', () {
    testWidgets('shows each course with its periods', (tester) async {
      await pump(tester, const MyAttendanceScreen());
      expect(find.text('การเข้าเรียนของฉัน'), findsOneWidget);
      expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
      expect(find.text('50%'), findsOneWidget);
      expect(find.text('ป.5/1 · สาย 1 · ลาป่วย 1'), findsOneWidget);

      await tester.tap(find.text('ค15101 คณิตศาสตร์ 5'));
      await tester.pumpAndSettle();
      expect(find.text('6 ต.ค. 2569'), findsOneWidget);
      expect(find.text('5 ต.ค. 2569 · คาบ 1'), findsOneWidget);
      expect(find.text('ไข้หวัด'), findsOneWidget);
      expect(find.text('ลาป่วย'), findsOneWidget);
    });
  });
}
