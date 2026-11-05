import 'package:eduvision/core/api/error_codes.dart';
import 'package:eduvision/features/analysis/analysis_models.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/indicator_mapping.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/exams/exam_import_models.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

/// DESIGN §36.1: background AI work keeps the code of its failure.
void main() {
  const failed = {
    'id': 5,
    'status': 'failed',
    'error': 'AI อ่านไม่สำเร็จ',
    'ai_error_code': 'AI-QUOTA',
    'ai_call_id': 48213,
  };

  test('fromFields reads a code with its call, or nothing', () {
    expect(ErrorCode.fromFields('AI-QUOTA', 48213)?.label, 'AI-QUOTA · #48213');
    expect(
      ErrorCode.fromFields('AI-KEY-MISSING', null)?.label,
      'AI-KEY-MISSING',
    );
    expect(ErrorCode.fromFields(null, 3), isNull);
    expect(ErrorCode.fromFields('', 3), isNull);
  });

  test(
    'file reads, suggestions, analyses and rubric drafts carry the code',
    () {
      expect(
        KeyExtraction.fromJson(failed).errorCode?.label,
        'AI-QUOTA · #48213',
      );
      expect(ExamRead.fromJson(failed).errorCode?.label, 'AI-QUOTA · #48213');
      expect(
        CourseExtraction.fromJson({'extraction': failed}).errorCode?.label,
        'AI-QUOTA · #48213',
      );
      expect(
        SuggestState.fromJson({
          'status': 'failed',
          'error': {
            'code': 'ai_failed',
            'message': 'AI เสนอตัวชี้วัดไม่สำเร็จ',
            'ai_error_code': 'AI-SERVER',
            'ai_call_id': 7,
          },
        }).aiCode?.label,
        'AI-SERVER · #7',
      );
      expect(
        AnalysisSummary.fromJson({
          'id': 1,
          'student_id': 2,
          'classroom_id': 3,
          'status': 'failed',
          'strengths': const [],
          'areas': const [],
          'ai_error_code': 'AI-BLOCKED',
          'ai_call_id': 9,
        }).aiCode?.label,
        'AI-BLOCKED · #9',
      );
      final question = Question.fromJson({
        'id': 4,
        'position': 1,
        'type': 'open',
        'prompt_text': 'เรียงความ',
        'max_points': 4,
        'rubric_status': 'draft',
        'rubric_draft_error': {
          'ai_error_code': 'AI-OUTPUT',
          'ai_call_id': 11,
          'message': 'AI ร่าง rubric ไม่สำเร็จ',
        },
      });
      expect(question.rubricDraftError?.code.label, 'AI-OUTPUT · #11');
      expect(question.rubricDraftError?.message, 'AI ร่าง rubric ไม่สำเร็จ');
      expect(
        Question.fromJson({
          'id': 4,
          'position': 1,
          'type': 'open',
          'prompt_text': 'x',
          'max_points': 1,
          'rubric_draft_error': null,
        }).rubricDraftError,
        isNull,
      );
    },
  );

  test('the practice bank asks for the last failed generation', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': const [],
        'generation_error': {
          'ai_error_code': 'AI-KEY-MISSING',
          'ai_call_id': null,
          'message': 'ยังไม่มี Gemini API key',
        },
      }),
    );
    final failure = await ApiPracticeBankRepository(
      fakeDio(adapter),
    ).generationError(12);
    expect(adapter.requests.single.uri.queryParameters['skill'], '12');
    expect(failure?.code.label, 'AI-KEY-MISSING');
    expect(failure?.message, 'ยังไม่มี Gemini API key');
  });
}
