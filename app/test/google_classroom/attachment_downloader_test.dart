import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/features/google_classroom/attachment_downloader.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

const _token = DriveAccessToken(
  value: 'ya29.test-token',
  email: 'kru@school.ac.th',
);

const _photo = GoogleAttachment(
  driveFileId: '1AbC-xyz',
  title: 'IMG_0012.jpg',
  mimeType: 'image/jpeg',
);

ResponseBody _bytes(int status, List<int> bytes, {int? length}) =>
    ResponseBody.fromBytes(
      bytes,
      status,
      headers: {
        Headers.contentTypeHeader: ['image/jpeg'],
        Headers.contentLengthHeader: ['${length ?? bytes.length}'],
      },
    );

/// The Drive download runs on the teacher's phone with an on-device token
/// (DESIGN §18.5), against a fake HTTP adapter: no network.
void main() {
  late Directory dir;

  setUp(() async {
    dir = await Directory.systemTemp.createTemp('drive_download_test');
  });

  tearDown(() async {
    if (await dir.exists()) await dir.delete(recursive: true);
  });

  DriveAttachmentDownloader downloaderWith(
    FakeHttpAdapter adapter, {
    int maxBytes = DriveAttachmentDownloader.defaultMaxBytes,
  }) {
    final dio = Dio()..httpClientAdapter = adapter;
    return DriveAttachmentDownloader(dio: dio, maxBytes: maxBytes);
  }

  test('downloads alt=media with the bearer token into the folder', () async {
    final adapter = FakeHttpAdapter(
      (_) async => _bytes(200, [0xFF, 0xD8, 0xFF, 0xE0, 1, 2, 3]),
    );
    final file = await downloaderWith(adapter).download(_photo, _token, dir);

    final req = adapter.requests.single;
    expect(req.method, 'GET');
    expect(req.uri.host, 'www.googleapis.com');
    expect(req.uri.path, '/drive/v3/files/1AbC-xyz');
    expect(req.uri.queryParameters['alt'], 'media');
    expect(req.headers['Authorization'], 'Bearer ya29.test-token');
    expect(file.path, startsWith(dir.path));
    expect(file.path, endsWith('1AbC-xyz.jpg'));
    expect(await file.readAsBytes(), [0xFF, 0xD8, 0xFF, 0xE0, 1, 2, 3]);
  });

  test('keeps an unsafe file id out of the local path', () async {
    final adapter = FakeHttpAdapter((_) async => _bytes(200, [1]));
    final file = await downloaderWith(adapter).download(
      const GoogleAttachment(
        driveFileId: '../../etc/passwd',
        title: 'x.pdf',
        mimeType: 'application/pdf',
      ),
      _token,
      dir,
    );
    expect(file.parent.path, dir.path);
    expect(file.path, endsWith('.pdf'));
    expect(
      adapter.requests.single.uri.path,
      '/drive/v3/files/..%2F..%2Fetc%2Fpasswd',
    );
  });

  test('maps Drive errors to Thai reasons', () async {
    Future<Object?> failWith(int status) async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(status, {
          'error': {'code': status},
        }),
      );
      try {
        await downloaderWith(adapter).download(_photo, _token, dir);
        return null;
      } catch (e) {
        return e;
      }
    }

    expect(await failWith(401), isA<DriveTokenRejected>());
    expect(await failWith(403), isA<DriveAccessDenied>());
    expect(await failWith(404), isA<DriveFileMissing>());
    final other = await failWith(500);
    expect(other, isA<DownloadFailed>());
    expect((other! as DownloadFailure).message, contains('HTTP 500'));
    expect(dir.listSync(), isEmpty, reason: 'no half files are left');
  });

  test(
    'refuses Google Docs and other unsupported types without a request',
    () async {
      final adapter = FakeHttpAdapter((_) async => _bytes(200, [1]));
      await expectLater(
        downloaderWith(adapter).download(
          const GoogleAttachment(
            driveFileId: 'doc1',
            title: 'รายงาน',
            mimeType: 'application/vnd.google-apps.document',
          ),
          _token,
          dir,
        ),
        throwsA(
          isA<AttachmentUnsupported>().having(
            (e) => e.message,
            'message',
            contains('รูปถ่าย'),
          ),
        ),
      );
      expect(adapter.requests, isEmpty);
    },
  );

  test('stops at the size limit from Content-Length', () async {
    final adapter = FakeHttpAdapter(
      (_) async => _bytes(200, [1, 2, 3], length: 50 * 1024 * 1024),
    );
    await expectLater(
      downloaderWith(adapter, maxBytes: 1024).download(_photo, _token, dir),
      throwsA(isA<AttachmentTooLarge>()),
    );
  });

  test(
    'stops at the size limit while streaming and deletes the file',
    () async {
      final adapter = FakeHttpAdapter(
        (_) async => ResponseBody(
          Stream.fromIterable([Uint8List(600), Uint8List(600)]),
          200,
        ),
      );
      await expectLater(
        downloaderWith(adapter, maxBytes: 1000).download(_photo, _token, dir),
        throwsA(isA<AttachmentTooLarge>()),
      );
      expect(dir.listSync(), isEmpty);
    },
  );

  test('an empty body is a failure', () async {
    final adapter = FakeHttpAdapter((_) async => _bytes(200, const []));
    await expectLater(
      downloaderWith(adapter).download(_photo, _token, dir),
      throwsA(isA<DownloadFailed>()),
    );
  });
}
