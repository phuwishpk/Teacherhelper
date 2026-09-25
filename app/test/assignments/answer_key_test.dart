import 'package:eduvision/features/assignments/answer_key.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('AnswerKey.splitAccepted', () {
    test('a comma inside an answer does not split it', () {
      expect(AnswerKey.splitAccepted('1,000'), ['1,000']);
      expect(AnswerKey.splitAccepted('x = 2, y = 3'), ['x = 2, y = 3']);
      expect(AnswerKey.splitAccepted('(2, 3)'), ['(2, 3)']);
    });

    test('one answer per line, trimmed, blank lines dropped', () {
      expect(AnswerKey.splitAccepted(' กรุงเทพมหานคร \n\nกรุงเทพฯ\n'), [
        'กรุงเทพมหานคร',
        'กรุงเทพฯ',
      ]);
      expect(AnswerKey.splitAccepted('1,000\n1000\nหนึ่งพัน'), [
        '1,000',
        '1000',
        'หนึ่งพัน',
      ]);
      expect(AnswerKey.splitAccepted('  \n '), isEmpty);
    });
  });

  test('short key keeps a comma answer as one accepted value', () {
    expect(
      AnswerKey.short(
        accepted: AnswerKey.splitAccepted('1,000'),
        numericValue: 1000,
      ),
      {
        'accepted': ['1,000'],
        'numeric': {'value': 1000.0, 'abs_tol': 0},
      },
    );
  });

  test('show_work key nests the final answer (DESIGN §8.3)', () {
    expect(
      AnswerKey.showWork(
        finalAccepted: AnswerKey.splitAccepted('x = 2, y = 3'),
        referenceSteps: AnswerKey.splitLines('x + y = 5\nx - y = -1'),
      ),
      {
        'final': {
          'accepted': ['x = 2, y = 3'],
        },
        'reference_steps': ['x + y = 5', 'x - y = -1'],
      },
    );
  });
}
