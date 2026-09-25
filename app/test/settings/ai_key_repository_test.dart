import 'dart:convert';

import 'package:eduvision/features/settings/ai_key.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../review/review_fixtures.dart';

/// `/me/ai-key` (DESIGN §9.1): the key goes up once and never comes back.
void main() {
  test(
    'status parses configured, last4, verified time and server key',
    () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(200, {
          'data': {
            'configured': true,
            'key_last4': '1234',
            'last_verified_at': '2026-10-01T02:00:00Z',
            'server_key_available': true,
          },
        }),
      );
      final s = await ApiAiKeyRepository(fakeDio(adapter)).status();
      expect(adapter.requests.single.uri.path, '/api/v1/me/ai-key');
      expect(s.configured, isTrue);
      expect(s.masked, '••••1234');
      expect(s.lastVerifiedAt, DateTime.utc(2026, 10, 1, 2));
      expect(s.serverKeyAvailable, isTrue);
    },
  );

  test(
    'save PUTs {gemini_api_key}; delete with 204 re-reads the status',
    () async {
      final adapter = FakeHttpAdapter((options) async {
        return switch (options.method) {
          'PUT' => jsonResponse(200, {
            'configured': true,
            'key_last4': 'WXYZ',
            'last_verified_at': null,
          }),
          'DELETE' => jsonResponse(204, null),
          _ => jsonResponse(200, {
            'configured': false,
            'key_last4': null,
            'server_key_available': false,
          }),
        };
      });
      final repo = ApiAiKeyRepository(fakeDio(adapter));

      final saved = await repo.save('AIzaTESTWXYZ');
      final put = adapter.requests.first;
      expect('${put.method} ${put.uri.path}', 'PUT /api/v1/me/ai-key');
      final body = put.data is String
          ? jsonDecode(put.data as String)
          : put.data;
      expect(body, {'gemini_api_key': 'AIzaTESTWXYZ'});
      expect(saved.keyLast4, 'WXYZ');

      final after = await repo.delete();
      expect(adapter.requests.skip(1).map((r) => r.method).toList(), [
        'DELETE',
        'GET',
      ]);
      expect(after.configured, isFalse);
    },
  );

  test('ai_key_invalid shows the field error, then the message', () {
    expect(
      aiKeyErrorMessage(
        apiError(422, {
          'message': 'key ใช้ไม่ได้',
          'errors': {
            'gemini_api_key': ['Gemini ปฏิเสธ key นี้ (API_KEY_INVALID)'],
          },
          'code': 'ai_key_invalid',
        }),
      ),
      'Gemini ปฏิเสธ key นี้ (API_KEY_INVALID)',
    );
    expect(
      aiKeyErrorMessage(
        apiError(422, {'message': 'key ใช้ไม่ได้', 'code': 'ai_key_invalid'}),
      ),
      'key ใช้ไม่ได้',
    );
  });
}
