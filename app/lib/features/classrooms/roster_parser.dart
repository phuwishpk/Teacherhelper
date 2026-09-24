import 'classroom.dart';

class RosterParseError {
  const RosterParseError(this.lineNumber, this.message);

  final int lineNumber;
  final String message;
}

class RosterParseResult {
  const RosterParseResult(this.students, this.errors);

  final List<NewStudent> students;
  final List<RosterParseError> errors;

  bool get ok => errors.isEmpty && students.isNotEmpty;
}

final _lineSplit = RegExp(r'^\s*(\d{1,3})[\s.,:\t]+(.+?)\s*$');

/// Parses pasted roster lines: "เลขที่ ชื่อ" per line, e.g.
/// `12 ด.ญ. สมหญิง ใจดี`. Tabs, commas and a dot after the number are also
/// accepted so lists copied from a spreadsheet work. Blank lines are skipped.
RosterParseResult parseRosterLines(String text) {
  final students = <NewStudent>[];
  final errors = <RosterParseError>[];
  final seen = <int>{};
  final lines = text.split(RegExp(r'\r?\n'));
  for (var i = 0; i < lines.length; i++) {
    final line = lines[i];
    if (line.trim().isEmpty) continue;
    final m = _lineSplit.firstMatch(line);
    if (m == null) {
      errors.add(
        RosterParseError(i + 1, 'ต้องขึ้นต้นด้วยเลขที่ แล้วตามด้วยชื่อ'),
      );
      continue;
    }
    final number = int.parse(m.group(1)!);
    final name = m.group(2)!.trim();
    if (number < 1 || number > 255) {
      errors.add(RosterParseError(i + 1, 'เลขที่ต้องอยู่ระหว่าง 1–255'));
      continue;
    }
    if (!seen.add(number)) {
      errors.add(RosterParseError(i + 1, 'เลขที่ $number ซ้ำ'));
      continue;
    }
    students.add(NewStudent(studentNumber: number, name: name));
  }
  students.sort((a, b) => a.studentNumber.compareTo(b.studentNumber));
  return RosterParseResult(students, errors);
}
