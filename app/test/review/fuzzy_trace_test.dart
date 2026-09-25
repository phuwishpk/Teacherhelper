import 'package:eduvision/features/review/fuzzy_trace.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('parses the ml/fuzzy engine shape (§11.3 worked example)', () {
    final t = FuzzyTrace.parse({
      'scoring': {
        'inputs': {'F': 0.0, 'S': 0.75},
        'rules': [
          {
            'name': 'R4',
            'weight': 0.5,
            'then': {'score_ratio': 0.6, 'u': 0.6},
            'note': '',
          },
          {
            'name': 'R5',
            'weight': 0.167,
            'then': {'score_ratio': 0.35, 'u': 0.3},
          },
          {
            'name': 'R1',
            'weight': 0.0,
            'then': {'score_ratio': 1.0, 'u': 1.0},
          },
        ],
        'weight_sum': 0.667,
        'outputs': {'score_ratio': 0.538, 'u': 0.525},
        'degenerate': false,
      },
    });
    expect(t.scoring!.inputs, {'F': 0.0, 'S': 0.75});
    expect(t.scoring!.fired.map((r) => r.name), ['R4', 'R5']);
    expect(t.scoring!.fired.first.scoreZ, 0.6);
    expect(t.scoring!.fired.first.description, contains('พลาดตอนท้าย'));
    expect(t.scoring!.outputs['score_ratio'], 0.538);
    expect(t.priority, isNull);
  });

  test('parses the backend ReviewPriority trace (rule/w/z) at top level', () {
    final t = FuzzyTrace.parse({
      'system': 'review_priority',
      'inputs': {'D': 0, 'L': 0.4, 'B': 0},
      'rules': [
        {'rule': 'P1', 'w': 0, 'z': 1.0},
        {'rule': 'P2', 'w': 0.4, 'z': 0.8},
        {'rule': 'P4', 'w': 0.6, 'z': 0.0},
      ],
      'p': 0.32,
    });
    expect(t.scoring, isNull);
    expect(t.priority!.outputs['p'], 0.32);
    expect(t.priority!.fired.map((r) => r.name), ['P4', 'P2']);
    expect(t.priority!.fired.last.pZ, 0.8);
  });

  test('suspicious short-circuit and unknown shapes do not throw', () {
    expect(
      FuzzyTrace.parse({
        'priority': {'suspicious_instruction': true, 'p': 1.0},
      }).suspicious,
      isTrue,
    );
    expect(FuzzyTrace.parse(null).isEmpty, isTrue);
    expect(FuzzyTrace.parse('x').isEmpty, isTrue);
    expect(FuzzyTrace.parse({'method': 'mcq'}).method, 'mcq');
  });

  test('reads the backend GradeResult/PriorityResult wrappers with a '
      'nested engine trace', () {
    final t = FuzzyTrace.parse({
      'grading': {
        'question_type': 'show_work',
        'grading_state': 'scored',
        'score_ratio': 0.538,
        'u': 0.525,
        'understanding': 'partial',
        'trace': {
          'inputs': {'F': 0.0, 'S': 0.75},
          'rules': [
            {
              'name': 'R4',
              'weight': 0.5,
              'then': {'score_ratio': 0.6, 'u': 0.6},
            },
          ],
          'weight_sum': 0.5,
          'outputs': {'score_ratio': 0.6, 'u': 0.6},
          'degenerate': false,
        },
      },
      'priority': {
        'system': 'review_priority',
        'inputs': {'D': 0.0, 'L': 0.4, 'B': 0.0},
        'flag': null,
        'p': 0.32,
        'band': 'look',
        'trace': {
          'rules': [
            {
              'name': 'P2',
              'weight': 0.4,
              'then': {'p': 0.8},
            },
          ],
          'outputs': {'p': 0.32},
        },
      },
    });
    expect(t.scoring!.inputs, {'F': 0.0, 'S': 0.75});
    expect(t.scoring!.fired.single.name, 'R4');
    expect(
      t.scoring!.outputs['score_ratio'],
      0.538,
      reason: 'the wrapper value wins over the engine output',
    );
    expect(t.priority!.fired.single.pZ, 0.8);
    expect(t.priority!.outputs['p'], 0.32);
    expect(t.suspicious, isFalse);

    expect(
      FuzzyTrace.parse({
        'priority': {'system': 'review_priority', 'flag': 'suspicious', 'p': 1},
      }).suspicious,
      isTrue,
    );
  });

  test('mcq traces carry the fill result and their review priority', () {
    final t = FuzzyTrace.parse({
      'system': 'mcq',
      'fill': {'A': 0.04, 'B': 0.83},
      'filled': ['B'],
      'ambiguous': false,
      'correct': 'B',
      'score_ratio': 1.0,
      'u': 1.0,
      'review_priority': {
        'system': 'review_priority',
        'inputs': {'D': 0.0, 'L': 0.0, 'B': 0.0},
        'p': 0.0,
        'band': 'confident',
        'trace': {
          'rules': [
            {
              'name': 'P4',
              'weight': 1.0,
              'then': {'p': 0.0},
            },
          ],
        },
      },
    });
    expect(t.method, 'mcq');
    expect(t.mcqFilled, ['B']);
    expect(t.mcqCorrect, 'B');
    expect(t.scoring, isNull);
    expect(t.priority!.fired.single.name, 'P4');
  });
}
