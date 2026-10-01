import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/features/gradebook/gradebook_models.dart';
import 'package:eduvision/features/gradebook/gradebook_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_api_server.dart';
import '../helpers/fake_http_adapter.dart';
import 'gradebook_fakes.dart';

typedef _Sent = ({
  String method,
  String path,
  Map<String, dynamic> body,
  Map<String, String> query,
});

/// Request and response shapes of the gradebook endpoints (DESIGN §23.11).
void main() {
  late List<_Sent> sent;
  late Future<ResponseBody> Function(String method, String path) answer;

  ApiGradebookRepository repo() {
    sent = [];
    final adapter = FakeHttpAdapter((o) async {
      final path = FakeApiServer.apiPath(o.uri).replaceFirst('/api/v1', '');
      sent.add((
        method: o.method,
        path: path,
        body: FakeApiServer.bodyOf(o),
        query: o.uri.queryParameters,
      ));
      return answer(o.method, path);
    });
    return ApiGradebookRepository(fakeDio(adapter));
  }

  setUp(() {
    answer = (_, _) async => jsonResponse(200, {'data': settingsJson()});
  });

  test('templates and settings parse the wrapped answers', () async {
    answer = (_, path) async => path == '/gradebook/templates'
        ? jsonResponse(200, {'data': templatesJson})
        : jsonResponse(200, {'data': settingsJson(uncategorised: 3)});
    final r = repo();

    final templates = await r.templates();
    expect(templates.map((t) => t.key), [
      'collect_final',
      'hw_mid_final_affective',
    ]);
    expect(templates.first.categories.first.isHomeworkDefault, isTrue);
    expect(templates.first.categories.last.weight, 30);

    final s = await r.settings(4);
    expect(sent.last.path, '/courses/4/gradebook/settings');
    expect(s.configured, isTrue);
    expect(s.categories, hasLength(4));
    expect(s.homeworkDefault?.name, 'การบ้าน');
    expect(s.categories.first.dropLowest, 1);
    expect(s.categories.last.itemCount, 2);
    expect(s.cutoffs, kDefaultCutoffs);
    expect(s.uncategorisedCount, 3);
  });

  test('the overview parses every course and classroom status', () async {
    answer = (_, _) async => jsonResponse(200, {
      'data': {'courses': overviewJson()},
    });
    final r = repo();

    final courses = await r.overview();
    expect(sent.single.method, 'GET');
    expect(sent.single.path, '/gradebook/overview');
    expect(sent.single.query, isEmpty);
    expect(courses.map((c) => c.id), [4, 5, 6]);
    final math = courses.first;
    expect(math.title, 'ค15101 คณิตศาสตร์ 5');
    expect(math.configured, isTrue);
    expect(math.categoryCount, 4);
    expect((math.semester, math.academicYear, math.gradeLevel), (1, 2569, 5));
    expect(math.classrooms.map((c) => c.status), [
      GradebookRoomStatus.missingScores,
      GradebookRoomStatus.ready,
      GradebookRoomStatus.published,
      GradebookRoomStatus.publishedStale,
    ]);
    final first = math.classrooms.first;
    expect(first.name, 'ป.5/1');
    expect(first.studentCount, 30);
    expect(first.emptyCategories, ['กลางภาค', 'ปลายภาค']);
    expect((first.atRiskMsCount, first.rCount, first.msCount), (2, 1, 1));
    expect(first.publishedAt, isNull);
    expect(math.classrooms[2].publishedAt, DateTime.utc(2026, 9, 30, 3));
    expect(math.classrooms[3].stale, isTrue);
    expect(courses[1].configured, isFalse);
    expect(
      courses[1].classrooms.single.status,
      GradebookRoomStatus.notConfigured,
    );

    await r.overview(academicYear: 2569, semester: 0);
    expect(sent.last.query, {'academic_year': '2569', 'semester': '0'});
  });

  test(
    'an unknown status or a missing list does not break the overview',
    () async {
      answer = (_, _) async => jsonResponse(200, {
        'data': {
          'courses': [
            {
              'id': 1,
              'classrooms': [
                {'id': 2, 'status': 'something_new'},
              ],
            },
          ],
        },
      });
      final courses = await repo().overview();
      expect(courses.single.code, '');
      expect(
        courses.single.classrooms.single.status,
        GradebookRoomStatus.notConfigured,
      );
      expect(courses.single.classrooms.single.rCount, 0);

      answer = (_, _) async => jsonResponse(200, {'data': <String, Object>{}});
      expect(await repo().overview(), isEmpty);
    },
  );

  test('template, categories and cutoffs are PUT as the API expects', () async {
    final r = repo();
    await r.applyTemplate(4, 'collect_final');
    expect(sent.last.method, 'PUT');
    expect(sent.last.path, '/courses/4/gradebook/categories');
    expect(sent.last.body, {'template': 'collect_final'});

    await r.saveCategories(4, const [
      CategoryDraft(id: 10, name: ' การบ้าน ', weight: 40, dropLowest: 2),
      CategoryDraft(name: 'สอบ', weight: 60, isHomeworkDefault: true),
    ]);
    expect(sent.last.body, {
      'categories': [
        {
          'id': 10,
          'name': 'การบ้าน',
          'weight': 40.0,
          'drop_lowest': 2,
          'is_homework_default': false,
        },
        {
          'name': 'สอบ',
          'weight': 60.0,
          'drop_lowest': 0,
          'is_homework_default': true,
        },
      ],
    });

    await r.saveCutoffs(4, [85, 80, 75, 70, 65, 60, 55]);
    expect(sent.last.path, '/courses/4/gradebook/cutoffs');
    expect(sent.last.body, {
      'cutoffs': [85, 80, 75, 70, 65, 60, 55],
    });
    await r.saveCutoffs(4, null);
    expect(sent.last.body, {'cutoffs': null});
  });

  test(
    'the grid parses columns, cells, categories and the publication',
    () async {
      answer = (_, _) async => jsonResponse(200, {
        'data': gridJson(
          publication: {
            'id': 3,
            'published_at': '2026-09-30T03:00:00+00:00',
            'stale': true,
          },
        ),
      });
      final r = repo();
      final g = await r.grid(4, 7);
      expect(sent.single.path, '/courses/4/gradebook');
      expect(sent.single.query, {'classroom_id': '7'});
      expect(g.courseCode, 'ค15101');
      expect(g.classroomName, 'ป.5/1');
      expect(g.complete, isTrue);
      expect(g.columns.map((c) => c.key), contains('i61'));
      final manual = g.column('a51')!;
      expect(manual.type, ColumnType.manualExam);
      expect(manual.editable, isTrue);
      expect(manual.isExam, isTrue);
      expect(g.column('a53')!.notCountedReason, 'ไม่นับเกรด');
      expect(g.column('i61')!.isAttendance, isTrue);
      final row = g.rows.first;
      expect(row.cell('a50').submissionId, 900);
      expect(row.cell('a52').dropped, isTrue);
      expect(row.cell('a54').state, CellState.pending);
      expect(row.categories[13]!.points, 19);
      expect(row.gradeText, '3');
      expect(g.rows.last.gradeText, 'มส');
      expect(g.rows.last.attendanceWarning, isTrue);
      expect(g.publication!.stale, isTrue);
      expect(g.countCells(CellState.pending), 1);
      expect(g.countCells(CellState.notDue), 1);
      expect(g.uncategorisedColumns, isEmpty);
    },
  );

  test('items are created per classroom, edited and deleted', () async {
    answer = (method, path) async => switch (method) {
      'POST' => jsonResponse(201, {
        'data': [
          {
            'id': 60,
            'course_id': 4,
            'classroom_id': 7,
            'category_id': 13,
            'name': 'การแต่งกาย',
            'max_points': 10.0,
            'is_attendance': false,
            'position': 1,
          },
          {
            'id': 61,
            'course_id': 4,
            'classroom_id': 8,
            'category_id': 13,
            'name': 'การแต่งกาย',
            'max_points': 10.0,
            'is_attendance': false,
            'position': 1,
          },
        ],
      }),
      'PATCH' => jsonResponse(200, {
        'data': {
          'id': 60,
          'classroom_id': 7,
          'category_id': 13,
          'name': 'การเข้าเรียน',
          'max_points': 20.0,
          'is_attendance': true,
        },
      }),
      _ => jsonResponse(204, null),
    };
    final r = repo();
    const draft = GradebookItemDraft(
      name: ' การแต่งกาย ',
      maxPoints: 10,
      categoryId: 13,
      classroomIds: [7, 8],
    );
    final items = await r.addItem(4, draft);
    expect(sent.last.path, '/courses/4/gradebook-items');
    expect(sent.last.body, {
      'classroom_ids': [7, 8],
      'category_id': 13,
      'name': 'การแต่งกาย',
      'max_points': 10.0,
      'is_attendance': false,
    });
    expect(items.map((i) => i.classroomId), [7, 8]);

    final updated = await r.updateItem(
      60,
      const GradebookItemDraft(
        name: 'การเข้าเรียน',
        maxPoints: 20,
        categoryId: 13,
        isAttendance: true,
      ),
    );
    expect(sent.last.method, 'PATCH');
    expect(sent.last.path, '/gradebook-items/60');
    expect(sent.last.body['is_attendance'], isTrue);
    expect(updated.isAttendance, isTrue);
    expect(updated.maxPoints, 20);

    await r.deleteItem(60);
    expect(sent.last.method, 'DELETE');
    expect(sent.last.path, '/gradebook-items/60');
  });

  test('scores go to the item or the assignment, 100 rows at a time', () async {
    answer = (method, _) async => method == 'POST'
        ? jsonResponse(200, {
            'data': {'filled': 5},
          })
        : jsonResponse(200, {
            'data': {'entries': <Object>[]},
          });
    final r = repo();
    const item = GradebookColumn(
      key: 'i60',
      type: ColumnType.custom,
      id: 60,
      name: 'x',
    );
    const exam = GradebookColumn(
      key: 'a51',
      type: ColumnType.manualExam,
      id: 51,
      name: 'y',
    );
    await r.saveScores(item, [
      const ScoreChange.score(101, 8.5),
      const ScoreChange.score(102, null),
      const ScoreChange.excused(103, true),
      const ScoreChange(
        studentId: 104,
        score: 3,
        setScore: true,
        excused: false,
      ),
    ]);
    expect(sent.single.path, '/gradebook-items/60/scores');
    expect(sent.single.body, {
      'scores': [
        {'student_id': 101, 'score': 8.5},
        {'student_id': 102, 'score': null},
        {'student_id': 103, 'excused': true},
        {'student_id': 104, 'score': 3.0, 'excused': false},
      ],
    });

    await r.saveScores(exam, [
      for (var i = 0; i < 130; i++) ScoreChange.score(i + 1, 1),
    ]);
    expect(sent.skip(1).map((s) => s.path), [
      '/assignments/51/gradebook-scores',
      '/assignments/51/gradebook-scores',
    ]);
    expect((sent[1].body['scores'] as List), hasLength(100));
    expect((sent[2].body['scores'] as List), hasLength(30));

    expect(await r.fillFull(item), 5);
    expect(sent.last.path, '/gradebook-items/60/fill-full');
    await r.fillFull(exam);
    expect(sent.last.path, '/assignments/51/gradebook-scores/fill-full');
  });

  test('ร/มส, publish and withdraw', () async {
    answer = (method, path) async => switch ((method, path)) {
      ('POST', _) => jsonResponse(201, {
        'data': {
          'publication_id': 3,
          'published_at': '2026-09-30T03:00:00+00:00',
          'student_count': 31,
        },
      }),
      ('DELETE', _) => jsonResponse(204, null),
      _ => jsonResponse(200, {
        'data': {'student_id': 101, 'special': 'ms', 'note': 'ขาด'},
      }),
    };
    final r = repo();
    await r.setSpecialGrade(
      4,
      classroomId: 7,
      studentId: 101,
      special: 'ms',
      note: ' ขาดเรียนเกิน ',
    );
    expect(sent.last.path, '/courses/4/gradebook/special-grades');
    expect(sent.last.body, {
      'classroom_id': 7,
      'student_id': 101,
      'special': 'ms',
      'note': 'ขาดเรียนเกิน',
    });
    await r.setSpecialGrade(4, classroomId: 7, studentId: 101, note: 'x');
    expect(sent.last.body, {
      'classroom_id': 7,
      'student_id': 101,
      'special': null,
    });

    final p = await r.publish(4, 7);
    expect(sent.last.path, '/courses/4/gradebook/publish');
    expect(sent.last.body, {'classroom_id': 7});
    expect(p.publicationId, 3);
    expect(p.studentCount, 31);
    expect(p.publishedAt, DateTime.utc(2026, 9, 30, 3));

    await r.withdraw(4, 7);
    expect(sent.last.method, 'DELETE');
    expect(sent.last.query, {'classroom_id': '7'});
  });

  test('the CSV keeps its bytes and the Thai file name', () async {
    final csv = utf8.encode('﻿เลขที่,ชื่อ\r\n1,ด.ญ. เอ\r\n');
    answer = (_, _) async => ResponseBody.fromBytes(
      csv,
      200,
      headers: {
        'content-type': ['text/csv; charset=UTF-8'],
        'content-disposition': [
          "attachment; filename=gradebook-4-7.csv; filename*=utf-8''"
              '${Uri.encodeComponent('gradebook-ค15101-ป.5/1.csv')}',
        ],
      },
    );
    final r = repo();
    final file = await r.exportCsv(4, 7);
    expect(sent.single.path, '/courses/4/gradebook/export');
    expect(sent.single.query, {'classroom_id': '7'});
    expect(file.fileName, 'gradebook-ค15101-ป.5/1.csv');
    expect(file.bytes.take(3), [0xEF, 0xBB, 0xBF]);
    expect(file.bytes, csv);
  });

  test('an export error body is readable although it came as bytes', () async {
    answer = (_, _) async => ResponseBody.fromBytes(
      utf8.encode(
        jsonEncode({
          'message': 'รายวิชานี้ยังไม่ได้ตั้งค่าสมุดคะแนน',
          'errors': <String, Object>{},
          'code': 'gradebook_not_configured',
        }),
      ),
      409,
      headers: {
        'content-type': ['application/json'],
      },
    );
    final r = repo();
    try {
      await r.exportCsv(4, 7);
      fail('expected a 409');
    } on DioException catch (e) {
      expect(apiErrorCode(e), 'gradebook_not_configured');
      expect(apiErrorMessage(e), 'รายวิชานี้ยังไม่ได้ตั้งค่าสมุดคะแนน');
    }
  });

  test('student grades and the breakdown of one course', () async {
    answer = (_, path) async => path == '/student/grades'
        ? jsonResponse(200, {
            'data': [
              {
                'course': {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
                'classroom_id': 7,
                'published_at': '2026-09-30T03:00:00+00:00',
                'grade': 3.5,
                'special': null,
                'total_rounded': 78,
              },
            ],
          })
        : jsonResponse(200, {'data': myGradeJson()});
    final r = repo();
    final list = await r.myGrades();
    expect(list.single.courseTitle, 'ค15101 คณิตศาสตร์ 5');
    expect(list.single.gradeText, '3.5');
    expect(list.single.totalRounded, 78);

    final d = await r.myCourseGrade(4);
    expect(sent.last.path, '/student/courses/4/grade');
    expect(sent.last.query, isEmpty);
    expect(d.summary.classroom?.text, 'ป.5/1 · 2569');
    expect(d.total, 73.8);
    expect(d.summary.gradeText, '3');
    expect(d.breakdown.first.items[1].dropped, isTrue);
    expect(d.breakdown.first.items[2].state, CellState.excused);
    expect(d.breakdown.last.percent, isNull);

    // One classroom's publication (DESIGN §24.26).
    await r.myCourseGrade(4, classroomId: 7);
    expect(sent.last.query, {'classroom_id': '7'});
  });
}
