import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:path/path.dart' as p;

import '../core/api/api_client.dart';
import '../core/db/app_database.dart';
import 'digit_model_spec.dart';

/// `GET /ml/models/active?name=` (DESIGN §9.8): the version the app should
/// run. `metrics` is `model_versions.metrics` (the exported metrics.json),
/// which carries the decode contract and the abstain threshold.
class ActiveModel {
  const ActiveModel({
    required this.name,
    required this.version,
    required this.sha256,
    required this.downloadUrl,
    this.id,
    this.metrics = const {},
  });

  final int? id;
  final String name;
  final String version;
  final String sha256;
  final String downloadUrl;
  final Map<String, dynamic> metrics;

  factory ActiveModel.fromJson(Map<String, dynamic> json, String name) {
    final id = (json['id'] as num?)?.toInt();
    final url =
        json['download_url'] as String? ??
        (id == null ? null : '/ml/models/$id/file');
    final sha = json['sha256'];
    final version = json['version'];
    if (url == null || sha is! String || version == null) {
      throw const FormatException('active model needs version, sha256, url');
    }
    return ActiveModel(
      id: id,
      name: json['name'] as String? ?? name,
      version: version.toString(),
      sha256: sha.toLowerCase(),
      downloadUrl: url,
      metrics: json['metrics'] is Map
          ? (json['metrics'] as Map).cast<String, dynamic>()
          : const {},
    );
  }
}

/// A verified model file on this device (`model_cache` row + file).
class InstalledModel {
  const InstalledModel({
    required this.name,
    required this.version,
    required this.sha256,
    required this.file,
    required this.metrics,
  });

  final String name;
  final String version;
  final String sha256;
  final File file;
  final Map<String, dynamic> metrics;
}

sealed class ModelSyncResult {
  const ModelSyncResult();
}

/// The installed model already is the active one.
final class ModelUpToDate extends ModelSyncResult {
  const ModelUpToDate(this.model);

  final InstalledModel model;
}

/// A new version was downloaded, verified and switched to.
final class ModelUpdated extends ModelSyncResult {
  const ModelUpdated(this.model);

  final InstalledModel model;
}

/// The server has no active model of that name (404); nothing changes.
final class NoActiveModel extends ModelSyncResult {
  const NoActiveModel();
}

/// Offline, a server error, a checksum mismatch or an unsupported model.
/// The installed model (if any) stays in use.
final class ModelSyncFailed extends ModelSyncResult {
  const ModelSyncFailed(this.reason);

  final String reason;
}

/// Downloads the active model of a name, verifies its sha256 BEFORE
/// switching to it, and keeps it in `model_cache` + a file (DESIGN §6.4,
/// §9.8). A metrics.json sidecar next to the file keeps the decode contract
/// for offline use.
class ModelRepository {
  ModelRepository({
    required this._dio,
    required this._db,
    required this._directory,
  });

  final Dio _dio;
  final AppDatabase _db;
  final Future<Directory> Function() _directory;

  /// Null when the server has no active model of [name].
  Future<ActiveModel?> active(String name) async {
    try {
      final res = await _dio.get<Object?>(
        '/ml/models/active',
        queryParameters: {'name': name},
      );
      return ActiveModel.fromJson(unwrapJson(res.data), name);
    } on DioException catch (e) {
      if (e.response?.statusCode == 404) return null;
      rethrow;
    }
  }

  /// The installed model of [name], or null. A row whose file is gone is
  /// dropped.
  Future<InstalledModel?> installed(String name) async {
    final row = await (_db.select(
      _db.modelCache,
    )..where((m) => m.name.equals(name))).getSingleOrNull();
    if (row == null) return null;
    final file = File(row.path);
    if (!await file.exists()) {
      await (_db.delete(
        _db.modelCache,
      )..where((m) => m.name.equals(name))).go();
      return null;
    }
    return InstalledModel(
      name: row.name,
      version: row.version,
      sha256: row.sha256,
      file: file,
      metrics: await _readMetrics(_metricsFile(row.path)),
    );
  }

  /// Makes the active version of [name] the installed one.
  Future<ModelSyncResult> sync(String name) async {
    final ActiveModel? active;
    try {
      active = await this.active(name);
    } on DioException catch (e) {
      return ModelSyncFailed(apiErrorMessage(e));
    } on FormatException catch (e) {
      return ModelSyncFailed(
        'ข้อมูลโมเดลจากเซิร์ฟเวอร์ไม่ถูกต้อง (${e.message})',
      );
    }
    if (active == null) return const NoActiveModel();

    final current = await installed(name);
    if (current != null &&
        current.version == active.version &&
        current.sha256 == active.sha256) {
      return ModelUpToDate(current);
    }

    try {
      DigitModelSpec.fromMetrics(active.metrics);
    } on UnsupportedDigitModelException catch (e) {
      return ModelSyncFailed(
        'แอปนี้ใช้โมเดลเวอร์ชัน ${active.version} ไม่ได้ ($e)',
      );
    }

    final dir = Directory(p.join((await _directory()).path, _safe(name)));
    await dir.create(recursive: true);
    final base = '${_safe(active.version)}-${active.sha256.substring(0, 8)}';
    final target = File(p.join(dir.path, '$base.tflite'));
    final part = File('${target.path}.part');
    try {
      await _dio.download(
        resolveApiPath(active.downloadUrl),
        part.path,
        options: Options(receiveTimeout: const Duration(minutes: 2)),
      );
      final digest = await fileSha256(part);
      if (digest != active.sha256) {
        await _deleteQuietly(part);
        return ModelSyncFailed(
          'ไฟล์โมเดลที่ดาวน์โหลดไม่ตรงกับ sha256 ของเซิร์ฟเวอร์ จึงยังไม่เปลี่ยนไปใช้',
        );
      }
      await _metricsFile(target.path).writeAsString(jsonEncode(active.metrics));
      await part.rename(target.path);
    } on DioException catch (e) {
      await _deleteQuietly(part);
      return ModelSyncFailed(apiErrorMessage(e));
    } on FileSystemException catch (e) {
      await _deleteQuietly(part);
      return ModelSyncFailed('บันทึกไฟล์โมเดลไม่ได้ (${e.message})');
    }

    await _db
        .into(_db.modelCache)
        .insertOnConflictUpdate(
          ModelCacheCompanion.insert(
            name: name,
            version: active.version,
            sha256: active.sha256,
            path: target.path,
            downloadedAt: DateTime.now().toUtc(),
          ),
        );
    if (current != null && current.file.path != target.path) {
      await _deleteQuietly(current.file);
      await _deleteQuietly(_metricsFile(current.file.path));
    }
    return ModelUpdated(
      InstalledModel(
        name: name,
        version: active.version,
        sha256: active.sha256,
        file: target,
        metrics: active.metrics,
      ),
    );
  }

  static File _metricsFile(String modelPath) =>
      File('${p.withoutExtension(modelPath)}.metrics.json');

  static Future<Map<String, dynamic>> _readMetrics(File file) async {
    try {
      final json = jsonDecode(await file.readAsString());
      if (json is Map) return json.cast<String, dynamic>();
    } catch (_) {
      // Missing or broken sidecar: the spec falls back to its defaults.
    }
    return const {};
  }

  static String _safe(String s) =>
      s.replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');

  static Future<void> _deleteQuietly(File f) async {
    try {
      if (await f.exists()) await f.delete();
    } on FileSystemException {
      // Best effort.
    }
  }
}

/// Lower-case hex sha256 of a file, streamed.
Future<String> fileSha256(File file) async =>
    (await sha256.bind(file.openRead()).first).toString();
