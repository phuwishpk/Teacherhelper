import 'package:dio/dio.dart';
import 'package:eduvision/core/api/error_codes.dart';
import 'package:eduvision/features/settings/ai_key_errors.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

DioException _error(int status, Map<String, Object?> body) => DioException(
  requestOptions: RequestOptions(path: '/x'),
  type: DioExceptionType.badResponse,
  response: Response(
    requestOptions: RequestOptions(path: '/x'),
    statusCode: status,
    data: body,
  ),
);

/// DESIGN §36: the code a teacher can report for a failed AI action.
void main() {
  test('the server code and call id, or the connection', () {
    final ai = errorCodeOf(
      _error(502, {
        'message': 'AI เขียนคำอธิบายไม่สำเร็จ',
        'code': 'ai_unavailable',
        'ai_error_code': 'AI-QUOTA',
        'ai_call_id': 48213,
      }),
    );
    expect(ai?.label, 'AI-QUOTA · #48213');
    expect(
      errorCodeOf(
        _error(422, {
          'code': 'ai_key_missing',
          'ai_error_code': 'AI-KEY-MISSING',
        }),
      )?.label,
      'AI-KEY-MISSING',
    );
    expect(errorCodeOf(_error(404, {'code': 'not_found'})), isNull);

    DioException net(DioExceptionType type, [Object? cause]) => DioException(
      requestOptions: RequestOptions(path: '/x'),
      type: type,
      error: cause,
    );
    expect(
      errorCodeOf(net(DioExceptionType.receiveTimeout))?.code,
      'NET-TIMEOUT',
    );
    expect(
      errorCodeOf(net(DioExceptionType.connectionError))?.code,
      'NET-OFFLINE',
    );
    expect(
      errorCodeOf(
        net(DioExceptionType.unknown, 'SocketException: Failed host lookup'),
      )?.code,
      'NET-OFFLINE',
    );
    expect(errorCodeOf(StateError('x')), isNull);
  });

  testWidgets('the AI snack bar shows the code and copies it', (tester) async {
    final error = _error(502, {
      'message': 'AI วิเคราะห์ไม่สำเร็จ: โควตาหรือเครดิตของ Gemini key หมด',
      'code': 'ai_unavailable',
      'ai_error_code': 'AI-QUOTA',
      'ai_call_id': 7,
    });
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => showAiError(context, error),
              child: const Text('go'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('go'));
    await tester.pump();

    expect(
      find.text(
        'AI วิเคราะห์ไม่สำเร็จ: โควตาหรือเครดิตของ Gemini key หมด\nรหัส AI-QUOTA · #7',
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('copy_error_code')), findsOneWidget);
  });
}
