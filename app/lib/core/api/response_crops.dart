import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/auth_repository.dart';
import '../auth/session.dart';

/// Answer crops have no public URL (DESIGN §7.3): they are fetched with the
/// bearer token from `GET /responses/{id}/crop[?part=final]`, which the
/// teacher of the classroom and (after publishing) the student may call.
abstract class CropLoader {
  Future<Uint8List> crop(int responseId, {bool finalPart = false});
}

class ApiCropLoader implements CropLoader {
  ApiCropLoader(this._dio);

  final Dio _dio;

  @override
  Future<Uint8List> crop(int responseId, {bool finalPart = false}) async {
    final res = await _dio.get<List<int>>(
      '/responses/$responseId/crop',
      queryParameters: {if (finalPart) 'part': 'final'},
      options: Options(
        responseType: ResponseType.bytes,
        headers: {'Accept': 'image/webp,image/*'},
      ),
    );
    final data = res.data ?? const <int>[];
    return data is Uint8List ? data : Uint8List.fromList(data);
  }
}

final cropLoaderProvider = Provider<CropLoader>(
  (ref) => ApiCropLoader(ref.watch(dioProvider)),
);

typedef CropKey = ({int responseId, bool finalPart});

/// Bytes of one crop; kept while a widget shows it.
final responseCropProvider = FutureProvider.autoDispose
    .family<Uint8List, CropKey>((ref, key) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(cropLoaderProvider)
          .crop(key.responseId, finalPart: key.finalPart);
    });
