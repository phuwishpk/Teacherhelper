import 'package:eduvision/features/review/review_labels.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:flutter_test/flutter_test.dart';

import 'review_fixtures.dart';

ReviewItem _row(
  int id, {
  String band = 'check',
  String state = 'scored',
  String? manualReason,
  bool suspicious = false,
  bool identityMismatch = false,
}) => ReviewItem.fromJson(
  queueRow(
    id: id,
    band: band,
    state: state,
    manualReason: manualReason,
    suspicious: suspicious,
    identityMismatch: identityMismatch,
  ),
);

void main() {
  group('identity_mismatch (DESIGN §18.3)', () {
    test('a confident row is not bulk-approvable and sits on "ต้องตรวจ"', () {
      final flagged = _row(1, band: 'confident', identityMismatch: true);
      expect(flagged.band, PriorityBand.confident);
      expect(flagged.identityMismatch, isTrue);
      expect(flagged.bulkApprovable, isFalse);
      expect(flagged.tab, PriorityBand.check);

      final plain = _row(2, band: 'confident');
      expect(plain.bulkApprovable, isTrue);
      expect(plain.tab, PriorityBand.confident);

      final queue = ReviewQueue.fromParts([flagged, plain], null);
      expect(queue.bulkApprovable.map((i) => i.id), [2]);
      expect(queue.tab(PriorityBand.confident).map((i) => i.id), [2]);
    });

    test('queue order: manual, then flagged, then review_priority', () {
      final queue = ReviewQueue.fromParts([
        _row(1), // check, p = 0.7
        _row(2, band: 'confident', identityMismatch: true), // p = 0.05
        _row(3, suspicious: true), // check, p = 0.7
        _row(4, state: 'manual', manualReason: 'ai_failed'),
      ], null);
      expect(queue.tab(PriorityBand.check).map((i) => i.id), [4, 3, 2, 1]);
    });

    test('also read from fuzzy_trace, where the backend may keep it', () {
      final d = ResponseDetail.fromJson({
        ...responseJson(),
        'fuzzy_trace': {'identity_mismatch': true},
      });
      expect(d.identityMismatch, isTrue);
    });
  });

  group('prompt-injection flag inside the stored JSON (§10.3, §11.8)', () {
    test('extraction.suspicious_instruction', () {
      final json = responseJson();
      expect(ResponseDetail.fromJson(json).isSuspicious, isFalse);
      (json['extraction'] as Map)['suspicious_instruction'] = true;
      expect(ResponseDetail.fromJson(json).isSuspicious, isTrue);
    });

    test('the backend trace: priority flag or top-level '
        'suspicious_instruction', () {
      final byFlag = ResponseDetail.fromJson({
        ...responseJson(),
        'fuzzy_trace': {
          'system': 'short',
          'score': null,
          'priority': {
            'system': 'review_priority',
            'flag': 'suspicious',
            'p': 1.0,
            'band': 'check',
          },
        },
      });
      expect(byFlag.isSuspicious, isTrue);

      final byTop = ResponseDetail.fromJson({
        ...responseJson(),
        'fuzzy_trace': {'system': 'short', 'suspicious_instruction': true},
      });
      expect(byTop.isSuspicious, isTrue);

      final manualFlag = ResponseDetail.fromJson({
        ...responseJson(),
        'fuzzy_trace': {
          'priority': {'system': 'review_priority', 'flag': 'manual'},
        },
      });
      expect(manualFlag.isSuspicious, isFalse);
    });

    test('a queue row that carries the trace is flagged too', () {
      final item = ReviewItem.fromJson({
        ...queueRow(id: 9, band: 'confident'),
        'fuzzy_trace': {
          'priority': {'system': 'review_priority', 'flag': 'suspicious'},
        },
      });
      expect(item.isSuspicious, isTrue);
      expect(item.bulkApprovable, isFalse);
    });
  });

  test('manual_reason falls back to fuzzy_trace.manual_reason', () {
    final d = ResponseDetail.fromJson({
      ...responseJson(aiScore: null, state: 'manual'),
      'fuzzy_trace': {'manual_reason': 'ai_key_missing'},
    });
    expect(d.manualReason, 'ai_key_missing');

    final item = ReviewItem.fromJson({
      ...queueRow(id: 5, state: 'manual'),
      'fuzzy_trace': {'manual_reason': 'ai_key_missing'},
    });
    expect(item.missingAiKey, isTrue);
  });
}
