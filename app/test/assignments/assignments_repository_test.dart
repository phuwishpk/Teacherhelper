import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

/// Request/response shapes of the assignment endpoints (DESIGN §9.3).
void main() {
  test('searchSkills uses the subject/grade/q query keys of §9.3', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [
          {
            'id': 12,
            'code': 'ค1.1 ป.5/1',
            'name': 'บวกลบเศษส่วน',
            'subject_id': 1,
            'grade_level': 5,
          },
        ],
      }),
    );
    final repo = ApiAssignmentsRepository(fakeDio(adapter));

    final skills = await repo.searchSkills(subjectId: 1, grade: 5, q: 'เศษ');

    final req = adapter.requests.single;
    expect(req.uri.path, '/api/v1/skills');
    expect(req.uri.queryParameters, {'subject': '1', 'grade': '5', 'q': 'เศษ'});
    expect(skills.single.id, 12);
    expect(skills.single.gradeLevel, 5);

    await repo.searchSkills();
    expect(
      adapter.requests.last.uri.queryParameters,
      isEmpty,
      reason: 'unset filters are not sent as empty strings',
    );
  });

  test('subjects reads GET /subjects', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [
          {'id': 1, 'code': 'ค', 'name': 'คณิตศาสตร์'},
          {'id': 2, 'code': 'ว', 'name': 'วิทยาศาสตร์'},
        ],
      }),
    );
    final repo = ApiAssignmentsRepository(fakeDio(adapter));
    final subjects = await repo.subjects();
    expect(adapter.requests.single.uri.path, '/api/v1/subjects');
    expect(subjects.map((s) => s.name), ['คณิตศาสตร์', 'วิทยาศาสตร์']);
  });

  test(
    'saveRubric PUTs {criteria[], reference_steps?} with positions',
    () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(200, {
          'data': {
            'id': 501,
            'position': 1,
            'type': 'show_work',
            'prompt_text': 'p',
            'max_points': 5,
            'rubric_status': 'approved',
            'rubric_criteria': [],
          },
        }),
      );
      final repo = ApiAssignmentsRepository(fakeDio(adapter));

      final q = await repo.saveRubric(
        501,
        criteria: const [
          RubricCriterion(
            id: 9,
            position: 7,
            description: 'ตั้งสมการ',
            points: 2,
            isCore: true,
            source: 'ai',
          ),
          RubricCriterion(position: 0, description: 'คำตอบ', points: 3),
        ],
        referenceSteps: const ['2x = 250', 'x = 125'],
      );

      final req = adapter.requests.single;
      expect(req.method, 'PUT');
      expect(req.uri.path, '/api/v1/questions/501/rubric');
      expect(req.data, {
        'criteria': [
          {
            'position': 1,
            'description': 'ตั้งสมการ',
            'points': 2.0,
            'is_core': true,
          },
          {
            'position': 2,
            'description': 'คำตอบ',
            'points': 3.0,
            'is_core': false,
          },
        ],
        'reference_steps': ['2x = 250', 'x = 125'],
      });
      expect(q.rubricStatus, RubricStatus.approved);

      await repo.saveRubric(
        501,
        criteria: const [
          RubricCriterion(position: 1, description: 'x', points: 1),
        ],
      );
      expect(
        (adapter.requests.last.data as Map).containsKey('reference_steps'),
        isFalse,
        reason: 'open questions send no reference steps',
      );
    },
  );

  test('requestRubricDraft posts to /questions/{id}/rubric/draft', () async {
    final adapter = FakeHttpAdapter((_) async => jsonResponse(202, {}));
    final repo = ApiAssignmentsRepository(fakeDio(adapter));
    await repo.requestRubricDraft(501);
    expect(adapter.requests.single.method, 'POST');
    expect(
      adapter.requests.single.uri.path,
      '/api/v1/questions/501/rubric/draft',
    );
  });

  test('questions are posted with the §8.3 keys and skill_ids', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'id': 502,
        'position': 2,
        'type': 'mcq',
        'prompt_text': '3 + 4 = ?',
        'max_points': 1,
        'answer_key': {'correct': 'B'},
      }),
    );
    final repo = ApiAssignmentsRepository(fakeDio(adapter));
    await repo.addQuestion(
      55,
      const QuestionDraft(
        type: QuestionType.mcq,
        promptText: '3 + 4 = ?',
        maxPoints: 1,
        answerKey: {'correct': 'B'},
        skillIds: [12],
      ),
    );
    final req = adapter.requests.single;
    expect(req.uri.path, '/api/v1/assignments/55/questions');
    expect(req.data, {
      'type': 'mcq',
      'prompt_text': '3 + 4 = ?',
      'max_points': 1.0,
      'answer_lines': null,
      'is_numeric': false,
      'match_mode': 'flexible',
      'answer_key': {'correct': 'B'},
      'skill_ids': [12],
    });
  });

  test('assignments list filters by classroom_id', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {'data': []}),
    );
    final repo = ApiAssignmentsRepository(fakeDio(adapter));
    await repo.list(classroomId: 7);
    expect(adapter.requests.single.uri.path, '/api/v1/assignments');
    expect(adapter.requests.single.uri.queryParameters, {'classroom_id': '7'});
  });
}
