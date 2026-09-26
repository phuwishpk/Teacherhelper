// Coverage gate for `flutter test --coverage` (DESIGN §16.1: >= 70% lines).
//
//   dart run tool/check_coverage.dart [--min 70] [--file coverage/lcov.info]
//
// Reads the lcov report, drops generated sources (build_runner, drift and
// Pigeon output), prints the line coverage per directory and the total, and
// exits 1 below the threshold so CI fails. It needs no lcov binary, so the
// check is identical on a developer Mac and on the CI runner.
//
// lcov only lists files with executable lines that some test loaded; a
// source no test imports is missing from the report rather than at 0%, and
// so is a file of nothing but constants, interfaces or exports. Those files
// are printed as a warning so a real gap stays visible.
import 'dart:io';

/// Files no test is expected to cover: generated code and the Pigeon
/// bindings that only run against the Android host.
final excludedPatterns = <RegExp>[
  RegExp(r'\.g\.dart$'),
  RegExp(r'\.freezed\.dart$'),
  RegExp(r'\.drift\.dart$'),
  RegExp(r'(^|/)lib/platform/pigeons/'),
];

void main(List<String> args) {
  var minPercent = 70.0;
  var path = 'coverage/lcov.info';
  var libDir = 'lib';
  var verbose = true;
  for (var i = 0; i < args.length; i++) {
    switch (args[i]) {
      case '--min':
        minPercent = double.parse(args[++i]);
      case '--file':
        path = args[++i];
      case '--lib':
        libDir = args[++i];
      case '--quiet':
        verbose = false;
      default:
        stderr.writeln('Unknown argument: ${args[i]}');
        exit(2);
    }
  }

  final file = File(path);
  if (!file.existsSync()) {
    stderr.writeln('No coverage report at $path; run flutter test --coverage');
    exit(2);
  }

  final report = parseLcov(file.readAsLinesSync());
  final kept = <String, FileCoverage>{};
  var droppedCount = 0;
  for (final entry in report.entries) {
    if (excludedPatterns.any((p) => p.hasMatch(entry.key))) {
      droppedCount++;
    } else {
      kept[entry.key] = entry.value;
    }
  }

  final total = FileCoverage.sum(kept.values);
  if (verbose) {
    final dirs = <String, List<FileCoverage>>{};
    for (final e in kept.entries) {
      dirs.putIfAbsent(directoryOf(e.key), () => []).add(e.value);
    }
    final rows = dirs.entries.toList()..sort((a, b) => a.key.compareTo(b.key));
    stdout.writeln('Line coverage by directory (generated files excluded):');
    for (final row in rows) {
      final c = FileCoverage.sum(row.value);
      stdout.writeln(
        '  ${row.key.padRight(30)} ${c.percent.toStringAsFixed(1).padLeft(5)}%'
        '  (${c.hit}/${c.found})',
      );
    }
    if (droppedCount > 0) {
      stdout.writeln('Excluded $droppedCount generated file(s).');
    }
    final missing = sourcesMissingFromReport(libDir, report.keys);
    if (missing.isNotEmpty) {
      stdout.writeln(
        'WARNING: ${missing.length} source file(s) absent from the report '
        '(no executable lines, or loaded by no test; not counted):',
      );
      for (final m in missing) {
        stdout.writeln('  $m');
      }
    }
  }
  stdout.writeln(
    'TOTAL ${total.percent.toStringAsFixed(2)}% '
    '(${total.hit}/${total.found} lines, ${kept.length} files)',
  );
  if (total.percent < minPercent) {
    stderr.writeln(
      'Coverage ${total.percent.toStringAsFixed(2)}% is below the '
      '${minPercent.toStringAsFixed(0)}% threshold',
    );
    exit(1);
  }
  stdout.writeln('OK: at or above ${minPercent.toStringAsFixed(0)}%');
}

/// `lib/features/review/x.dart` -> `lib/features/review`; `lib/ml/x.dart`
/// -> `lib/ml`; `lib/app.dart` -> `lib`. Deeper paths are cut at three
/// segments so the table stays short.
String directoryOf(String path) {
  final parts = path.split('/');
  return parts.sublist(0, parts.length - 1).take(3).join('/');
}

/// Dart files under [libDir] that have no lcov record and are not excluded.
/// Paths are normalised to `lib/...` like lcov prints them.
List<String> sourcesMissingFromReport(
  String libDir,
  Iterable<String> inReport,
) {
  final dir = Directory(libDir);
  if (!dir.existsSync()) return const [];
  final seen = inReport.map(_normalise).toSet();
  final missing = <String>[];
  for (final entity in dir.listSync(recursive: true)) {
    if (entity is! File || !entity.path.endsWith('.dart')) continue;
    final rel = _normalise(entity.path);
    if (excludedPatterns.any((p) => p.hasMatch(rel))) continue;
    if (!seen.contains(rel)) missing.add(rel);
  }
  return missing..sort();
}

String _normalise(String path) {
  final p = path.replaceAll(r'\', '/');
  final i = p.indexOf('lib/');
  return i < 0 ? p : p.substring(i);
}

class FileCoverage {
  const FileCoverage(this.found, this.hit);

  final int found;
  final int hit;

  double get percent => found == 0 ? 100 : hit * 100 / found;

  static FileCoverage sum(Iterable<FileCoverage> items) {
    var found = 0;
    var hit = 0;
    for (final c in items) {
      found += c.found;
      hit += c.hit;
    }
    return FileCoverage(found, hit);
  }
}

/// lcov: `SF:<path>` opens a record, `DA:<line>,<count>` marks a line,
/// `end_of_record` closes it. Counts are taken from DA so a report without
/// LF/LH lines still works.
Map<String, FileCoverage> parseLcov(List<String> lines) {
  final out = <String, FileCoverage>{};
  String? current;
  var found = 0;
  var hit = 0;
  for (final raw in lines) {
    final line = raw.trim();
    if (line.startsWith('SF:')) {
      current = line.substring(3).replaceAll(r'\', '/');
      found = 0;
      hit = 0;
    } else if (line.startsWith('DA:') && current != null) {
      final parts = line.substring(3).split(',');
      if (parts.length < 2) continue;
      found++;
      if ((int.tryParse(parts[1]) ?? 0) > 0) hit++;
    } else if (line == 'end_of_record' && current != null) {
      final previous = out[current];
      out[current] = previous == null
          ? FileCoverage(found, hit)
          : FileCoverage(previous.found + found, previous.hit + hit);
      current = null;
    }
  }
  return out;
}
