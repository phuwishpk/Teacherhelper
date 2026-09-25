import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/auth_repository.dart';

/// `POST /devices {fcm_token}` (DESIGN §9.1): ties this install's FCM token
/// to the signed-in user. Registering again refreshes it server-side.
abstract class DevicesRepository {
  Future<void> register(String fcmToken);
}

class ApiDevicesRepository implements DevicesRepository {
  ApiDevicesRepository(this._dio);

  final Dio _dio;

  @override
  Future<void> register(String fcmToken) async {
    await _dio.post<Object?>('/devices', data: {'fcm_token': fcmToken});
  }
}

final devicesRepositoryProvider = Provider<DevicesRepository>(
  (ref) => ApiDevicesRepository(ref.watch(dioProvider)),
);
