import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import 'answer_key_models.dart';

/// The teacher's answer key endpoints (DESIGN §19.5, §19.9): upload files,
/// read the key from them once or let AI draft it, then approve.
abstract class AnswerKeyRepository {
  /// `POST /documents` multipart `files[]`.
  Future<List<SourceDocument>> upload(List<PickedDocument> files);

  /// `GET /assignments/{id}/answer-key`.
  Future<AnswerKeyState> answerKey(int assignmentId);

  /// `POST /assignments/{id}/answer-key/estimate`: free, queues nothing.
  Future<KeyEstimate> estimate(
    int assignmentId, {
    required KeyRequestKind kind,
    List<int> documentIds = const [],
    int? pageFrom,
    int? pageTo,
  });

  /// `POST .../answer-key/extract` (read) or `.../draft` (AI drafts it).
  Future<KeyRequestResult> request(
    int assignmentId, {
    required KeyRequestKind kind,
    List<int> documentIds = const [],
    int? pageFrom,
    int? pageTo,
  });

  /// `POST /assignments/{id}/answer-key/approve`.
  Future<AnswerKeyState> approve(int assignmentId);
}

class ApiAnswerKeyRepository implements AnswerKeyRepository {
  ApiAnswerKeyRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<SourceDocument>> upload(List<PickedDocument> files) async {
    final form = FormData();
    for (final f in files) {
      final type = DioMediaType.parse(f.mimeType);
      final bytes = f.bytes;
      form.files.add(
        MapEntry(
          'files[]',
          bytes != null
              ? MultipartFile.fromBytes(
                  bytes,
                  filename: f.name,
                  contentType: type,
                )
              : await MultipartFile.fromFile(
                  f.path!,
                  filename: f.name,
                  contentType: type,
                ),
        ),
      );
    }
    final res = await _dio.post<Object?>(
      '/documents',
      data: form,
      options: Options(
        sendTimeout: const Duration(minutes: 3),
        receiveTimeout: const Duration(minutes: 2),
      ),
    );
    return unwrapList(res.data).map(SourceDocument.fromJson).toList();
  }

  @override
  Future<AnswerKeyState> answerKey(int assignmentId) async {
    final res = await _dio.get<Object?>(
      '/assignments/$assignmentId/answer-key',
    );
    return AnswerKeyState.fromJson(unwrapJson(res.data));
  }

  @override
  Future<KeyEstimate> estimate(
    int assignmentId, {
    required KeyRequestKind kind,
    List<int> documentIds = const [],
    int? pageFrom,
    int? pageTo,
  }) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/answer-key/estimate',
      data: {
        'kind': kind.apiValue,
        ..._selection(documentIds, pageFrom, pageTo),
      },
    );
    return KeyEstimate.fromJson(unwrapJson(res.data));
  }

  @override
  Future<KeyRequestResult> request(
    int assignmentId, {
    required KeyRequestKind kind,
    List<int> documentIds = const [],
    int? pageFrom,
    int? pageTo,
  }) async {
    final action = kind == KeyRequestKind.read ? 'extract' : 'draft';
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/answer-key/$action',
      data: _selection(documentIds, pageFrom, pageTo),
    );
    return KeyRequestResult.fromJson(unwrapJson(res.data));
  }

  @override
  Future<AnswerKeyState> approve(int assignmentId) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/answer-key/approve',
    );
    return AnswerKeyState.fromJson(unwrapJson(res.data));
  }

  static Map<String, dynamic> _selection(
    List<int> documentIds,
    int? pageFrom,
    int? pageTo,
  ) => {
    if (documentIds.isNotEmpty) 'document_ids': documentIds,
    if (pageFrom != null && pageTo != null) ...{
      'page_from': pageFrom,
      'page_to': pageTo,
    },
  };
}

final answerKeyRepositoryProvider = Provider<AnswerKeyRepository>(
  (ref) => ApiAnswerKeyRepository(ref.watch(dioProvider)),
);
