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
import '../../core/widgets/content_column.dart';
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

/// Bottom sheet for a PDF that was just made (worksheets, QR login cards):
/// open it (Android), save or download it, or share it. The web has no file
/// to open, so "ดาวน์โหลด" is its first choice (DESIGN §25.2).
Future<void> showPdfFileActions(
  BuildContext context, {
  required PdfFiles files,
  required PdfFile file,
  required String title,
}) {
  Future<void> save() async {
    try {
      final saved = await files.save(file);
      if (saved && context.mounted) {
        showMessage(context, 'บันทึก ${file.name} แล้ว');
      }
    } catch (e) {
      if (context.mounted) showMessage(context, 'บันทึกไฟล์ไม่ได้: $e');
    }
  }

  Future<void> share() async {
    try {
      await files.share(file, subject: title);
    } catch (e) {
      if (context.mounted) showMessage(context, 'แชร์ไฟล์ไม่ได้: $e');
    }
  }

  return showModalBottomSheet<void>(
    context: context,
    builder: (sheetContext) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ListTile(title: Text(title), subtitle: Text(file.name)),
          if (files.canOpen)
            ListTile(
              key: const ValueKey('pdf_open'),
              leading: const Icon(Icons.open_in_new),
              title: const Text('เปิดไฟล์'),
              onTap: () {
                Navigator.of(sheetContext).pop();
                files.open(context, file);
              },
            ),
          ListTile(
            key: const ValueKey('pdf_save'),
            leading: const Icon(Icons.download_outlined),
            title: Text(files.saveLabel),
            onTap: () {
              Navigator.of(sheetContext).pop();
              save();
            },
          ),
          ListTile(
            key: const ValueKey('pdf_share'),
            leading: const Icon(Icons.share_outlined),
            title: const Text('แชร์ / ส่งไปพิมพ์'),
            onTap: () {
              Navigator.of(sheetContext).pop();
              share();
            },
          ),
        ],
      ),
    ),
  );
}
