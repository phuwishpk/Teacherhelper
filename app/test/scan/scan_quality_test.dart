import 'package:eduvision/features/scan/scan_quality.dart';
import 'package:flutter_test/flutter_test.dart';

import 'scan_fixtures.dart';

void main() {
  test('a sharp page with four markers and a worksheet QR passes', () {
    expect(checkDetection(goodDetection()), isEmpty);
  });

  test('missing markers name the corners in Thai', () {
    final issues = checkDetection(goodDetection(missing: [2, 0]));
    expect(issues, hasLength(1));
    final issue = issues.single as MarkersMissing;
    expect(issue.ids, [0, 2]);
    expect(issue.message, contains('มุมบนซ้าย และมุมล่างขวา'));
  });

  test('no marker at all asks for the whole sheet', () {
    final issue =
        checkDetection(goodDetection(missing: [0, 1, 2, 3])).single
            as MarkersMissing;
    expect(issue.message, contains('ทั้งแผ่น'));
  });

  test('blur is only judged when every marker was found', () {
    expect(
      checkDetection(
        goodDetection(missing: [1], blur: 5),
      ).whereType<TooBlurry>(),
      isEmpty,
    );
    final issues = checkDetection(goodDetection(blur: 12.5));
    expect(issues.single, isA<TooBlurry>());
    expect(onlyBlur(issues), isTrue);
    expect(
      checkDetection(goodDetection(blur: 12.5), minBlurScore: 10),
      isEmpty,
    );
  });

  test('QR problems', () {
    expect(checkDetection(goodDetection(qr: null)).single, isA<QrUnreadable>());

    final card =
        checkDetection(goodDetection(qr: 'EVL1.abcdef')).single
            as NotAWorksheet;
    expect(card.isLoginCard, isTrue);
    expect(card.message, contains('บัตรเข้าสู่ระบบ'));

    final other =
        checkDetection(goodDetection(qr: 'https://example.com')).single
            as NotAWorksheet;
    expect(other.isLoginCard, isFalse);

    // student_id 0 = spare worksheet, Google Classroom only (DESIGN §18.3).
    expect(
      checkDetection(goodDetection(qr: 'EV1.123.0.1.2.K7Q3M2PA')).single,
      isA<SpareWorksheet>(),
    );
  });

  test('a spare worksheet passes when its source allows it', () {
    final spare = goodDetection(qr: 'EV1.123.0.1.2.K7Q3M2PA');
    expect(checkDetection(spare, allowSpareWorksheet: true), isEmpty);
    // The other checks still apply.
    expect(
      checkDetection(
        goodDetection(qr: 'EV1.123.0.1.2.K7Q3M2PA', missing: [3]),
        allowSpareWorksheet: true,
      ).single,
      isA<MarkersMissing>(),
    );
  });

  test('the blank line is the one DESIGN §11.8 rule D uses', () {
    expect(emptyInkRatio, 0.02);
  });

  test('several problems are all reported, and blur alone can be kept', () {
    final issues = checkDetection(goodDetection(qr: null, blur: 1));
    expect(issues.map((i) => i.runtimeType), [QrUnreadable, TooBlurry]);
    expect(onlyBlur(issues), isFalse);
    expect(onlyBlur(const []), isFalse);
  });

  test('filled options for the confirm screen', () {
    expect(filledOptions({'A': 0.04, 'B': 0.83, 'C': 0.5, 'D': 0.49}), [
      'B',
      'C',
    ]);
    expect(filledOptions(null), isEmpty);
  });
}
