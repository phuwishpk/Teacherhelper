import 'package:eduvision/features/gradebook/gradebook_models.dart';
import 'package:eduvision/features/gradebook/gradebook_repository.dart';
import 'package:flutter_test/flutter_test.dart';

/// The small rules the app checks before sending (DESIGN §23.2, §23.3).
void main() {
  test('numbers and grades are shown without trailing zeros', () {
    expect(formatGbNumber(8), '8');
    expect(formatGbNumber(8.5), '8.5');
    expect(formatGbNumber(73.3333), '73.33');
    expect(formatGbNumber(100.0), '100');
    expect(formatGbNumber(null), '–');
    expect(gradeLabel(4.0, null), '4');
    expect(gradeLabel(3.5, null), '3.5');
    expect(gradeLabel(0, null), '0');
    expect(gradeLabel(null, null), '');
    expect(gradeLabel(3.0, 'r'), 'ร');
    expect(gradeLabel(null, 'ms'), 'มส');
    expect(specialLabel('x'), isNull);
  });

  test('weights are summed in hundredths', () {
    expect(weightCents([33.33, 33.33, 33.34]), 10000);
    expect(weightCents([30, 20, 30, 20]), 10000);
    expect(weightCents([70, 20]), 9000);
  });

  test('cutoffs must be 7 strictly falling integers in 1–100', () {
    expect(cutoffsProblem(kDefaultCutoffs), isNull);
    expect(cutoffsProblem([80, 75]), contains('7 ค่า'));
    expect(cutoffsProblem([80, 75, 70, null, 60, 55, 50]), contains('ครบ'));
    expect(cutoffsProblem([101, 75, 70, 65, 60, 55, 50]), contains('1–100'));
    expect(cutoffsProblem([80, 80, 70, 65, 60, 55, 50]), contains('ลดหลั่น'));
  });

  test('a score is 0 … full marks with at most 2 decimals', () {
    expect(scoreProblem(10, 10), isNull);
    expect(scoreProblem(0, 10), isNull);
    expect(scoreProblem(8.25, 10), isNull);
    expect(scoreProblem(null, 10), 'ไม่ใช่ตัวเลข');
    // double.tryParse takes these; a score must not.
    for (final text in [
      'NaN',
      'nan',
      'Infinity',
      '-Infinity',
      '1e2',
      '0x10',
      '',
    ]) {
      expect(parseScore(text), isNull, reason: text);
    }
    expect(parseScore(' 8,5 '), 8.5);
    expect(parseScore('.5'), 0.5);
    expect(parseScore('-1'), -1);
    expect(parseScore('10.'), 10);
    expect(scoreProblem(-1, 10), 'ติดลบ');
    expect(scoreProblem(11, 10), contains('เกินคะแนนเต็ม'));
    expect(scoreProblem(8.125, 10), contains('ทศนิยม'));
    expect(parseScore(' 8,5 '), 8.5);
    expect(parseScore('abc'), isNull);
  });

  test('pasted Excel lines: first tab field, blanks, and bad values', () {
    final values = parsePastedScores(
      '8\t ด.ญ. เอ\r\n\r\n12\n-1\nabc\n9.5\n\n',
      10,
    );
    expect(values, hasLength(6));
    expect(values[0].value, 8);
    expect(values[0].valid, isTrue);
    expect(values[1].blank, isTrue);
    expect(values[1].valid, isFalse);
    expect(values[2].error, contains('เกินคะแนนเต็ม'));
    expect(values[3].error, 'ติดลบ');
    expect(values[4].error, 'ไม่ใช่ตัวเลข');
    expect(values[5].value, 9.5);
    expect(parsePastedScores('\n\n', 10), isEmpty);
  });

  test('score changes send only what changed', () {
    expect(const ScoreChange.score(1, null).toJson(), {
      'student_id': 1,
      'score': null,
    });
    expect(const ScoreChange.excused(1, false).toJson(), {
      'student_id': 1,
      'excused': false,
    });
  });

  test('column reasons and cell labels', () {
    const zero = GradebookColumn(
      key: 'a1',
      type: ColumnType.assignment,
      id: 1,
      name: 'mirror',
      categoryId: 10,
    );
    expect(zero.notCountedReason, 'คะแนนเต็มเป็น 0 ไม่นับเกรด');
    const none = GradebookColumn(
      key: 'a2',
      type: ColumnType.assignment,
      id: 2,
      name: 'x',
      fullMarks: 10,
    );
    expect(none.notCountedReason, contains('ยังไม่ระบุหมวด'));
    expect(cellStateLabel(CellState.missing, ColumnType.assignment), 'ไม่ส่ง');
    expect(
      cellStateLabel(CellState.missing, ColumnType.manualExam),
      'ไม่มีคะแนน',
    );
    expect(CellState.fromApi('bogus'), CellState.notCounted);
    expect(ColumnType.fromApi('custom'), ColumnType.custom);
  });

  test('the CSV file name prefers the UTF-8 form', () {
    expect(csvFileName(null), isNull);
    expect(csvFileName('attachment; filename="a.csv"'), 'a.csv');
    expect(
      csvFileName("attachment; filename=a.csv; filename*=utf-8''%E0%B8%81.csv"),
      'ก.csv',
    );
  });

  test('settings fall back to the default cutoffs', () {
    final s = GradebookSettings.fromJson(const {
      'configured': false,
      'categories': [],
      'cutoffs': [1, 2],
    });
    expect(s.cutoffs, kDefaultCutoffs);
    expect(s.homeworkDefault, isNull);
    expect(
      const CategoryDraft(
        id: 1,
        name: 'ก ',
        weight: 30,
      ).sameAs(const CategoryDraft(id: 1, name: 'ก', weight: 30.001)),
      isTrue,
    );
  });
}
