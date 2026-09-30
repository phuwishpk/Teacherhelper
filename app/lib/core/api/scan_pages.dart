import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/auth_repository.dart';
import '../auth/session.dart';
import 'api_retry.dart';

/// Warped pages of scanned sheets have no public URL (DESIGN §7.3): the
/// teacher fetches them with the bearer token from `GET /scans/{id}/page`
/// (WebP). The server deletes them after publishing (410 `image_purged`).
abstract class ScanPageLoader {
  Future<Uint8List> page(int scanId);
}

class ApiScanPageLoader implements ScanPageLoader {
  ApiScanPageLoader(this._dio);

  final Dio _dio;

  @override
  Future<Uint8List> page(int scanId) async {
    final res = await _dio.get<List<int>>(
      '/scans/$scanId/page',
      options: Options(
        responseType: ResponseType.bytes,
        receiveTimeout: const Duration(minutes: 1),
        headers: {'Accept': 'image/webp,image/*'},
      ),
    );
    final data = res.data ?? const <int>[];
    return data is Uint8List ? data : Uint8List.fromList(data);
  }
}

final scanPageLoaderProvider = Provider<ScanPageLoader>(
  (ref) => ApiScanPageLoader(ref.watch(dioProvider)),
);

/// Bytes of one warped page; kept while a widget shows it. A 4xx (410
/// deleted, 404) is not asked again.
final scanPageProvider = FutureProvider.autoDispose.family<Uint8List, int>((
  ref,
  scanId,
) {
  watchSignedInUser(ref, keepAlive: false);
  return ref.watch(scanPageLoaderProvider).page(scanId);
}, retry: apiRetry);
