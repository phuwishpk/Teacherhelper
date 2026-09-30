import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exams_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import 'exam_fakes.dart';

/// Request/response shapes of the exam endpoints of DESIGN §22.15.
void main() {
  late FakeHttpAdapter adapter;
  late ApiExamsRepository repo;
  Object? body;

  setUp(() {
    body = {'data': examJson()};
    adapter = FakeHttpAdapter((_) async => jsonResponse(200, body));
    repo = ApiExamsRepository(fakeDio(adapter));
  });

  RequestOptions last() => adapter.requests.last;

  test('create POSTs /assignments with kind = exam', () async {
    body = {'data': examJson()['exam']};
    final created = await repo.create(
      ExamSettingsDraft(
        title: 'สอบ',
        examDate: DateTime.utc(2026, 10, 15),
        classroomId: 7,
        courseId: 3,
      ),
    );
    expect(last().method, 'POST');
    expect(last().uri.path, '/api/v1/assignments');
    expect((last().data as Map)['kind'], 'exam');
    expect((last().data as Map)['grading_method'], 'app');
    expect((last().data as Map).containsKey('manual_full_marks'), isFalse);
    expect(created.isExam, isTrue);
  });

  test('updateSettings PATCHes /assignments/{id}', () async {
    body = {'data': examJson()['exam']};
    await repo.updateSettings(40, {'version_count': 3});
    expect(last().method, 'PATCH');
    expect(last().uri.path, '/api/v1/assignments/40');
    expect(last().data, {'version_count': 3});
  });

  test('get reads GET /exams/{id}', () async {
    final d = await repo.get(40);
    expect(last().uri.path, '/api/v1/exams/40');
    expect(d.questionCount, 4);
  });

  test('sections: POST /exams/{id}/sections, PATCH and DELETE '
      '/exam-sections/{id}', () async {
    body = {'data': (examJson()['sections'] as List).first};
    final s = await repo.addSection(
      40,
      const ExamSectionDraft(type: ExamSectionType.trueFalse, questionCount: 5),
    );
    expect(last().method, 'POST');
    expect(last().uri.path, '/api/v1/exams/40/sections');
    expect((last().data as Map)['type'], 'true_false');
    expect((last().data as Map)['question_count'], 5);
    expect(s.id, 1);

    await repo.updateSection(1, {'position': 2});
    expect(last().method, 'PATCH');
    expect(last().uri.path, '/api/v1/exam-sections/1');
    expect(last().data, {'position': 2});

    body = null;
    adapter = FakeHttpAdapter((_) async => ResponseBody.fromString('', 204));
    repo = ApiExamsRepository(fakeDio(adapter));
    await repo.deleteSection(1);
    expect(last().method, 'DELETE');
    expect(last().uri.path, '/api/v1/exam-sections/1');
  });

  test('questions: POST /exam-sections/{id}/questions, PATCH and DELETE '
      '/questions/{id}', () async {
    body = {
      'data': questionJson(id: 11, sectionId: 1, position: 1, options: ['a']),
    };
    const draft = ExamQuestionDraft(
      type: ExamSectionType.mcq,
      promptText: 'โจทย์',
      options: ['a', 'b'],
      key: ExamKey(options: [2]),
      approve: true,
    );
    final q = await repo.addQuestion(1, draft);
    expect(last().uri.path, '/api/v1/exam-sections/1/questions');
    expect((last().data as Map).containsKey('approve'), isFalse);
    expect(q.id, 11);

    await repo.updateQuestion(11, draft);
    expect(last().method, 'PATCH');
    expect(last().uri.path, '/api/v1/questions/11');
    expect((last().data as Map)['approve'], isTrue);
    expect((last().data as Map)['answer_key'], {
      'accepted_options': [2],
    });

    await repo.deleteQuestion(11);
    expect(last().method, 'DELETE');
    expect(last().uri.path, '/api/v1/questions/11');
  });

  test(
    'images: multipart `image`, DELETE, and bytes through the API',
    () async {
      body = {
        'data': questionJson(id: 11, sectionId: 1, position: 1, image: true),
      };
      final file = PickedDocument(
        name: 'fig.png',
        bytes: Uint8List.fromList([1, 2, 3]),
      );
      final q = await repo.uploadQuestionImage(11, file);
      expect(last().uri.path, '/api/v1/questions/11/image');
      final form = last().data as FormData;
      expect(form.files.single.key, 'image');
      expect(form.files.single.value.filename, 'fig.png');
      expect(form.files.single.value.contentType.toString(), 'image/png');
      expect(q.hasPromptImage, isTrue);

      await repo.uploadOptionImage(113, file);
      expect(last().uri.path, '/api/v1/question-options/113/image');
      await repo.deleteQuestionImage(11);
      expect(last().method, 'DELETE');
      expect(last().uri.path, '/api/v1/questions/11/image');
      await repo.deleteOptionImage(113);
      expect(last().uri.path, '/api/v1/question-options/113/image');

      adapter = FakeHttpAdapter(
        (_) async => ResponseBody.fromBytes([9, 8, 7], 200),
      );
      repo = ApiExamsRepository(fakeDio(adapter));
      expect(await repo.image((option: false, id: 11)), [9, 8, 7]);
      expect(last().uri.path, '/api/v1/questions/11/image');
      await repo.image((option: true, id: 113));
      expect(last().uri.path, '/api/v1/question-options/113/image');
    },
  );

  test('approveQuestions and the key: PUT answer-key, approve', () async {
    await repo.approveQuestions(40, [12]);
    expect(last().uri.path, '/api/v1/exams/40/questions/approve');
    expect(last().data, {
      'question_ids': [12],
    });

    await repo.saveAnswerKey(40, [
      (
        questionId: 11,
        type: ExamSectionType.mcq,
        key: const ExamKey(options: [2, 4]),
      ),
      (questionId: 12, type: ExamSectionType.mcq, key: null),
      (
        questionId: 31,
        type: ExamSectionType.numeric,
        key: const ExamKey(values: ['0.5']),
      ),
    ]);
    expect(last().method, 'PUT');
    expect(last().uri.path, '/api/v1/exams/40/answer-key');
    expect(last().data, {
      'answers': [
        {
          'question_id': 11,
          'accepted_options': [2, 4],
        },
        {'question_id': 12, 'accepted_options': <int>[]},
        {
          'question_id': 31,
          'accepted_values': ['0.5'],
        },
      ],
    });

    await repo.approveKey(40);
    expect(last().method, 'POST');
    expect(last().uri.path, '/api/v1/assignments/40/answer-key/approve');
  });

  test('versions, reshuffle and unlock', () async {
    body = {'data': versionsJson()};
    final v = await repo.versions(40);
    expect(last().uri.path, '/api/v1/exams/40/versions');
    expect(v.versions, hasLength(2));
    await repo.reshuffle(40);
    expect(last().method, 'POST');
    expect(last().uri.path, '/api/v1/exams/40/versions/reshuffle');

    body = {'data': examJson()};
    await repo.unlockStructure(40);
    expect(last().uri.path, '/api/v1/exams/40/unlock-structure');
  });

  test('apiFieldErrors reads the first message of each field', () async {
    adapter = FakeHttpAdapter(
      (_) async => jsonResponse(422, {
        'message': 'ข้อมูลไม่ถูกต้อง',
        'code': 'validation_failed',
        'errors': {
          'answers.1.accepted_values.0': ['ค่า 123 ยาวเกิน', 'อื่น'],
          'answers': 'ข้อความเดียว',
        },
      }),
    );
    repo = ApiExamsRepository(fakeDio(adapter));
    try {
      await repo.saveAnswerKey(40, [
        (questionId: 31, type: ExamSectionType.numeric, key: null),
      ]);
      fail('expected a 422');
    } catch (e) {
      expect(apiFieldErrors(e), {
        'answers.1.accepted_values.0': 'ค่า 123 ยาวเกิน',
        'answers': 'ข้อความเดียว',
      });
    }
    expect(apiFieldErrors(StateError('x')), isEmpty);
  });
}
