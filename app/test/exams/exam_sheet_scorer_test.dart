import 'dart:convert';
import 'dart:io';

import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exam_scan_models.dart';
import 'package:eduvision/platform/answer_sheet_pipeline.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §22.9: the phone scores exactly like the server. The fixtures are
/// a copy of backend/tests/fixtures/exam_scoring (the backend test checks
/// that both copies are identical).
Map<String, dynamic> _fixture(String name) =>
    jsonDecode(File('test/fixtures/exam_scoring/$name').readAsStringSync())
        as Map<String, dynamic>;

Map<String, double> _fill(Object? json) => {
  for (final e in (json as Map<String, dynamic>).entries)
    e.key: (e.value as num).toDouble(),
};

void main() {
  for (final file in ['rows.json', 'digits.json']) {
    group(file, () {
      for (final c in (_fixture(file)['cases'] as List)) {
        final kase = c as Map<String, dynamic>;
        test(kase['name'] as String, () {
          final expected = kase['expected'] as Map<String, dynamic>;
          final version = ExamSheetScorer.version(
            kase['version_count'] as int,
            kase['page'] as int,
            kase['version_fill'] == null ? null : _fill(kase['version_fill']),
            kase['page_one_version'] as int?,
          );
          expect(version.versionNo, expected['version_no']);
          expect(version.source, expected['version_source']);
          expect(version.doubtful, expected['version_doubtful']);
          if (version.versionNo == null) return;

          final key = {
            for (final item
                in (kase['keys'] as Map)['${version.versionNo}'] as List)
              (item['sheet_no'] as num).toInt(): ExamKeyItem.fromJson(
                item as Map<String, dynamic>,
              ),
          };
          final result = ExamSheetScorer.scorePage(
            key,
            {
              for (final e in (kase['rows'] as Map<String, dynamic>).entries)
                int.parse(e.key): _fill(e.value),
            },
            {
              for (final e in (kase['digits'] as Map<String, dynamic>).entries)
                int.parse(e.key): DigitFill.fromJson(
                  e.value as Map<String, dynamic>,
                ),
            },
          );
          expect(result.score, closeTo((expected['score'] as num), 1e-9));
          expect(
            result.maxScore,
            closeTo((expected['max_score'] as num), 1e-9),
          );
          expect(
            [
              for (final i in result.items)
                [i.sheetNo, i.selected, i.value, i.score, i.doubts],
            ],
            [
              for (final i in expected['items'] as List)
                [
                  i['sheet_no'],
                  i['selected'],
                  i['value'],
                  (i['score'] as num).toDouble(),
                  i['doubts'],
                ],
            ],
          );
        });
      }
    });
  }

  test('canonical forms match the shared fixture', () {
    for (final pair in _fixture('canonical.json')['canonical'] as List) {
      expect(
        NumericAnswer.canonical(pair[0] as String),
        pair[1],
        reason: 'canonical(${pair[0]})',
      );
    }
  });

  test('review count counts only the doubts of §22.3', () {
    const page = ExamPageScore(
      items: [
        ExamItemScore(
          sheetNo: 1,
          selected: [1, 2],
          value: null,
          score: 0,
          max: 1,
          doubts: ['double_mark'],
        ),
        ExamItemScore(
          sheetNo: 2,
          selected: [],
          value: null,
          score: 0,
          max: 1,
          doubts: [],
        ),
      ],
      score: 0,
      maxScore: 2,
    );
    expect(page.reviewCount, 1);
  });

  group('ExamQr', () {
    test('parses answer sheets and key sheets', () {
      final qr = ExamQr.tryParse('EVX1.301.4567.2.3.Q2M7K3PA')!;
      expect(
        [qr.assignmentId, qr.studentId, qr.page, qr.layoutVersion],
        [301, 4567, 2, 3],
      );
      expect(qr.isKeySheet, isFalse);
      expect(ExamQr.tryParse('EVX1.301.0.1.1.AAAAAAAA')!.isKeySheet, isTrue);
    });

    test('rejects worksheets and other text', () {
      expect(ExamQr.tryParse('EV1.301.4567.1.1.Q2M7K3PA'), isNull);
      expect(ExamQr.tryParse('EVX1.301.x.1.1.Q2M7K3PA'), isNull);
      expect(ExamQr.tryParse(null), isNull);
    });
  });
}
