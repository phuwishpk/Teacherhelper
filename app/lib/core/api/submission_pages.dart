import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../auth/auth_repository.dart';
import '../auth/session.dart';
import 'api_retry.dart';

/// Files of whole-page submissions (DESIGN §19.4) have no public URL: they
/// are fetched with the bearer token from `GET /submission-pages/{id}/image`
/// (the classroom's teacher, or the student once published). The server
/// streams the file exactly as it was handed in: JPEG, PNG, WebP,
/// HEIC/HEIF or PDF.
abstract class SubmissionPageLoader {
  Future<Uint8List> page(int pageId);
}

class ApiSubmissionPageLoader implements SubmissionPageLoader {
  ApiSubmissionPageLoader(this._dio);

  final Dio _dio;

  @override
  Future<Uint8List> page(int pageId) async {
    final res = await _dio.get<List<int>>(
      '/submission-pages/$pageId/image',
      options: Options(
        responseType: ResponseType.bytes,
        receiveTimeout: const Duration(minutes: 2),
        headers: {'Accept': 'image/*,application/pdf'},
      ),
    );
    final data = res.data ?? const <int>[];
    return data is Uint8List ? data : Uint8List.fromList(data);
  }
}

final submissionPageLoaderProvider = Provider<SubmissionPageLoader>(
  (ref) => ApiSubmissionPageLoader(ref.watch(dioProvider)),
);

/// Bytes of one handed-in file; kept while a widget shows it. A 4xx
/// (410 purged, 404) is not asked again.
final submissionPageProvider = FutureProvider.autoDispose
    .family<Uint8List, int>((ref, pageId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(submissionPageLoaderProvider).page(pageId);
    }, retry: apiRetry);

/// File extension for a handed-in file's type.
String pageFileExtension(String? mimeType) => switch (mimeType) {
  'image/jpeg' => 'jpg',
  'image/png' => 'png',
  'image/webp' => 'webp',
  'image/heic' => 'heic',
  'image/heif' => 'heif',
  'application/pdf' => 'pdf',
  _ => 'bin',
};

/// "ดาวน์โหลดไฟล์" for what the app cannot draw (a PDF, or HEIC on a
/// device without the codec): the bytes go to the app's cache and another
/// app on the phone opens them.
abstract class PageFileOpener {
  /// Returns null when another app opened the file, else a Thai reason.
  Future<String?> open(
    Uint8List bytes, {
    required String fileName,
    String? mimeType,
  });
}

class LocalPageFileOpener implements PageFileOpener {
  @override
  Future<String?> open(
    Uint8List bytes, {
    required String fileName,
    String? mimeType,
  }) async {
    final dir = await getTemporaryDirectory();
    final target = p.join(dir.path, 'pages', fileName);
    await Directory(p.dirname(target)).create(recursive: true);
    await File(target).writeAsBytes(bytes, flush: true);
    final result = await OpenFilex.open(target, type: mimeType);
    return result.type == ResultType.done ? null : result.message;
  }
}

final pageFileOpenerProvider = Provider<PageFileOpener>(
  (ref) => LocalPageFileOpener(),
);
