import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/ml/model_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

String shaOf(List<int> bytes) => sha256.convert(bytes).toString();

void main() {
  late Directory tmp;
  late AppDatabase db;
  late Map<String, Uint8List> files;
  late Map<String, dynamic>? active;
  late FakeHttpAdapter adapter;
  late ModelRepository repo;

  final v1 = Uint8List.fromList(utf8.encode('model v0.1.0 bytes'));
  final v2 = Uint8List.fromList(utf8.encode('model v0.2.0 bytes, bigger'));
  const metrics = {
    'charset': '0123456789.-/',
    'blank_index': 13,
    'decode': {
      'method': 'ctc_greedy',
      'confidence': 'emitting_mean_max_prob',
      'abstain_below': 0.9,
    },
  };

  Map<String, dynamic> activeJson(
    String version,
    int id,
    Uint8List bytes, {
    String? sha,
    Map<String, dynamic> metrics = metrics,
  }) => {
    'id': id,
    'name': 'digit_crnn',
    'version': version,
    'sha256': sha ?? shaOf(bytes),
    'download_url': 'http://test.local/api/v1/ml/models/$id/file',
    'metrics': metrics,
  };

  setUp(() async {
    tmp = await Directory.systemTemp.createTemp('models_test');
    db = AppDatabase(NativeDatabase.memory());
    files = {'/api/v1/ml/models/3/file': v1, '/api/v1/ml/models/4/file': v2};
    active = activeJson('0.1.0', 3, v1);
    adapter = FakeHttpAdapter((options) async {
      final path = options.uri.path;
      if (path == '/api/v1/ml/models/active') {
        expect(options.uri.queryParameters, {'name': 'digit_crnn'});
        if (active == null) {
          return jsonResponse(404, {'message': 'ไม่มีโมเดลที่เปิดใช้งาน'});
        }
        return jsonResponse(200, {'data': active});
      }
      final bytes = files[path];
      if (bytes == null) return jsonResponse(404, {'message': 'not found'});
      return ResponseBody.fromBytes(bytes, 200);
    });
    repo = ModelRepository(
      dio: fakeDio(adapter),
      db: db,
      directory: () async => tmp,
    );
  });

  tearDown(() async {
    await db.close();
    await tmp.delete(recursive: true);
  });

  int downloads() =>
      adapter.requests.where((r) => r.uri.path.endsWith('/file')).length;

  test('downloads, verifies and installs the active model', () async {
    expect(await repo.installed('digit_crnn'), isNull);

    final result = await repo.sync('digit_crnn');
    expect(result, isA<ModelUpdated>());
    final installed = (await repo.installed('digit_crnn'))!;
    expect(installed.version, '0.1.0');
    expect(installed.sha256, shaOf(v1));
    expect(await installed.file.readAsBytes(), v1);
    expect(await fileSha256(installed.file), shaOf(v1));
    // The decode contract is kept next to the file for offline use.
    expect((installed.metrics['decode'] as Map)['abstain_below'], 0.9);
    expect(installed.file.path, startsWith(tmp.path));
    final row = await db.select(db.modelCache).getSingle();
    expect(row.name, 'digit_crnn');
    expect(row.path, installed.file.path);

    // Same version again: nothing is downloaded.
    expect(await repo.sync('digit_crnn'), isA<ModelUpToDate>());
    expect(downloads(), 1);
  });

  test('a checksum mismatch keeps the installed model', () async {
    await repo.sync('digit_crnn');
    final before = (await repo.installed('digit_crnn'))!;

    active = activeJson('0.2.0', 4, v2, sha: shaOf(v1));
    final result = await repo.sync('digit_crnn');
    expect(result, isA<ModelSyncFailed>());
    expect((result as ModelSyncFailed).reason, contains('sha256'));

    final after = (await repo.installed('digit_crnn'))!;
    expect(after.version, '0.1.0');
    expect(after.file.path, before.file.path);
    expect(await after.file.readAsBytes(), v1);
    // No half-downloaded file is left behind.
    final leftovers = tmp
        .listSync(recursive: true)
        .whereType<File>()
        .where((f) => f.path.endsWith('.part'));
    expect(leftovers, isEmpty);
  });

  test('a new active version replaces the old file', () async {
    await repo.sync('digit_crnn');
    final old = (await repo.installed('digit_crnn'))!;

    active = activeJson('0.2.0', 4, v2);
    expect(await repo.sync('digit_crnn'), isA<ModelUpdated>());
    final now = (await repo.installed('digit_crnn'))!;
    expect(now.version, '0.2.0');
    expect(await now.file.readAsBytes(), v2);
    expect(await old.file.exists(), isFalse);
    expect(await db.select(db.modelCache).get(), hasLength(1));
  });

  test('no active model (404) changes nothing', () async {
    active = null;
    expect(await repo.sync('digit_crnn'), isA<NoActiveModel>());
    expect(await repo.installed('digit_crnn'), isNull);
    expect(downloads(), 0);
  });

  test('offline keeps the cached model and reports a failure', () async {
    await repo.sync('digit_crnn');
    final offline = ModelRepository(
      dio: fakeDio(
        FakeHttpAdapter(
          (o) async => throw DioException.connectionError(
            requestOptions: o,
            reason: 'offline',
          ),
        ),
      ),
      db: db,
      directory: () async => tmp,
    );
    expect(await offline.sync('digit_crnn'), isA<ModelSyncFailed>());
    expect((await offline.installed('digit_crnn'))!.version, '0.1.0');
  });

  test('a model with an unknown decode is not downloaded', () async {
    active = activeJson(
      '0.2.0',
      4,
      v2,
      metrics: {
        'decode': {'method': 'ctc_beam'},
      },
    );
    final result = await repo.sync('digit_crnn');
    expect(result, isA<ModelSyncFailed>());
    expect(downloads(), 0);
  });

  test('a cache row whose file is gone is dropped', () async {
    await repo.sync('digit_crnn');
    final installed = (await repo.installed('digit_crnn'))!;
    await installed.file.delete();
    expect(await repo.installed('digit_crnn'), isNull);
    expect(await db.select(db.modelCache).get(), isEmpty);
    // The next sync downloads it again.
    expect(await repo.sync('digit_crnn'), isA<ModelUpdated>());
  });

  test('a relative download_url goes through the API base', () async {
    active = {
      ...activeJson('0.1.0', 3, v1),
      'download_url': '/api/v1/ml/models/3/file',
    };
    expect(await repo.sync('digit_crnn'), isA<ModelUpdated>());
    expect(
      adapter.requests.last.uri.toString(),
      'http://test.local/api/v1/ml/models/3/file',
    );
  });
}
