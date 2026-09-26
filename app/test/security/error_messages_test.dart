import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:flutter_test/flutter_test.dart';

DioException _badResponse(int status, Object? data) {
  final options = RequestOptions(
    path: 'https://api.school.test/api/v1/classrooms',
    headers: {'Authorization': 'Bearer top-secret-token'},
  );
  return DioException(
    requestOptions: options,
    response: Response(requestOptions: options, statusCode: status, data: data),
    type: DioExceptionType.badResponse,
  );
}

/// What the user sees when the API fails: the `{message, errors, code}`
/// envelope of DESIGN §9 and nothing else (no URL, no token, no server
/// internals).
void main() {
  test('a Laravel error envelope shows its message and exposes its code', () {
    final e = _badResponse(422, {
      'message': 'ชื่อห้องซ้ำ',
      'errors': {
        'name': ['ชื่อห้องซ้ำ'],
      },
      'code': 'validation_failed',
    });
    expect(apiErrorMessage(e), 'ชื่อห้องซ้ำ');
    expect(apiErrorCode(e), 'validation_failed');
    expect(apiStatusCode(e), 422);
  });

  test('an HTML 500 page is never shown to the user', () {
    final e = _badResponse(
      500,
      '<html><body>Whoops, looks like something went wrong. '
      'PDOException in /var/www/vhosts/eduvision/app/Models/User.php:42'
      '</body></html>',
    );
    final message = apiErrorMessage(e);
    expect(message, 'เซิร์ฟเวอร์ตอบกลับผิดพลาด (500)');
    expect(message, isNot(contains('/var/www')));
    expect(message, isNot(contains('PDOException')));
    expect(apiErrorCode(e), isNull);
  });

  test('a debug-mode exception body without `message` shows the generic '
      'text', () {
    final e = _badResponse(500, {
      'exception': 'Illuminate\\Database\\QueryException',
      'file': '/var/www/vhosts/eduvision/vendor/laravel/x.php',
      'line': 760,
      'trace': [
        {'file': '/var/www/vhosts/eduvision/app/Http/X.php'},
      ],
    });
    final message = apiErrorMessage(e);
    expect(message, 'เซิร์ฟเวอร์ตอบกลับผิดพลาด (500)');
    expect(message, isNot(contains('/var/www')));
    expect(message, isNot(contains('QueryException')));
  });

  test('a message that is not a string is ignored, not rendered', () {
    final e = _badResponse(500, {
      'message': {'secret': 'db password'},
    });
    expect(apiErrorMessage(e), 'เซิร์ฟเวอร์ตอบกลับผิดพลาด (500)');
  });

  test('connection errors name neither the URL nor the token', () {
    final e = DioException.connectionError(
      requestOptions: RequestOptions(
        path: 'https://api.school.test/api/v1/me',
        headers: {'Authorization': 'Bearer top-secret-token'},
      ),
      reason:
          'Connection refused (api.school.test:443) Bearer top-secret-token',
    );
    final message = apiErrorMessage(e);
    expect(message, 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ (connectionError)');
    expect(message, isNot(contains('top-secret-token')));
    expect(message, isNot(contains('api.school.test')));
    expect(apiStatusCode(e), isNull);
  });

  test('unknown errors show a fixed text, never the exception', () {
    expect(
      apiErrorMessage(StateError('db password=hunter2')),
      'เกิดข้อผิดพลาดที่ไม่คาดคิด',
    );
    expect(apiErrorCode(StateError('x')), isNull);
    expect(apiStatusCode(StateError('x')), isNull);
  });

  test('parse errors of our own describe the shape, not the payload', () {
    expect(
      () => unwrapJson('<html>secret</html>'),
      throwsA(
        isA<FormatException>().having(
          (e) => apiErrorMessage(e),
          'message',
          'ข้อมูลจากเซิร์ฟเวอร์ไม่ถูกต้อง (Expected a JSON object from the API)',
        ),
      ),
    );
    expect(() => unwrapList('secret'), throwsA(isA<FormatException>()));
  });
}
