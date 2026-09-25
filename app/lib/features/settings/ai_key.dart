import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';

/// `GET /me/ai-key` (DESIGN §9.1, §10.1). The key itself never comes back:
/// only whether one is set, its last 4 characters and when it last worked.
class AiKeyStatus {
  const AiKeyStatus({
    required this.configured,
    this.keyLast4,
    this.lastVerifiedAt,
    this.serverKeyAvailable = false,
  });

  final bool configured;
  final String? keyLast4;
  final DateTime? lastVerifiedAt;

  /// The server has its own `GEMINI_API_KEY` to fall back on.
  final bool serverKeyAvailable;

  /// "••••1234".
  String get masked => '••••${keyLast4 ?? ''}';

  /// Grading can use Gemini (teacher key first, then the server key).
  bool get canGrade => configured || serverKeyAvailable;

  factory AiKeyStatus.fromJson(Map<String, dynamic> json) {
    final verified = json['last_verified_at'];
    return AiKeyStatus(
      configured: json['configured'] == true,
      keyLast4: json['key_last4'] as String?,
      lastVerifiedAt: verified is String ? DateTime.tryParse(verified) : null,
      serverKeyAvailable: json['server_key_available'] == true,
    );
  }
}

/// The teacher's own Gemini key. The app sends it once over HTTPS and keeps
/// no copy (not in secure storage, not in logs).
abstract class AiKeyRepository {
  Future<AiKeyStatus> status();

  /// The server test-calls Gemini first; an unusable key is a 422 with
  /// `code: ai_key_invalid`.
  Future<AiKeyStatus> save(String geminiApiKey);
  Future<AiKeyStatus> delete();
}

class ApiAiKeyRepository implements AiKeyRepository {
  ApiAiKeyRepository(this._dio);

  final Dio _dio;

  @override
  Future<AiKeyStatus> status() async {
    final res = await _dio.get<Object?>('/me/ai-key');
    return AiKeyStatus.fromJson(unwrapJson(res.data));
  }

  @override
  Future<AiKeyStatus> save(String geminiApiKey) async {
    final res = await _dio.put<Object?>(
      '/me/ai-key',
      data: {'gemini_api_key': geminiApiKey},
    );
    return AiKeyStatus.fromJson(unwrapJson(res.data));
  }

  @override
  Future<AiKeyStatus> delete() async {
    final res = await _dio.delete<Object?>('/me/ai-key');
    final body = res.data;
    if (body is Map &&
        (body.containsKey('configured') || body['data'] is Map)) {
      return AiKeyStatus.fromJson(unwrapJson(body));
    }
    // 204: ask again so server_key_available stays right.
    return status();
  }
}

final aiKeyRepositoryProvider = Provider<AiKeyRepository>(
  (ref) => ApiAiKeyRepository(ref.watch(dioProvider)),
);

class AiKeyNotifier extends AsyncNotifier<AiKeyStatus> {
  @override
  Future<AiKeyStatus> build() => ref.watch(aiKeyRepositoryProvider).status();

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  /// Throws the DioException on failure so the form can show the reason.
  Future<void> save(String key) async {
    final status = await ref.read(aiKeyRepositoryProvider).save(key);
    state = AsyncData(status);
  }

  Future<void> delete() async {
    final status = await ref.read(aiKeyRepositoryProvider).delete();
    state = AsyncData(status);
  }
}

final aiKeyProvider = AsyncNotifierProvider<AiKeyNotifier, AiKeyStatus>(
  AiKeyNotifier.new,
);

/// Thai message for a failed save: the field error or message the server
/// gave for `ai_key_invalid` (422), otherwise the generic API error text.
String aiKeyErrorMessage(Object error) {
  final data = error is DioException ? error.response?.data : null;
  final fieldErrors = data is Map ? data['errors'] : null;
  if (fieldErrors is Map && fieldErrors['gemini_api_key'] is List) {
    final first = (fieldErrors['gemini_api_key'] as List).firstOrNull;
    if (first is String && first.isNotEmpty) return first;
  }
  if (apiErrorCode(error) == 'ai_key_invalid') {
    final message = data is Map ? data['message'] : null;
    return message is String && message.isNotEmpty
        ? message
        : 'Gemini ไม่ยอมรับ key นี้ ตรวจว่าคัดลอกมาครบ '
              'และเปิดใช้ Generative Language API แล้ว';
  }
  return apiErrorMessage(error);
}
