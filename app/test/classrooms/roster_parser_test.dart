import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/roster_parser.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('parses "เลขที่ ชื่อ" lines with spaces, tabs, dots and commas', () {
    final r = parseRosterLines(
      '2 ด.ญ. สมหญิง รักเรียน\n'
      '1\tด.ช. สมชาย ใจดี\n'
      '\n'
      '3. เด็กหญิง มานี มีนา\n'
      '4, ด.ช. ปิติ  ชูใจ  \n',
    );
    expect(r.ok, isTrue);
    expect(r.students, const [
      NewStudent(studentNumber: 1, name: 'ด.ช. สมชาย ใจดี'),
      NewStudent(studentNumber: 2, name: 'ด.ญ. สมหญิง รักเรียน'),
      NewStudent(studentNumber: 3, name: 'เด็กหญิง มานี มีนา'),
      NewStudent(studentNumber: 4, name: 'ด.ช. ปิติ  ชูใจ'),
    ]);
  });

  test('reports bad lines, duplicates and out-of-range numbers', () {
    final r = parseRosterLines('สมชาย\n5 ก\n5 ข\n300 ค');
    expect(r.ok, isFalse);
    expect(r.students.length, 1);
    expect(r.errors.map((e) => e.lineNumber), [1, 3, 4]);
  });

  test('empty input is not ok', () {
    expect(parseRosterLines('  \n').ok, isFalse);
  });
}
