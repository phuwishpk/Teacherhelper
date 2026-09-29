import 'dart:typed_data';

import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/document_read_screen.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:eduvision/features/courses/course_detail_screen.dart';
import 'package:eduvision/features/courses/course_import_screen.dart';
import 'package:eduvision/features/courses/course_item_forms.dart';
import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/courses/courses_providers.dart';
import 'package:eduvision/features/courses/courses_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

import '../assignments/answer_key_fixtures.dart';
import '../helpers/pump_screen.dart';
import 'course_fakes.dart';

const _pdf = SourceDocument(
  id: 5,
  originalName: 'คำอธิบายรายวิชา.pdf',
  mimeType: 'application/pdf',
  pageCount: 3,
  sizeBytes: 240000,
);

Map<String, dynamic> _readCourse() => {
  'kind': 'course',
  'notes_th': 'หน้า 3 อ่านไม่ชัด',
  'course': {
    'code': 'ค15101',
    'name': 'คณิตศาสตร์ 5',
    'subject_code': 'ค',
    'grade_level': 5,
    'semester': 1,
    'academic_year': 2569,
    'hours': 160,
    'description': 'ศึกษาเศษส่วนและทศนิยม',
  },
  'indicators': [
    {'code': 'ค 1.1 ป.5/1', 'text': 'บวกลบเศษส่วน'},
    {'code': 'ค 1.1 ป.5/9', 'text': 'ใช้เศษส่วนแก้ปัญหา'},
  ],
  'units': [
    {
      'position': 1,
      'title': 'เศษส่วน',
      'hours': 12,
      'description': null,
      'indicator_codes': ['ค 1.1 ป.5/1', 'ค 1.1 ป.5/9'],
    },
  ],
  'lesson_plans': [
    {
      'position': 1,
      'unit_position': 1,
      'title': 'การบวกเศษส่วน',
      'hours': 2,
      'objectives': 'บวกเศษส่วนได้',
      'indicator_codes': ['ค 1.1 ป.5/9'],
    },
  ],
};

CourseExtraction _extraction(
  String status, {
  Map<String, dynamic>? result,
  List<Map<String, dynamic>> matches = const [],
  bool cached = false,
  String? error,
}) => CourseExtraction.fromJson({
  'cached': cached,
  'extraction': {'id': 70, 'status': status, 'error': error},
  'result': result,
  'indicator_matches': matches,
});

List<Map<String, dynamic>> _matches() => [
  {'code': 'ค 1.1 ป.5/1', 'skill': skillJson(fraction)},
  {'code': 'ค 1.1 ป.5/9', 'skill': null},
];

List<Override> _flowOverrides(
  FakeCoursesRepository courses,
  FakeAnswerKeys keys,
  FakeDocumentPicker picker,
) => [
  ...overrides(courses, skills: FakeSkills()),
  answerKeyRepositoryProvider.overrideWithValue(keys),
  documentFilePickerProvider.overrideWithValue(picker),
  courseExtractionPollIntervalProvider.overrideWithValue(
    const Duration(milliseconds: 50),
  ),
];

void main() {
  testWidgets('a new course from a document: upload, cost, wait, resolve an '
      'unknown indicator, confirm', (tester) async {
    tall(tester);
    final courses = FakeCoursesRepository()
      ..extractResult = _extraction('queued')
      ..polls = [
        _extraction('queued'),
        _extraction('done', result: _readCourse(), matches: _matches()),
      ];
    final keys = FakeAnswerKeys(answerKeyState())..uploadResult = const [_pdf];
    final picker = FakeDocumentPicker([
      PickedDocument(name: 'คำอธิบายรายวิชา.pdf', bytes: Uint8List(4)),
    ]);
    await pumpScreen(
      tester,
      const CoursesScreen(),
      overrides: _flowOverrides(courses, keys, picker),
      extraRoutes: [stubRoute('/courses/:id', 'detail')],
    );

    await tester.tap(find.text('สร้างรายวิชา'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('new_course_document')));
    await tester.pumpAndSettle();
    expect(find.textContaining('ห้ามมีชื่อ เลขที่'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('course_doc_file')));
    await tester.pumpAndSettle();

    expect(keys.uploads.single.single.name, 'คำอธิบายรายวิชา.pdf');
    expect(find.byType(DocumentReadScreen), findsOneWidget);
    expect(
      find.text('ส่งให้ AI อ่านคำอธิบายหรือโครงสร้างรายวิชา'),
      findsWidgets,
    );
    expect(find.textContaining('ห้ามมีชื่อ เลขที่'), findsOneWidget);
    expect(find.textContaining('ประมาณ 0.52 บาท'), findsOneWidget);
    expect(courses.estimates.single, {
      'purpose': 'course',
      'document_ids': [5],
      'page_from': null,
      'page_to': null,
    });
    await tester.tap(find.byKey(const ValueKey('send_key_request')));
    await tester.pump();
    await tester.pump();
    expect(find.textContaining('AI กำลังอ่านเอกสาร'), findsOneWidget);
    await tester.pumpAndSettle();

    expect(courses.calls.where((c) => c == 'poll:70'), hasLength(2));
    expect(find.byType(CourseImportScreen), findsOneWidget);
    expect(find.text('หมายเหตุจาก AI: หน้า 3 อ่านไม่ชัด'), findsOneWidget);
    expect(find.text('ค15101'), findsOneWidget);
    expect(find.text('คณิตศาสตร์'), findsOneWidget, reason: 'subject by code');
    expect(find.text('ค 1.1 ป.5/9 (ไม่พบ)'), findsOneWidget);
    expect(
      find.text(
        'ไม่พบ 1 รหัสในหลักสูตรของโรงเรียน '
        'เลือกตัวที่ตรงกัน หรือเพิ่มเป็นตัวชี้วัดของโรงเรียน',
      ),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('add_indicator_ค 1.1 ป.5/9')));
    await tester.pumpAndSettle();
    expect(find.text('ใช้เศษส่วนแก้ปัญหา'), findsWidgets);
    await tester.tap(find.widgetWithText(RadioListTile<int>, 'ค 1.1'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('add_indicator_save')));
    await tester.pumpAndSettle();
    expect(find.text('ค 1.1 ป.5/9 (ไม่พบ)'), findsNothing);

    await tester.tap(find.text('ป.5/2'));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('course_import_save')));
    await tester.pumpAndSettle();

    final sent = courses.imports.single.toJson();
    expect(sent['extraction_id'], 70);
    expect(sent['course'], {
      'code': 'ค15101',
      'name': 'คณิตศาสตร์ 5',
      'subject_id': 1,
      'grade_level': 5,
      'semester': 1,
      'academic_year': 2569,
      'hours': 160,
      'description': 'ศึกษาเศษส่วนและทศนิยม',
    });
    expect(sent['classroom_ids'], [8]);
    expect(sent['skill_ids'], [1, 90]);
    expect(sent['units'], [
      {
        'title': 'เศษส่วน',
        'hours': 12,
        'description': null,
        'skill_ids': [1, 90],
      },
    ]);
    final plan = (sent['lesson_plans'] as List).single as Map;
    expect(plan['unit_index'], 0);
    expect(plan['skill_ids'], [90]);
    expect(plan['objectives'], 'บวกเศษส่วนได้');
    expect(find.text('detail /courses/500'), findsOneWidget);
  });

  testWidgets('lesson plans read for an existing course reuse its units and '
      'warn about codes left unmatched', (tester) async {
    tall(tester);
    final existing = course(id: 4, units: [unitJson(21, title: 'เศษส่วน')]);
    final courses = FakeCoursesRepository([existing]);
    final read = _readCourse()
      ..['kind'] = 'lesson_plan'
      ..['units'] = [
        {'position': 1, 'title': 'เศษส่วน', 'indicator_codes': <String>[]},
        {'position': 2, 'title': 'ทศนิยม', 'indicator_codes': <String>[]},
      ]
      ..['lesson_plans'] = [
        {'position': 1, 'unit_position': 1, 'title': 'บวกเศษส่วน'},
        {
          'position': 2,
          'unit_position': 2,
          'title': 'อ่านทศนิยม',
          'indicator_codes': ['ค 1.1 ป.5/9'],
        },
      ];
    await pumpScreen(
      tester,
      CourseImportScreen(
        extraction: _extraction(
          'done',
          cached: true,
          result: read,
          matches: _matches(),
        ),
        purpose: CourseDocumentPurpose.lessonPlan,
        course: existing,
      ),
      overrides: overrides(courses),
    );
    expect(find.text('ตรวจแผนการสอนที่อ่านได้'), findsOneWidget);
    expect(find.text('หน่วยใหม่ (1)'), findsOneWidget);
    expect(find.text('หน่วยที่ 2 ทศนิยม'), findsOneWidget);
    expect(find.text('หน่วยที่ 1 เศษส่วน · 2 ชั่วโมง'), findsNothing);
    expect(find.textContaining('หน่วยที่ 1 เศษส่วน'), findsOneWidget);
    expect(find.textContaining('หน่วยใหม่: ทศนิยม'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('course_import_save')));
    await tester.pumpAndSettle();
    expect(find.text('ยังมีตัวชี้วัดที่ไม่พบ 1 รหัส'), findsOneWidget);
    await tester.tap(find.text('บันทึกต่อ'));
    await tester.pumpAndSettle();

    final sent = courses.imports.single.toJson();
    expect(sent['course_id'], 4);
    expect(sent.containsKey('course'), isFalse);
    expect(sent['skill_ids'], [1], reason: 'the unmatched code is dropped');
    expect(
      [for (final u in sent['units'] as List) (u as Map)['title']],
      ['ทศนิยม'],
    );
    final plans = (sent['lesson_plans'] as List).cast<Map>();
    expect(plans[0]['unit_id'], 21);
    expect(plans[1]['unit_index'], 0);
    expect(plans[1]['skill_ids'], <int>[]);
    expect(find.byType(CourseImportScreen), findsNothing);
  });

  testWidgets('edits and removes read units and plans before saving', (
    tester,
  ) async {
    tall(tester);
    final courses = FakeCoursesRepository();
    await pumpScreen(
      tester,
      CourseImportScreen(
        extraction: _extraction(
          'done',
          result: _readCourse(),
          matches: [
            {'code': 'ค 1.1 ป.5/1', 'skill': skillJson(fraction)},
            {'code': 'ค 1.1 ป.5/9', 'skill': skillJson(decimal)},
          ],
        ),
        purpose: CourseDocumentPurpose.course,
      ),
      overrides: overrides(courses),
    );

    await tester.tap(find.byKey(const ValueKey('import_plan_0')));
    await tester.pumpAndSettle();
    expect(find.byType(LessonPlanFormScreen), findsOneWidget);
    await tester.enterText(
      find.byKey(const ValueKey('plan_title')),
      'บวกและลบเศษส่วน',
    );
    await tester.ensureVisible(find.byKey(const ValueKey('plan_save')));
    await tester.tap(find.byKey(const ValueKey('plan_save')));
    await tester.pumpAndSettle();
    expect(find.text('บวกและลบเศษส่วน'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('import_unit_0')));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('ลบหน่วย'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ลบ'));
    await tester.pumpAndSettle();
    expect(find.text('หน่วยการเรียนรู้ (0)'), findsOneWidget);
    expect(
      find.textContaining('ไม่อยู่ในหน่วย'),
      findsOneWidget,
      reason: 'its plan left the removed unit',
    );

    await tester.tap(find.text('เพิ่มแผน'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('plan_title')), 'ทบทวน');
    await tester.ensureVisible(find.byKey(const ValueKey('plan_save')));
    await tester.tap(find.byKey(const ValueKey('plan_save')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('course_import_save')));
    await tester.pumpAndSettle();
    final sent = courses.imports.single.toJson();
    expect(sent['units'], isEmpty);
    expect(
      [for (final p in sent['lesson_plans'] as List) (p as Map)['title']],
      ['บวกและลบเศษส่วน', 'ทบทวน'],
    );
    expect((sent['lesson_plans'] as List).first, isNot(contains('unit_index')));
  });

  testWidgets('a failed read explains itself', (tester) async {
    await pumpScreen(
      tester,
      CourseImportScreen(
        extraction: _extraction(
          'failed',
          error: 'AI อ่านเอกสารไม่สำเร็จ ลองถ่ายรูปให้ชัดขึ้น',
        ),
        purpose: CourseDocumentPurpose.course,
      ),
      overrides: overrides(FakeCoursesRepository()),
    );
    expect(
      find.text('AI อ่านเอกสารไม่สำเร็จ ลองถ่ายรูปให้ชัดขึ้น'),
      findsOneWidget,
    );
  });

  testWidgets('"นำเข้าแผนจากเอกสาร" reads lesson plans into the course', (
    tester,
  ) async {
    tall(tester);
    final existing = course(id: 4);
    final courses = FakeCoursesRepository([existing])
      ..extractResult = _extraction(
        'done',
        cached: true,
        result: _readCourse()..['kind'] = 'lesson_plan',
        matches: [
          {'code': 'ค 1.1 ป.5/1', 'skill': skillJson(fraction)},
          {'code': 'ค 1.1 ป.5/9', 'skill': skillJson(decimal)},
        ],
      )
      ..estimateResult = const KeyEstimate(
        pages: 3,
        cached: true,
        estimate: CostEstimate(inputTokens: 0, outputTokens: 0),
      );
    final keys = FakeAnswerKeys(answerKeyState())..uploadResult = const [_pdf];
    final picker = FakeDocumentPicker([
      PickedDocument(name: 'แผน.pdf', bytes: Uint8List(4)),
    ]);
    await pumpScreen(
      tester,
      const CourseDetailScreen(courseId: 4),
      overrides: _flowOverrides(courses, keys, picker),
    );
    await tester.tap(find.byKey(const ValueKey('course_import_plans')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('course_doc_file')));
    await tester.pumpAndSettle();
    expect(
      find.text('โรงเรียนเคยอ่านไฟล์นี้แล้ว ไม่เสียค่าใช้จ่าย'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('send_key_request')));
    await tester.pumpAndSettle();
    expect(courses.extracts.single['purpose'], 'lesson_plan');
    expect(find.text('ตรวจแผนการสอนที่อ่านได้'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('course_import_save')));
    await tester.pumpAndSettle();
    expect(courses.imports.single.courseId, 4);
    expect(find.byType(CourseDetailScreen), findsOneWidget);
  });
}
