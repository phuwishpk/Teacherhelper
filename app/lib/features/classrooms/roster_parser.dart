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

/// A student code after a tab or a comma: letters, digits and dashes with
/// at least one digit (DESIGN §24.4 `[0-9A-Z-]`, Thai digits allowed).
final _codeAfterSeparator = RegExp(
  r'^(.*?\S)\s*[\t,]\s*([0-9A-Za-z๐-๙-]{1,20})$',
);

/// A student code after spaces: digits (and dashes) only, so a name is
/// never mistaken for one.
final _codeAfterSpace = RegExp(r'^(.*?\S)\s+([0-9๐-๙][0-9๐-๙-]{0,19})$');

final _digit = RegExp(r'[0-9๐-๙]');

/// Splits "ชื่อ เลขประจำตัว" into the name and the optional student code.
(String, String?) _nameAndCode(String rest) {
  final tabbed = _codeAfterSeparator.firstMatch(rest);
  if (tabbed != null && _digit.hasMatch(tabbed.group(2)!)) {
    return (tabbed.group(1)!.trim(), tabbed.group(2)!);
  }
  final spaced = _codeAfterSpace.firstMatch(rest);
  if (spaced != null) return (spaced.group(1)!.trim(), spaced.group(2)!);
  return (rest.trim(), null);
}

/// Parses pasted roster lines: "เลขที่ ชื่อ" per line, e.g.
/// `12 ด.ญ. สมหญิง ใจดี`, optionally followed by the student code
/// (เลขประจำตัว, DESIGN §24.4): `12 ด.ญ. สมหญิง ใจดี 65012`. Tabs, commas
/// and a dot after the number are also accepted so lists copied from a
/// spreadsheet work. Blank lines are skipped.
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
    final (name, code) = _nameAndCode(m.group(2)!);
    if (number < 1 || number > 255) {
      errors.add(RosterParseError(i + 1, 'เลขที่ต้องอยู่ระหว่าง 1–255'));
      continue;
    }
    if (!seen.add(number)) {
      errors.add(RosterParseError(i + 1, 'เลขที่ $number ซ้ำ'));
      continue;
    }
    students.add(
      NewStudent(studentNumber: number, name: name, studentCode: code),
    );
  }
  students.sort((a, b) => a.studentNumber.compareTo(b.studentNumber));
  return RosterParseResult(students, errors);
}
