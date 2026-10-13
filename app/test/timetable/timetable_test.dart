import 'package:eduvision/features/timetable/student_timetable_screen.dart';
import 'package:eduvision/features/timetable/timetable_models.dart';
import 'package:eduvision/features/timetable/timetable_repository.dart';
import 'package:eduvision/features/timetable/timetable_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';

Map<String, dynamic> _slotJson({
  int id = 1,
  int weekday = 1,
  int? periodNo = 2,
  String? startsAt = '09:20',
  String? endsAt = '10:10',
  bool own = true,
  String? teacherName,
}) => {
  'id': id,
  'weekday': weekday,
  'period_no': periodNo,
  'starts_at': startsAt,
  'ends_at': endsAt,
  'course': own ? {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'} : null,
  'classroom': {'id': 7, 'name': 'ป.5/1'},
  'own': own,
  'teacher_name': teacherName,
};

/// The week of Monday 12 Oct 2026: maths on Monday period 2 and Wednesday
/// period 5, a colleague's period on Monday period 1.
Map<String, dynamic> _weekJson({
  String weekStart = '2026-10-12',
  List<Map<String, dynamic>> cancellations = const [],
  bool empty = false,
}) => {
  'week_start': weekStart,
  'slots': empty
      ? const []
      : [
          _slotJson(),
          _slotJson(
            id: 2,
            weekday: 3,
            periodNo: 5,
            startsAt: null,
            endsAt: null,
          ),
        ],
  'busy': empty
      ? const []
      : [
          _slotJson(
            id: 9,
            weekday: 1,
            periodNo: 1,
            own: false,
            teacherName: 'ครูบี',
          ),
        ],
  'cancellations': cancellations,
  'terms': empty
      ? const []
      : [
          {
            'course_id': 4,
            'code': 'ค15101',
            'term_starts_on': '2026-05-18',
            'term_ends_on': '2026-10-09',
          },
        ],
};

String _mondayOf(String date) {
  final d = DateTime.parse(date);
  return isoDate(d.subtract(Duration(days: d.weekday - 1)));
}

class _FakeTimetable implements TimetableRepository {
  _FakeTimetable([Map<String, dynamic>? body]) : body = body ?? _weekJson();

  Map<String, dynamic> body;
  final calls = <String>[];

  TimetableWeek _week(String? week) => TimetableWeek.fromJson({
    ...body,
    if (week != null) 'week_start': _mondayOf(week),
  });

  @override
  Future<TimetableWeek> week({String? week}) async {
    calls.add('week:$week');
    return _week(week);
  }

  @override
  Future<TimetableWeek> studentWeek({String? week}) async {
    calls.add('student:$week');
    return _week(week);
  }

  @override
  Future<TimetableSlot> addSlot(
    int courseId, {
    required int classroomId,
    required int weekday,
    int? periodNo,
    String? startsAt,
    String? endsAt,
  }) async {
    calls.add(
      'add:$courseId:$classroomId:$weekday:$periodNo:$startsAt:$endsAt',
    );
    return TimetableSlot.fromJson(_slotJson(id: 3, weekday: weekday));
  }

  @override
  Future<TimetableSlot> updateSlot(
    int id, {
    int? weekday,
    int? periodNo,
    bool clearPeriod = false,
    String? startsAt,
    String? endsAt,
    bool clearTimes = false,
  }) async {
    calls.add(
      'update:$id:w=$weekday:p=$periodNo:cp=$clearPeriod:s=$startsAt:e=$endsAt:ct=$clearTimes',
    );
    return TimetableSlot.fromJson(_slotJson(id: id));
  }

  @override
  Future<void> deleteSlot(int id) async => calls.add('delete:$id');

  @override
  Future<TimetableCancellation> cancel(
    int slotId,
    String heldOn, {
    String? note,
  }) async {
    calls.add('cancel:$slotId:$heldOn');
    final row = {'id': 5, 'slot_id': slotId, 'held_on': heldOn, 'note': note};
    body = {
      ...body,
      'cancellations': [...body['cancellations'] as List, row],
    };
    return TimetableCancellation.fromJson(row);
  }

  @override
  Future<void> uncancel(int cancellationId) async {
    calls.add('uncancel:$cancellationId');
    body = {...body, 'cancellations': const []};
  }

  @override
  Future<void> setTerm(
    int courseId, {
    String? startsOn,
    String? endsOn,
  }) async => calls.add('term:$courseId:$startsOn:$endsOn');
}

/// DESIGN §29.12: the weekly timetable of the teacher and the student.
void main() {
  group('models', () {
    test('a week parses with its helpers', () {
      final w = TimetableWeek.fromJson(
        _weekJson(
          cancellations: [
            {'id': 5, 'slot_id': 1, 'held_on': '2026-10-12', 'note': 'กีฬาสี'},
          ],
        ),
      );
      expect(w.weekStart, DateTime(2026, 10, 12));
      expect(w.dateOf(3), DateTime(2026, 10, 14));
      expect(w.slots.map((s) => s.id), [1, 2]);
      expect(w.busy.single.own, isFalse);
      expect(w.busy.single.teacherName, 'ครูบี');
      expect(w.slots.first.timeLabel, '09:20–10:10');
      expect(w.slots.last.timeLabel, '');
      expect(w.weekdays, [1, 2, 3, 4, 5], reason: 'no weekend period');
      expect(w.periods, [1, 2, 3, 4, 5, 6, 7, 8]);
      expect(w.isCancelled(w.slots.first), isTrue);
      expect(w.isCancelled(w.slots.last), isFalse);
      expect(w.cancellationOf(w.slots.first)?.note, 'กีฬาสี');
      expect(w.terms.single.startsOn, DateTime(2026, 5, 18));

      // The same cancellation does not strike the slot in another week.
      final next = TimetableWeek.fromJson({
        ..._weekJson(weekStart: '2026-10-19'),
        'cancellations': [
          {'id': 5, 'slot_id': 1, 'held_on': '2026-10-12', 'note': null},
        ],
      });
      expect(next.isCancelled(next.slots.first), isFalse);
    });

    test('weekend days and high or missing periods widen the grid', () {
      final w = TimetableWeek.fromJson({
        ..._weekJson(),
        'slots': [
          _slotJson(id: 1, weekday: 6, periodNo: 10),
          _slotJson(id: 2, weekday: 2, periodNo: null),
        ],
        'busy': const [],
      });
      expect(w.weekdays, [1, 2, 3, 4, 5, 6]);
      expect(w.periods, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, null]);
    });

    test('dates', () {
      expect(shortThaiDate(DateTime(2026, 10, 12)), '12 ต.ค.');
      expect(isoDate(DateTime(2026, 1, 5)), '2026-01-05');
    });
  });

  group('repository', () {
    test('the API calls', () async {
      final adapter = FakeHttpAdapter((o) async {
        if (o.path.endsWith('/timetable')) {
          return jsonResponse(200, {'data': _weekJson()});
        }
        if (o.path.contains('/cancellations')) {
          return jsonResponse(201, {
            'data': {
              'id': 5,
              'slot_id': 1,
              'held_on': '2026-10-12',
              'note': 'กีฬาสี',
            },
          });
        }
        if (o.path.contains('timetable-slots')) {
          return jsonResponse(200, {'data': _slotJson()});
        }
        return jsonResponse(200, {});
      });
      final repo = ApiTimetableRepository(fakeDio(adapter));

      final w = await repo.week(week: '2026-10-14');
      expect(w.slots.length, 2);
      expect(adapter.requests.last.path, '/timetable');
      expect(adapter.requests.last.queryParameters, {'week': '2026-10-14'});
      await repo.week();
      expect(adapter.requests.last.queryParameters, isEmpty);

      await repo.addSlot(
        4,
        classroomId: 7,
        weekday: 2,
        periodNo: 3,
        startsAt: '08:30',
        endsAt: '09:20',
      );
      expect(adapter.requests.last.method, 'POST');
      expect(adapter.requests.last.path, '/courses/4/timetable-slots');
      expect(adapter.requests.last.data, {
        'classroom_id': 7,
        'weekday': 2,
        'period_no': 3,
        'starts_at': '08:30',
        'ends_at': '09:20',
      });

      await repo.updateSlot(1, periodNo: 4);
      expect(adapter.requests.last.method, 'PUT');
      expect(adapter.requests.last.path, '/timetable-slots/1');
      expect(adapter.requests.last.data, {'period_no': 4});
      await repo.updateSlot(1, weekday: 5, clearPeriod: true, clearTimes: true);
      expect(adapter.requests.last.data, {
        'weekday': 5,
        'period_no': null,
        'starts_at': null,
        'ends_at': null,
      });

      await repo.deleteSlot(1);
      expect(adapter.requests.last.method, 'DELETE');
      expect(adapter.requests.last.path, '/timetable-slots/1');

      final c = await repo.cancel(1, '2026-10-12', note: 'กีฬาสี');
      expect(c.id, 5);
      expect(adapter.requests.last.path, '/timetable-slots/1/cancellations');
      expect(adapter.requests.last.data, {
        'held_on': '2026-10-12',
        'note': 'กีฬาสี',
      });
      await repo.uncancel(5);
      expect(adapter.requests.last.method, 'DELETE');
      expect(adapter.requests.last.path, '/timetable-cancellations/5');

      await repo.setTerm(4, startsOn: '2026-05-18', endsOn: '2026-10-09');
      expect(adapter.requests.last.method, 'PATCH');
      expect(adapter.requests.last.path, '/courses/4');
      expect(adapter.requests.last.data, {
        'term_starts_on': '2026-05-18',
        'term_ends_on': '2026-10-09',
      });

      await repo.studentWeek();
      expect(adapter.requests.last.path, '/student/timetable');
    });
  });

  group('teacher page', () {
    Future<_FakeTimetable> pump(
      WidgetTester tester, {
      _FakeTimetable? repo,
    }) async {
      tester.view.physicalSize = const Size(1440, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final r = repo ?? _FakeTimetable();
      await pumpScreen(
        tester,
        const TimetableScreen(),
        overrides: [
          ...overrides(FakeCoursesRepository([course()])),
          timetableRepositoryProvider.overrideWithValue(r),
        ],
        extraRoutes: [stubRoute('/courses/:id/attendance', 'attendance')],
      );
      return r;
    }

    testWidgets('the week shows own periods, busy periods and the term', (
      tester,
    ) async {
      final repo = await pump(tester);
      expect(repo.calls, ['week:null']);
      expect(find.text('สัปดาห์ 12 ต.ค. – 18 ต.ค. 2569'), findsOneWidget);
      for (final d in [1, 2, 3, 4, 5]) {
        expect(find.byKey(ValueKey('day_$d')), findsOneWidget);
      }
      expect(find.byKey(const ValueKey('day_6')), findsNothing);
      expect(find.text('จันทร์ 12 ต.ค.'), findsOneWidget);
      expect(find.byKey(const ValueKey('slot_1')), findsOneWidget);
      expect(find.byKey(const ValueKey('slot_2')), findsOneWidget);
      expect(find.text('ค15101'), findsNWidgets(2));
      expect(find.text('ไม่ว่าง'), findsOneWidget);
      expect(find.textContaining('ครูบี'), findsOneWidget);
      // The term of each course.
      expect(find.text('18 พ.ค. – 9 ต.ค. 2569'), findsOneWidget);

      // Next week asks the API for a date of that week; "สัปดาห์นี้" comes back.
      await tester.tap(find.byKey(const ValueKey('week_next')));
      await tester.pumpAndSettle();
      expect(repo.calls.last, 'week:2026-10-19');
      expect(find.text('สัปดาห์ 19 ต.ค. – 25 ต.ค. 2569'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('week_prev')));
      await tester.pumpAndSettle();
      expect(repo.calls.last, 'week:2026-10-12');
      await tester.tap(find.text('สัปดาห์นี้'));
      await tester.pumpAndSettle();
      expect(repo.calls.last, 'week:null');
    });

    testWidgets('an empty week explains the button and the terms', (
      tester,
    ) async {
      await pump(tester, repo: _FakeTimetable(_weekJson(empty: true)));
      expect(find.text('ยังไม่มีคาบในตาราง'), findsOneWidget);
      expect(find.text('ยังไม่ตั้งวันเปิด-ปิดภาคเรียน'), findsOneWidget);
    });

    testWidgets('a period opens attendance, is cancelled for one date and '
        'removed', (tester) async {
      final repo = await pump(tester);

      await tester.tap(find.byKey(const ValueKey('slot_1')));
      await tester.pumpAndSettle();
      expect(
        find.text('ป.5/1 · จันทร์ 12 ต.ค. · คาบ 2 · 09:20–10:10'),
        findsOneWidget,
      );
      expect(find.text('ยกเลิกคาบวันที่ 12 ต.ค.'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('slot_attendance')));
      await tester.pumpAndSettle();
      expect(
        find.text('attendance /courses/4/attendance?classroom=7'),
        findsOneWidget,
      );
      await tester.pageBack();
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('slot_1')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('slot_cancel')));
      await tester.pumpAndSettle();
      expect(repo.calls, contains('cancel:1:2026-10-12'));
      expect(find.text('ยกเลิกคาบแล้ว'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('slot_1')));
      await tester.pumpAndSettle();
      expect(find.text('คืนคาบวันที่ 12 ต.ค.'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('slot_cancel')));
      await tester.pumpAndSettle();
      expect(repo.calls, contains('uncancel:5'));

      await tester.tap(find.byKey(const ValueKey('slot_2')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('slot_delete')));
      await tester.pumpAndSettle();
      expect(find.text('ลบคาบนี้ออกจากตาราง?'), findsOneWidget);
      await tester.tap(find.text('ลบ'));
      await tester.pumpAndSettle();
      expect(repo.calls, contains('delete:2'));
    });

    testWidgets('the add dialog sends course, room, day, period and times', (
      tester,
    ) async {
      final repo = await pump(tester);
      await tester.tap(find.byKey(const ValueKey('timetable_add')));
      await tester.pumpAndSettle();
      expect(find.text('เพิ่มคาบ'), findsNWidgets(2), reason: 'FAB + title');

      // The only course is preselected; the room is not.
      await tester.tap(find.byKey(const ValueKey('slot_save')));
      await tester.pumpAndSettle();
      expect(find.text('เลือกห้องเรียนที่ผูกกับรายวิชา'), findsOneWidget);
      expect(repo.calls.where((c) => c.startsWith('add:')), isEmpty);

      await tester.tap(find.byKey(const ValueKey('slot_classroom_4')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ป.5/1').last);
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('slot_starts')), '8.30');
      await tester.tap(find.byKey(const ValueKey('slot_save')));
      await tester.pumpAndSettle();
      expect(find.text('เวลาต้องเป็น ชช:นน เช่น 08:30'), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('slot_starts')),
        '08:30',
      );
      await tester.enterText(find.byKey(const ValueKey('slot_ends')), '09:20');
      await tester.tap(find.byKey(const ValueKey('slot_save')));
      await tester.pumpAndSettle();
      expect(repo.calls, contains('add:4:7:1:1:08:30:09:20'));
      expect(find.text('เพิ่มคาบแล้ว'), findsOneWidget);
    });

    testWidgets('the edit dialog changes the period of a slot', (tester) async {
      final repo = await pump(tester);
      await tester.tap(find.byKey(const ValueKey('slot_1')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('slot_edit')));
      await tester.pumpAndSettle();
      expect(find.text('แก้ไขคาบ'), findsOneWidget);
      expect(find.byKey(const ValueKey('slot_course')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('slot_period')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ไม่ระบุคาบ').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('slot_save')));
      await tester.pumpAndSettle();
      expect(
        repo.calls,
        contains('update:1:w=1:p=null:cp=true:s=09:20:e=10:10:ct=false'),
      );
    });
  });

  group('student page', () {
    Future<_FakeTimetable> pump(
      WidgetTester tester, {
      required Size size,
    }) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final repo = _FakeTimetable(
        _weekJson(
          cancellations: [
            {'id': 5, 'slot_id': 1, 'held_on': '2026-10-12', 'note': 'กีฬาสี'},
          ],
        ),
      );
      await pumpScreen(
        tester,
        const StudentTimetableScreen(),
        overrides: [timetableRepositoryProvider.overrideWithValue(repo)],
      );
      return repo;
    }

    testWidgets('a phone lists the days as cards', (tester) async {
      final repo = await pump(tester, size: const Size(390, 1800));
      expect(repo.calls, ['student:null']);
      expect(find.text('สัปดาห์ 12 ต.ค. – 18 ต.ค. 2569'), findsOneWidget);
      for (final d in [1, 2, 3, 4, 5]) {
        expect(find.byKey(ValueKey('student_day_$d')), findsOneWidget);
      }
      expect(find.byKey(const ValueKey('student_day_6')), findsNothing);
      expect(find.text('ค15101 คณิตศาสตร์ 5'), findsNWidgets(2));
      expect(find.text('ไม่มีคาบ'), findsNWidgets(3));
      expect(find.textContaining('ยกเลิก: กีฬาสี'), findsOneWidget);
      expect(find.text('ไม่ว่าง'), findsNothing, reason: 'no busy blocks');

      await tester.tap(find.byKey(const ValueKey('week_next')));
      await tester.pumpAndSettle();
      expect(repo.calls.last, 'student:2026-10-19');
      expect(
        find.textContaining('ยกเลิก'),
        findsNothing,
        reason: 'the cancellation belongs to the week before',
      );
    });

    testWidgets('a tablet shows the grid', (tester) async {
      await pump(tester, size: const Size(1024, 900));
      expect(find.byKey(const ValueKey('student_day_1')), findsNothing);
      expect(find.byKey(const ValueKey('day_1')), findsOneWidget);
      expect(find.byKey(const ValueKey('slot_1')), findsOneWidget);
      expect(find.text('ค15101'), findsNWidgets(2));
    });
  });
}
