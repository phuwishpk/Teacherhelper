import 'dart:io' show File;
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import 'pdf_actions.dart';

/// A downloaded PDF: a file in the app cache on Android, bytes in memory on
/// the web (no file system there).
class PdfFile {
  const PdfFile({required this.name, this.path, this.bytes});

  final String name;
  final String? path;
  final Uint8List? bytes;
}

/// Downloads, opens, saves and shares PDFs on Android and on the web (the
/// Chrome preview of CLAUDE.md). Behind a provider so tests can fake it.
class PdfFiles {
  PdfFiles(this._dio, this._downloader);

  final Dio _dio;
  final PdfDownloader _downloader;

  /// Opening in a viewer needs a file on disk (Android only); the web
  /// offers download and share instead.
  bool get canOpen => !kIsWeb;

  /// The save button: a browser download on the web.
  String get saveLabel => kIsWeb ? 'ดาวน์โหลด' : 'บันทึกลงเครื่อง';

  Future<PdfFile> fetch(String url, {required String fileName}) async {
    if (!kIsWeb) {
      final path = await _downloader.download(url, fileName: fileName);
      return PdfFile(name: fileName, path: path);
    }
    final res = await _dio.get<List<int>>(
      resolveApiPath(url),
      options: Options(
        responseType: ResponseType.bytes,
        receiveTimeout: const Duration(minutes: 2),
        headers: {'Accept': 'application/pdf'},
      ),
    );
    final data = res.data ?? const <int>[];
    return PdfFile(
      name: fileName,
      bytes: data is Uint8List ? data : Uint8List.fromList(data),
    );
  }

  Future<void> open(BuildContext context, PdfFile file) async {
    final path = file.path;
    if (path == null) return;
    await openPdf(context, path);
  }

  /// "บันทึกลงเครื่อง" (a save dialog on Android, a browser download on the
  /// web). False when the teacher cancelled.
  Future<bool> save(PdfFile file) async {
    final bytes = file.bytes ?? await File(file.path!).readAsBytes();
    final saved = await FilePicker.saveFile(
      fileName: file.name,
      bytes: bytes,
      mimeType: 'application/pdf',
    );
    return kIsWeb || saved != null;
  }

  /// The share sheet ("ส่งไปพิมพ์", LINE, Drive …); the web falls back to a
  /// download when the browser cannot share files.
  Future<void> share(PdfFile file, {required String subject}) async {
    final bytes = file.bytes;
    await SharePlus.instance.share(
      ShareParams(
        files: [
          if (bytes != null)
            XFile.fromData(bytes, mimeType: 'application/pdf', name: file.name)
          else
            XFile(file.path!, mimeType: 'application/pdf'),
        ],
        fileNameOverrides: [file.name],
        subject: subject,
      ),
    );
  }
}

final pdfFilesProvider = Provider<PdfFiles>(
  (ref) => PdfFiles(ref.watch(dioProvider), ref.watch(pdfDownloaderProvider)),
);
