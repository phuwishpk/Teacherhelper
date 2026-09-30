import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_fakes.dart';

/// The Dart side of DESIGN §22.3 (numeric canonical form and "fits") and
/// §22.5 (lock suggestion), plus the JSON of §22.15.
void main() {
  group('NumericAnswer.canonical (same rules as the server)', () {
    const cases = {
      '0.5': '0.5',
      '.5': '0.5',
      '00.50': '0.5',
      '5.': '5',
      '007': '7',
      '0': '0',
      '000': '0',
      '-0': '0',
      '-0.00': '0',
      '-12.30': '-12.3',
      ' 3 ': '3',
      '10': '10',
    };
    for (final c in cases.entries) {
      test('"${c.key}" -> "${c.value}"', () {
        expect(NumericAnswer.canonical(c.key), c.value);
      });
    }
    for (final bad in [
      '',
      '-',
      '.',
      '-.',
      '1.2.3',
      'abc',
      '1,5',
      '+3',
      '1e3',
    ]) {
      test('"$bad" is not a number', () {
        expect(NumericAnswer.canonical(bad), isNull);
      });
    }
  });

  group('NumericAnswer.fits', () {
    const plain = NumericSpec(digits: 2);
    const decimal = NumericSpec(digits: 2, allowDecimal: true);
    const signed = NumericSpec(digits: 3, allowNegative: true);

    test('whole numbers fill every column', () {
      expect(NumericAnswer.fits('99', plain), isTrue);
      expect(NumericAnswer.fits('100', plain), isFalse);
    });

    test('a decimal needs the point column; below 1 drops the 0', () {
      expect(NumericAnswer.problem('0.5', plain), contains('ทศนิยม'));
      expect(NumericAnswer.fits('0.5', decimal), isTrue); // ".5" = 2 columns
      expect(NumericAnswer.fits('0.25', decimal), isTrue); // ".25" = 3
      expect(NumericAnswer.fits('1.5', decimal), isTrue); // "1.5" = 3
      expect(NumericAnswer.fits('1.25', decimal), isFalse); // 4 > 3
      expect(NumericAnswer.fits('123', decimal), isTrue); // 3 columns
    });

    test('a negative needs the sign column', () {
      expect(NumericAnswer.problem('-3', plain), contains('ติดลบ'));
      expect(NumericAnswer.fits('-123', signed), isTrue);
      expect(NumericAnswer.problem('-1234', signed), contains('ยาวเกิน'));
    });
  });

  test('parseList splits commas, canonicalises and deduplicates', () {
    const spec = NumericSpec(digits: 3, allowDecimal: true);
    final ok = NumericAnswer.parseList('0.33, .330 ,0.333，1', spec);
    expect(ok.error, isNull);
    expect(ok.values, ['0.33', '0.333', '1']);

    expect(NumericAnswer.parseList('', spec).values, isEmpty);
    expect(NumericAnswer.parseList('x', spec).error, contains('ไม่ใช่ตัวเลข'));
    expect(NumericAnswer.parseList('-1', spec).error, contains('ติดลบ'));
    expect(
      NumericAnswer.parseList(List.filled(11, '1').join(','), spec).error,
      contains('ไม่เกิน 10'),
    );
  });

  group('LockOptionsDetector', () {
    for (final text in [
      'ถูกทุกข้อ',
      'ผิด ทุก ข้อ',
      'ไม่มีข้อใดถูก',
      'ถูกทั้ง ก และ ข',
      'ทั้ง ก และ ข',
      'ข้อ ก และ ข้อ ค',
      'ก, ข และ ค ถูก',
      'ก และ ค ถูกต้อง',
      'All of the above',
      'none of the above.',
      'A and B',
      'Both A and C',
    ]) {
      test('suggests for "$text"', () {
        expect(LockOptionsDetector.refersToOthers(text), isTrue);
      });
    }
    for (final text in [
      '',
      'แมว',
      'ก',
      'กบ และ ขนม',
      'ข้อความยาวที่มีคำว่า ก ในประโยค',
      'Apple',
      '12',
    ]) {
      test('does not suggest for "$text"', () {
        expect(LockOptionsDetector.refersToOthers(text), isFalse);
      });
    }
    test('suggests when any option refers to the others', () {
      expect(LockOptionsDetector.suggests(['1', null, 'ถูกทุกข้อ']), isTrue);
      expect(LockOptionsDetector.suggests(['1', null, '2']), isFalse);
    });
  });

  test('ExamDetail reads GET /exams/{id}', () {
    final d = ExamDetail.fromJson(examJson());
    expect(d.exam.isExam, isTrue);
    expect(d.exam.versionCount, 2);
    expect(d.exam.durationMinutes, 60);
    expect(d.gradingMethod, ExamGradingMethod.app);
    expect(d.sections.map((s) => s.type), [
      ExamSectionType.mcq,
      ExamSectionType.trueFalse,
      ExamSectionType.numeric,
    ]);
    final mcq = d.sections.first;
    expect(mcq.heading, 'ตอนที่ 1 ปรนัย');
    expect(mcq.typeSummary, 'ปรนัย 4 ตัวเลือก');
    expect(mcq.numberRange, 'ข้อ 1–2');
    expect(mcq.defaultPoints, 1);
    expect(d.sections[1].heading, 'ตอนที่ 2');
    expect(d.sections[1].choiceCount, 2);
    expect(d.sections[2].typeSummary, 'เติมตัวเลข 2 หลัก · มีทศนิยม');
    expect(d.sections[2].numeric!.columns, 3);
    expect(d.questionCount, 4);
    expect(d.totalPoints, 5);
    final q11 = d.question(11)!;
    expect(q11.options.map((o) => o.label), ['ก', 'ข', 'ค', 'ง']);
    expect(q11.key!.describe(q11.type), 'ค');
    expect(d.question(12)!.blank, isTrue);
    expect(d.question(12)!.approved, isFalse);
    expect(d.question(21)!.key!.describe(ExamSectionType.trueFalse), 'ผิด');
    expect(d.question(31)!.key!.values, ['0.5']);
    expect(d.sectionOf(31)!.id, 3);
    expect(d.incomplete.single.summary, 'ข้อ 2: ยังไม่อนุมัติ, ยังไม่มีเฉลย');
    expect(d.structureLocked, isFalse);
    expect(d.sheetPages, 1);
  });

  test('Assignment reads the exam fields; homework keeps the defaults', () {
    final exam = Assignment.fromJson({
      ...(examJson(method: 'manual', lockedAt: '2026-10-01T00:00:00Z')['exam']
          as Map<String, dynamic>),
    });
    expect(exam.isManualExam, isTrue);
    expect(exam.manualFullMarks, 30);
    expect(exam.structureLockedAt, isNotNull);
    expect(exam.withGoogleLink(null).kind, Assignment.kindExam);

    final hw = Assignment.fromJson({
      'id': 1,
      'classroom_id': 7,
      'subject_id': 1,
      'title': 'การบ้าน',
    });
    expect(hw.isExam, isFalse);
    expect(hw.versionCount, 1);
  });

  test('ExamKey JSON per type and equality', () {
    const options = ExamKey(options: [2, 4]);
    expect(options.toJson(ExamSectionType.mcq), {
      'accepted_options': [2, 4],
    });
    expect(options.describe(ExamSectionType.mcq), 'ข, ง');
    const values = ExamKey(values: ['0.33', '0.333']);
    expect(values.toJson(ExamSectionType.numeric), {
      'accepted_values': ['0.33', '0.333'],
    });
    expect(values.describe(ExamSectionType.numeric), '0.33 หรือ 0.333');
    expect(const ExamKey().toJson(ExamSectionType.mcq), isNull);
    expect(ExamKey.fromJson({'accepted_options': []}), isNull);
    expect(
      ExamKey.fromJson({
        'accepted_options': [4, 2],
      }),
      options,
    );
    expect(options.hashCode, const ExamKey(options: [2, 4]).hashCode);
  });

  test('ExamSettingsDraft create and update bodies', () {
    final date = DateTime.utc(2026, 10, 15, 16, 59);
    final draft = ExamSettingsDraft(
      title: 'สอบ',
      examDate: date,
      classroomId: 7,
      courseId: 3,
      durationMinutes: 50,
      gradingMethod: ExamGradingMethod.manual,
      versionCount: 3,
      manualFullMarks: 40,
    );
    expect(draft.toCreateJson(), {
      'kind': 'exam',
      'classroom_id': 7,
      'course_id': 3,
      'title': 'สอบ',
      'due_at': '2026-10-15T16:59:00.000Z',
      'mode': 'worksheet',
      'grading_method': 'manual',
      'version_count': 3,
      'duration_minutes': 50,
      'show_key_to_students': false,
      'manual_full_marks': 40.0,
    });

    final current = ExamDetail.fromJson(examJson()).exam;
    final same = ExamSettingsDraft(
      title: current.title,
      examDate: current.dueAt!,
      courseId: current.courseId,
      durationMinutes: current.durationMinutes,
      versionCount: current.versionCount,
    );
    expect(same.toUpdateJson(current), isEmpty);
    final changed = ExamSettingsDraft(
      title: 'ใหม่',
      examDate: current.dueAt!,
      courseId: current.courseId,
      durationMinutes: null,
      versionCount: 1,
      gradingMethod: ExamGradingMethod.manual,
      manualFullMarks: 20,
      showKeyToStudents: true,
    );
    expect(changed.toUpdateJson(current), {
      'title': 'ใหม่',
      'duration_minutes': null,
      'show_key_to_students': true,
      'version_count': 1,
      'manual_full_marks': 20.0,
      'grading_method': 'manual',
    });
  });

  test('ExamSectionDraft create and update bodies', () {
    const mcq = ExamSectionDraft(
      type: ExamSectionType.mcq,
      title: ' ปรนัย ',
      instructions: '',
      optionCount: 5,
      questionCount: 20,
    );
    expect(mcq.toCreateJson(), {
      'title': 'ปรนัย',
      'instructions': null,
      'type': 'mcq',
      'option_count': 5,
      'default_points': 1.0,
      'question_count': 20,
    });
    const numeric = ExamSectionDraft(
      type: ExamSectionType.numeric,
      numeric: NumericSpec(digits: 3, allowNegative: true),
      defaultPoints: 2,
    );
    expect(numeric.toCreateJson()['numeric'], {
      'digits': 3,
      'allow_negative': true,
      'allow_decimal': false,
    });

    final d = ExamDetail.fromJson(examJson());
    const edit = ExamSectionDraft(
      type: ExamSectionType.mcq,
      title: 'ปรนัย',
      instructions: 'เลือกคำตอบที่ถูกที่สุด',
      optionCount: 4,
    );
    expect(edit.toUpdateJson(d.sections.first), isEmpty);
    const moreOptions = ExamSectionDraft(
      type: ExamSectionType.mcq,
      title: 'ตอนแรก',
      optionCount: 5,
      defaultPoints: 2,
    );
    expect(moreOptions.toUpdateJson(d.sections.first), {
      'title': 'ตอนแรก',
      'instructions': null,
      'default_points': 2.0,
      'option_count': 5,
    });
    const digits = ExamSectionDraft(
      type: ExamSectionType.numeric,
      title: 'เติมตัวเลข',
      numeric: NumericSpec(digits: 3, allowDecimal: true),
      defaultPoints: 2,
    );
    expect(digits.toUpdateJson(d.sections[2]), {
      'numeric': {'digits': 3, 'allow_negative': false, 'allow_decimal': true},
    });
  });

  test('ExamQuestionDraft body', () {
    const draft = ExamQuestionDraft(
      type: ExamSectionType.mcq,
      promptText: ' โจทย์ ',
      options: ['1', ' ', '3'],
      maxPoints: 2,
      key: ExamKey(options: [1]),
      lockOptions: true,
      approve: true,
    );
    expect(draft.toJson(), {
      'prompt_text': 'โจทย์',
      'options': [
        {'text': '1'},
        {'text': null},
        {'text': '3'},
      ],
      'max_points': 2.0,
      'answer_key': {
        'accepted_options': [1],
      },
      'lock_options': true,
      'approve': true,
    });
    expect(draft.toJson(create: true).containsKey('approve'), isFalse);
    const numeric = ExamQuestionDraft(
      type: ExamSectionType.numeric,
      options: ['x'],
      lockOptions: true,
    );
    expect(numeric.toJson(), {'prompt_text': '', 'answer_key': null});
  });

  test('ExamVersions labels the order and the key of each version', () {
    final v = ExamVersions.fromJson(versionsJson());
    expect(v.versions.map((x) => x.label), ['ก', 'ข']);
    final b = v.versions[1];
    final q11 = b.items[1];
    expect(q11.sheetNo, 2);
    expect(q11.originalPosition, 1);
    expect(q11.shuffled, isTrue);
    expect(q11.orderLabel, 'ค ก ง ข');
    expect(q11.keyLabel, 'ก');
    expect(b.items[2].keyLabel, 'ผิด');
    expect(b.items[3].keyLabel, '0.5');
    expect(v.versions[0].items[1].keyLabel, '–');
    expect(v.versions[0].items[0].shuffled, isFalse);
  });

  test('labels and points', () {
    expect(examOptionLabel(6), 'ฉ');
    expect(examOptionLabel(9), '9');
    expect(examVersionLabel(4), 'ง');
    expect(examVersionLabel(11), '11');
    expect(examChoiceLabel(ExamSectionType.trueFalse, 1), 'ถูก');
    expect(examChoiceLabel(ExamSectionType.trueFalse, 3), '?');
    expect(formatPoints(2), '2');
    expect(formatPoints(1.5), '1.5');
    expect(ExamIncomplete.reasonLabel('no_prompt'), 'ยังไม่มีโจทย์');
    expect(ExamGradingMethod.fromApi(null), ExamGradingMethod.app);
  });
}
