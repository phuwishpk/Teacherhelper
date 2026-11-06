import 'package:eduvision/features/exams/exam_models.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_fakes.dart';

/// DESIGN §22.20: the written section types, their question fields and the
/// "rubric ยังไม่อนุมัติ" reason of the key check.
void main() {
  test('written section types are known and need no choices', () {
    expect(ExamSectionType.fromApi('open'), ExamSectionType.open);
    expect(ExamSectionType.fromApi('show_work'), ExamSectionType.showWork);
    expect(ExamSectionType.open.isWritten, isTrue);
    expect(ExamSectionType.showWork.isWritten, isTrue);
    expect(ExamSectionType.mcq.isWritten, isFalse);
    expect(ExamSectionType.open.label, 'อัตนัย');
    expect(ExamSectionType.showWork.label, 'แสดงวิธีทำ');
  });

  test('a written section has no choices and says what it is', () {
    final sections = writtenSectionsJson();
    final open = ExamSection.fromJson(sections[0]);
    final showWork = ExamSection.fromJson(sections[1]);
    expect(open.type, ExamSectionType.open);
    expect(open.choiceCount, 0);
    expect(open.typeSummary, 'เขียนตอบ (อัตนัย)');
    expect(showWork.choiceCount, 0);
    expect(showWork.typeSummary, 'เขียนตอบ (แสดงวิธีทำ)');
  });

  test('a show_work question reads lines, rubric status and final answer', () {
    final q = ExamQuestion.fromJson(
      questionJson(
        id: 61,
        sectionId: 6,
        position: 7,
        type: 'show_work',
        answerLines: 6,
        rubricStatus: 'draft',
        key: {
          'final': {
            'accepted': ['12', '๑๒'],
          },
          'reference_steps': ['คูณทั้งสองข้าง'],
        },
      ),
    );
    expect(q.type, ExamSectionType.showWork);
    expect(q.answerLines, 6);
    expect(q.rubricStatus, 'draft');
    expect(q.rubricApproved, isFalse);
    expect(q.finalAccepted, ['12', '๑๒']);
    expect(
      q.key,
      isNull,
      reason: 'options/values key does not read this shape',
    );
    final approved = ExamQuestion.fromJson(
      questionJson(
        id: 62,
        sectionId: 6,
        position: 8,
        type: 'open',
        rubricStatus: 'approved',
      ),
    );
    expect(approved.rubricApproved, isTrue);
    expect(approved.finalAccepted, isEmpty);
  });

  test('a written draft sends lines and the final answer, never options', () {
    final showWork = const ExamQuestionDraft(
      type: ExamSectionType.showWork,
      promptText: ' หาค่า ',
      maxPoints: 4,
      answerLines: 6,
      finalAccepted: ['12'],
    ).toJson();
    expect(showWork['answer_lines'], 6);
    expect(showWork['answer_key'], {
      'final': {
        'accepted': ['12'],
      },
    });
    expect(showWork.containsKey('options'), isFalse);
    expect(showWork.containsKey('lock_options'), isFalse);
    expect(showWork['prompt_text'], 'หาค่า');

    final open = const ExamQuestionDraft(
      type: ExamSectionType.open,
      answerLines: 9,
    ).toJson();
    expect(open['answer_lines'], 9);
    expect(open.containsKey('answer_key'), isFalse);

    final mcq = const ExamQuestionDraft(
      type: ExamSectionType.mcq,
      options: ['a', 'b'],
    ).toJson();
    expect(mcq.containsKey('answer_lines'), isFalse);
  });

  test('the key check names a rubric that is not approved', () {
    final reason = ExamIncomplete.fromJson({
      'question_id': 51,
      'position': 3,
      'reasons': ['no_rubric'],
    });
    expect(reason.summary, 'ข้อ 3: rubric ยังไม่อนุมัติ');
  });

  test(
    'the detail separates choice questions and knows if written is allowed',
    () {
      final detail = ExamDetail.fromJson(
        examJson(
          sections: [
            ...(examJson()['sections'] as List).cast<Map<String, dynamic>>(),
            ...writtenSectionsJson(),
          ],
        ),
      );
      expect(detail.questions.length, 7);
      expect(detail.choiceQuestions.map((q) => q.id), [11, 12, 21, 31]);
      expect(detail.allowsWritten, isTrue);
      final manual = ExamDetail.fromJson(examJson(method: 'manual'));
      expect(manual.allowsWritten, isFalse);
    },
  );
}
