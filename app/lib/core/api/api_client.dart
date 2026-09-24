import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/token_storage.dart';
import 'api_config.dart';

/// Called when the API answers 401 so the session can be cleared.
typedef OnUnauthorized = void Function();

Dio createDio({
  required TokenStorage tokenStorage,
  required OnUnauthorized onUnauthorized,
  String baseUrl = apiBaseUrl,
}) {
  final dio = Dio(
    BaseOptions(
      baseUrl: '$baseUrl$apiPrefix',
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 20),
      headers: {'Accept': 'application/json'},
    ),
  );
  dio.interceptors.add(
    _AuthInterceptor(
      tokenStorage: tokenStorage,
      onUnauthorized: onUnauthorized,
    ),
  );
  return dio;
}

class _AuthInterceptor extends Interceptor {
  _AuthInterceptor({required this.tokenStorage, required this.onUnauthorized});

  final TokenStorage tokenStorage;
  final OnUnauthorized onUnauthorized;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final token = await tokenStorage.read();
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    if (err.response?.statusCode == 401) {
      onUnauthorized();
    }
    handler.next(err);
  }
}

/// Human-readable (Thai) message for any error thrown by the API layer.
/// Laravel errors look like {"message": "...", "errors": {...}}.
String apiErrorMessage(Object error) {
  if (error is DioException) {
    final data = error.response?.data;
    if (data is Map && data['message'] is String) {
      return data['message'] as String;
    }
    if (error.response == null) {
      return 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ (${error.type.name})';
    }
    return 'เซิร์ฟเวอร์ตอบกลับผิดพลาด (${error.response?.statusCode})';
  }
  return 'เกิดข้อผิดพลาดที่ไม่คาดคิด';
}

/// Laravel API Resources may wrap payloads in {"data": ...}; accept both.
Map<String, dynamic> unwrapJson(Object? body) {
  if (body is Map<String, dynamic>) {
    final inner = body['data'];
    if (inner is Map<String, dynamic>) return inner;
    return body;
  }
  throw const FormatException('Expected a JSON object from the API');
}

final tokenStorageProvider = Provider<TokenStorage>(
  (ref) => SecureTokenStorage(),
);
