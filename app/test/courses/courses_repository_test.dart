import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_api_server.dart';
import '../helpers/fake_http_adapter.dart';
import 'course_fakes.dart';

/// Request and response shapes of the course endpoints (DESIGN §20.7).
void main() {
  late List<(String, String, Map<String, dynamic>, Map<String, String>)> sent;
  late Object? Function(String method, String path) answer;

  ApiCoursesRepository repo() {
    sent = [];
    final adapter = FakeHttpAdapter((o) async {
      final path = FakeApiServer.apiPath(o.uri).replaceFirst('/api/v1', '');
      sent.add((
        o.method,
        path,
        FakeApiServer.bodyOf(o),
        o.uri.queryParameters,
      ));
      final body = answer(o.method, path);
      return body == null
          ? jsonResponse(204, null)
          : jsonResponse(o.method == 'POST' ? 201 : 200, body);
    });
    return ApiCoursesRepository(fakeDio(adapter));
  }

  setUp(() {
    answer = (_, _) => {'data': courseJson()};
  });

  test('list filters by classroom and parses the summary', () async {
    answer = (_, _) => {
      'data': [
        {...courseJson(), 'indicators': null, 'indicator_count': 12},
      ],
    };
    final r = repo();
    final list = await r.list(classroomId: 7);
    expect(sent.single.$2, '/courses');
    expect(sent.single.$4, {'classroom_id': '7'});
    final c = list.single;
    expect(c.title, 'ค15101 คณิตศาสตร์ 5');
    expect(c.termLabel, 'ป.5 · ภาคเรียนที่ 1 · 2569');
    expect(c.indicatorCount, 12);
    expect(c.classroomIds, [7]);
    expect(c.subjectName, 'คณิตศาสตร์');

    await r.list();
    expect(sent.last.$4, isEmpty);
  });

  test('create sends the course with its classrooms and indicators', () async {
    final r = repo();
    await r.create(
      const CourseDraft(
        code: 'ค15101',
        name: 'คณิตศาสตร์ 5',
        subjectId: 1,
        gradeLevel: 5,
        semester: 0,
        academicYear: 2569,
        hours: 160,
      ),
      classroomIds: [7, 8],
      skillIds: [1, 2],
    );
    expect(sent.single.$1, 'POST');
    expect(sent.single.$2, '/courses');
    expect(sent.single.$3, {
      'code': 'ค15101',
      'name': 'คณิตศาสตร์ 5',
      'subject_id': 1,
      'grade_level': 5,
      'semester': 0,
      'academic_year': 2569,
      'hours': 160,
      'description': null,
      'classroom_ids': [7, 8],
      'skill_ids': [1, 2],
    });
  });

  test('course, unit and plan edits use their own paths', () async {
    final r = repo();
    answer = (method, path) => method == 'DELETE'
        ? null
        : path.startsWith('/units') || path.endsWith('/units')
        ? {'data': unitJson(21)}
        : path.contains('lesson-plans')
        ? {'data': planJson(31, taughtOn: '2026-09-30')}
        : {'data': courseJson()};

    await r.setClassrooms(4, [7]);
    await r.setIndicators(4, [1]);
    await r.addUnit(
      4,
      const UnitDraft(title: 'เศษส่วน', indicators: [fraction]),
    );
    await r.updateUnit(21, const UnitDraft(title: 'เศษส่วน', hours: 10));
    await r.deleteUnit(21);
    final plan = await r.addPlan(
      4,
      PlanDraft(
        title: 'การบวกเศษส่วน',
        unit: const UnitRef.existing(21),
        taughtOn: DateTime(2026, 9, 30),
        indicators: const [decimal],
      ),
    );
    await r.updatePlan(31, const PlanDraft(title: 'ใหม่'));
    await r.deletePlan(31);
    await r.delete(4);

    expect(
      [for (final s in sent) '${s.$1} ${s.$2}'],
      [
        'PUT /courses/4/classrooms',
        'PUT /courses/4/indicators',
        'POST /courses/4/units',
        'PATCH /units/21',
        'DELETE /units/21',
        'POST /courses/4/lesson-plans',
        'PATCH /lesson-plans/31',
        'DELETE /lesson-plans/31',
        'DELETE /courses/4',
      ],
    );
    expect(sent[0].$3, {
      'classroom_ids': [7],
    });
    expect(sent[1].$3, {
      'skill_ids': [1],
    });
    expect(sent[2].$3['skill_ids'], [1]);
    expect(sent[5].$3, {
      'title': 'การบวกเศษส่วน',
      'unit_id': 21,
      'hours': null,
      'objectives': null,
      'content': null,
      'activities': null,
      'assessment': null,
      'taught_on': '2026-09-30',
      'skill_ids': [2],
    });
    expect(sent[6].$3['taught_on'], isNull, reason: 'not taught = cleared');
    expect(plan.taughtOn, DateTime(2026, 9, 30));
    expect(plan.label, 'แผนที่ 1 การบวกเศษส่วน');
  });

  test('estimate, extract and poll a course document', () async {
    final r = repo();
    answer = (method, path) => switch (path) {
      '/courses/extract/estimate' => {
        'data': {
          'purpose': 'course',
          'pages': 3,
          'cached': false,
          'estimate': {
            'input_tokens': 1680,
            'output_tokens': 16384,
            'thb': 0.5,
          },
        },
      },
      '/courses/extract' => {
        'data': {
          'cached': false,
          'estimate': {
            'input_tokens': 1680,
            'output_tokens': 16384,
            'thb': 0.5,
          },
          'extraction': {'id': 70, 'purpose': 'course', 'status': 'queued'},
          'result': null,
          'indicator_matches': <Object>[],
        },
      },
      _ => {
        'data': {
          'id': 70,
          'purpose': 'course',
          'status': 'done',
          'error': null,
          'result': {
            'kind': 'course',
            'notes_th': 'หน้า 2 อ่านไม่ชัด',
            'course': {'code': 'ค15101', 'grade_level': 5, 'subject_code': 'ค'},
            'indicators': [
              {'code': 'ค 1.1 ป.5/1', 'text': 'บวกลบเศษส่วน'},
              {'code': 'ค 9.9 ป.5/9', 'text': 'ไม่มีในหลักสูตร'},
            ],
            'units': [
              {
                'position': 1,
                'title': 'เศษส่วน',
                'hours': 12,
                'indicator_codes': ['ค 1.1 ป.5/1'],
              },
            ],
            'lesson_plans': [
              {'position': 1, 'unit_position': 1, 'title': 'บวกเศษส่วน'},
            ],
          },
          'indicator_matches': [
            {'code': 'ค 1.1 ป.5/1', 'skill': skillJson(fraction)},
            {'code': 'ค 9.9 ป.5/9', 'skill': null},
          ],
        },
      },
    };

    final estimate = await r.estimate(
      purpose: CourseDocumentPurpose.course,
      documentIds: [5],
      pageFrom: 1,
      pageTo: 3,
    );
    expect(estimate.pages, 3);
    expect(sent.last.$3, {
      'purpose': 'course',
      'document_ids': [5],
      'page_from': 1,
      'page_to': 3,
    });

    final queued = await r.extract(
      purpose: CourseDocumentPurpose.lessonPlan,
      documentIds: [5],
    );
    expect(sent.last.$3, {
      'purpose': 'lesson_plan',
      'document_ids': [5],
    });
    expect(queued.id, 70);
    expect(queued.done, isFalse);
    expect(queued.estimate?.thb, 0.5);

    final done = await r.extraction(70);
    expect(sent.last.$2, '/document-extractions/70');
    expect(done.done, isTrue);
    expect(done.result!.notesTh, 'หน้า 2 อ่านไม่ชัด');
    expect(done.result!.course.subjectCode, 'ค');
    expect(done.result!.units.single.indicatorCodes, ['ค 1.1 ป.5/1']);
    expect(done.result!.lessonPlans.single.unitPosition, 1);
    expect(done.matchMap, {'ค 1.1 ป.5/1': fraction, 'ค 9.9 ป.5/9': null});
  });

  test('import sends the confirmed form with unit_index and unit_id', () async {
    final r = repo();
    await r.import(
      const CourseImport(
        extractionId: 70,
        courseId: 4,
        classroomIds: [7],
        indicators: [fraction],
        units: [
          UnitDraft(title: 'ทศนิยม', indicators: [decimal]),
        ],
        lessonPlans: [
          PlanDraft(title: 'แผนในหน่วยใหม่', unit: UnitRef.draft(0)),
          PlanDraft(title: 'แผนในหน่วยเดิม', unit: UnitRef.existing(21)),
          PlanDraft(title: 'แผนลอย'),
        ],
      ),
    );
    final body = sent.single.$3;
    expect(sent.single.$2, '/courses/import');
    expect(body['extraction_id'], 70);
    expect(body['course_id'], 4);
    expect(body.containsKey('course'), isFalse);
    expect(body['skill_ids'], [1]);
    expect(body['units'], [
      {
        'title': 'ทศนิยม',
        'hours': null,
        'description': null,
        'skill_ids': [2],
      },
    ]);
    final plans = (body['lesson_plans'] as List).cast<Map>();
    expect(plans[0]['unit_index'], 0);
    expect(plans[0].containsKey('unit_id'), isFalse);
    expect(plans[1]['unit_id'], 21);
    expect(plans[2].containsKey('unit_index'), isFalse);
    expect(plans[2].containsKey('unit_id'), isFalse);
  });

  test('addIndicator posts a school indicator under its parent', () async {
    final r = repo();
    answer = (_, _) => {'data': skillJson(schoolSkill)};
    final added = await r.addIndicator(parentId: 11, name: 'ใหม่', code: ' ');
    expect(sent.single.$2, '/skills');
    expect(sent.single.$3, {'parent_id': 11, 'name': 'ใหม่'});
    expect(added.sourceLabel, 'ครูเพิ่มเอง');
    expect(added.level, 'indicator');
  });

  test('a course detail sorts units and plans and groups plans by unit', () {
    final c = Course.fromJson(
      courseJson(
        units: [
          unitJson(22, position: 2, title: 'ข'),
          unitJson(21),
        ],
        plans: [
          planJson(33, unitId: 22, position: 3),
          planJson(31, unitId: 21),
          planJson(32, position: 2),
        ],
      ),
    );
    expect([for (final u in c.units) u.id], [21, 22]);
    expect([for (final p in c.plansOf(21)) p.id], [31]);
    expect([for (final p in c.plansOf(null)) p.id], [32]);
    expect(c.units.first.label, 'หน่วยที่ 1 เศษส่วน');
    expect(semesterLabel(0), 'ทั้งปี');
    expect(parseApiDate('2026-09-30'), DateTime(2026, 9, 30));
    expect(parseApiDate(null), isNull);
    expect(apiDate(DateTime(2026, 1, 5)), '2026-01-05');
  });
}
