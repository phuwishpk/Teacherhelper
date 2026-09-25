import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;

import 'google_auth.dart';
import 'google_models.dart';

/// Why an attachment could not be downloaded. [message] is Thai.
sealed class DownloadFailure implements Exception {
  const DownloadFailure();

  String get message;
}

/// Google rejected the token (expired or revoked): get a new one and retry.
final class DriveTokenRejected extends DownloadFailure {
  const DriveTokenRejected();

  @override
  String get message => 'สิทธิ์ Google Drive หมดอายุ ลองอีกครั้ง';
}

final class DriveAccessDenied extends DownloadFailure {
  const DriveAccessDenied();

  @override
  String get message =>
      'บัญชี Google ของครูเปิดไฟล์นี้ไม่ได้ (นักเรียนอาจแนบไฟล์ที่ไม่ได้แชร์ในงาน)';
}

final class DriveFileMissing extends DownloadFailure {
  const DriveFileMissing();

  @override
  String get message => 'ไม่พบไฟล์ใน Google Drive (นักเรียนอาจลบไฟล์ไปแล้ว)';
}

final class AttachmentUnsupported extends DownloadFailure {
  const AttachmentUnsupported(this.mimeType);

  final String mimeType;

  @override
  String get message =>
      'ไฟล์ชนิดนี้สแกนไม่ได้ ต้องเป็นรูปถ่าย (JPEG, PNG, HEIC) หรือ PDF';
}

final class AttachmentTooLarge extends DownloadFailure {
  const AttachmentTooLarge(this.maxBytes);

  final int maxBytes;

  @override
  String get message =>
      'ไฟล์ใหญ่เกิน ${maxBytes ~/ (1024 * 1024)} MB ให้นักเรียนส่งรูปที่เล็กลง';
}

final class DownloadFailed extends DownloadFailure {
  const DownloadFailed(this.detail);

  final String detail;

  @override
  String get message => 'ดาวน์โหลดไฟล์จาก Google Drive ไม่สำเร็จ ($detail)';
}

/// Downloads a student's attachment straight from Google Drive with the
/// teacher's on-device token (DESIGN §18.1, §18.5): the file never passes
/// through our server, and the token is only put in this request's header.
class DriveAttachmentDownloader {
  DriveAttachmentDownloader({Dio? dio, this.maxBytes = defaultMaxBytes})
    : _dio =
          dio ??
          Dio(
            BaseOptions(
              connectTimeout: const Duration(seconds: 15),
              receiveTimeout: const Duration(seconds: 60),
            ),
          );

  static const driveFilesUrl = 'https://www.googleapis.com/drive/v3/files';

  /// A phone photo is 2-8 MB; a multi-page PDF of photos more.
  static const defaultMaxBytes = 40 * 1024 * 1024;

  /// Its own Dio: never the API client, whose interceptor adds our token.
  final Dio _dio;
  final int maxBytes;

  /// Saves [attachment] into [directory] and returns the file.
  Future<File> download(
    GoogleAttachment attachment,
    DriveAccessToken token,
    Directory directory,
  ) async {
    if (!attachment.isSupported) {
      throw AttachmentUnsupported(attachment.mimeType);
    }
    await directory.create(recursive: true);
    final file = File(
      p.join(
        directory.path,
        '${_safe(attachment.driveFileId)}${_extension(attachment)}',
      ),
    );
    final Response<ResponseBody> res;
    try {
      res = await _dio.get<ResponseBody>(
        '$driveFilesUrl/${Uri.encodeComponent(attachment.driveFileId)}',
        queryParameters: {'alt': 'media', 'supportsAllDrives': 'true'},
        options: Options(
          responseType: ResponseType.stream,
          headers: {'Authorization': 'Bearer ${token.value}'},
        ),
      );
    } on DioException catch (e) {
      throw switch (e.response?.statusCode) {
        401 => const DriveTokenRejected(),
        403 => const DriveAccessDenied(),
        404 => const DriveFileMissing(),
        final int status => DownloadFailed('HTTP $status'),
        null => DownloadFailed(e.type.name),
      };
    }

    final length = int.tryParse(
      res.headers.value(Headers.contentLengthHeader) ?? '',
    );
    if (length != null && length > maxBytes) {
      throw AttachmentTooLarge(maxBytes);
    }
    final body = res.data;
    if (body == null) throw const DownloadFailed('empty body');

    final sink = file.openWrite();
    var total = 0;
    try {
      await for (final chunk in body.stream) {
        total += chunk.length;
        if (total > maxBytes) throw AttachmentTooLarge(maxBytes);
        sink.add(chunk);
      }
      await sink.flush();
    } catch (e) {
      await sink.close();
      await _deleteQuietly(file);
      if (e is DownloadFailure) rethrow;
      throw DownloadFailed(e is DioException ? e.type.name : '$e');
    }
    await sink.close();
    if (total == 0) {
      await _deleteQuietly(file);
      throw const DownloadFailed('empty file');
    }
    return file;
  }

  static Future<void> _deleteQuietly(File file) async {
    try {
      if (await file.exists()) await file.delete();
    } on FileSystemException {
      // A leftover temp file is harmless.
    }
  }

  static String _safe(String id) =>
      id.replaceAll(RegExp(r'[^A-Za-z0-9_-]'), '_');

  static String _extension(GoogleAttachment a) {
    final mime = a.mimeType.toLowerCase();
    return switch (mime) {
      'image/jpeg' || 'image/jpg' => '.jpg',
      'image/png' => '.png',
      'image/heic' => '.heic',
      'image/heif' => '.heif',
      'image/webp' => '.webp',
      'application/pdf' => '.pdf',
      _ => p.extension(a.title).length <= 6 ? p.extension(a.title) : '',
    };
  }
}

final driveAttachmentDownloaderProvider = Provider<DriveAttachmentDownloader>(
  (ref) => DriveAttachmentDownloader(),
);
