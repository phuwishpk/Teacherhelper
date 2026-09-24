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
      apiOrigin: Uri.parse(baseUrl).origin,
    ),
  );
  return dio;
}

class _AuthInterceptor extends Interceptor {
  _AuthInterceptor({
    required this.tokenStorage,
    required this.onUnauthorized,
    required this.apiOrigin,
  });

  final TokenStorage tokenStorage;
  final OnUnauthorized onUnauthorized;

  /// Only requests to our own server carry the bearer token, so an absolute
  /// download URL pointing elsewhere can never leak it.
  final String apiOrigin;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    if (options.uri.origin == apiOrigin) {
      final token = await tokenStorage.read();
      if (token != null) {
        options.headers['Authorization'] = 'Bearer $token';
      }
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
/// Laravel errors look like {"message": "...", "errors": {...}, "code": "..."}.
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
  if (error is FormatException) {
    return 'ข้อมูลจากเซิร์ฟเวอร์ไม่ถูกต้อง (${error.message})';
  }
  return 'เกิดข้อผิดพลาดที่ไม่คาดคิด';
}

/// The machine-readable `code` from an error body (DESIGN §9), if any.
String? apiErrorCode(Object error) {
  if (error is DioException) {
    final data = error.response?.data;
    if (data is Map && data['code'] is String) return data['code'] as String;
  }
  return null;
}

int? apiStatusCode(Object error) =>
    error is DioException ? error.response?.statusCode : null;

/// Laravel API Resources may wrap payloads in {"data": ...}; accept both.
Map<String, dynamic> unwrapJson(Object? body) {
  if (body is Map<String, dynamic>) {
    final inner = body['data'];
    if (inner is Map<String, dynamic>) return inner;
    return body;
  }
  throw const FormatException('Expected a JSON object from the API');
}

/// A list payload, either bare `[...]` or wrapped as `{"data": [...]}`.
List<Map<String, dynamic>> unwrapList(Object? body) {
  final list = switch (body) {
    List<dynamic> l => l,
    Map<String, dynamic> m when m['data'] is List => m['data'] as List,
    _ => throw const FormatException('Expected a JSON list from the API'),
  };
  return list.cast<Map<String, dynamic>>();
}

/// Cursor for the next page of a cursor-paginated list, if there is one.
/// Laravel puts it at `next_cursor` or `meta.next_cursor`.
String? nextCursorOf(Object? body) {
  if (body is! Map<String, dynamic>) return null;
  final direct = body['next_cursor'];
  if (direct is String && direct.isNotEmpty) return direct;
  final meta = body['meta'];
  if (meta is Map && meta['next_cursor'] is String) {
    final c = meta['next_cursor'] as String;
    return c.isEmpty ? null : c;
  }
  return null;
}

/// Follows cursor pagination until the last page and concatenates the rows.
Future<List<Map<String, dynamic>>> fetchAllPages(
  Dio dio,
  String path, {
  Map<String, dynamic>? query,
  int maxPages = 50,
}) async {
  final rows = <Map<String, dynamic>>[];
  String? cursor;
  for (var page = 0; page < maxPages; page++) {
    final res = await dio.get<Object?>(
      path,
      queryParameters: {...?query, 'cursor': ?cursor},
    );
    rows.addAll(unwrapList(res.data));
    cursor = nextCursorOf(res.data);
    if (cursor == null) break;
  }
  return rows;
}

/// Turns a download link from the API into something this Dio can fetch:
/// absolute URLs pass through, `/api/v1/...` loses the prefix (the base URL
/// already has it) and bare paths are used as-is.
String resolveApiPath(String url) {
  if (url.startsWith('http://') || url.startsWith('https://')) return url;
  if (url.startsWith(apiPrefix)) return url.substring(apiPrefix.length);
  return url;
}

final tokenStorageProvider = Provider<TokenStorage>(
  (ref) => SecureTokenStorage(),
);
