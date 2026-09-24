import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/widgets/content_column.dart';

/// Downloads a PDF through the authenticated Dio into the app cache and
/// returns the local path.
class PdfDownloader {
  PdfDownloader(this._dio);

  final Dio _dio;

  Future<String> download(String url, {required String fileName}) async {
    final dir = await getTemporaryDirectory();
    final target = p.join(dir.path, 'pdf', fileName);
    await Directory(p.dirname(target)).create(recursive: true);
    await _dio.download(
      resolveApiPath(url),
      target,
      options: Options(
        receiveTimeout: const Duration(minutes: 2),
        headers: {'Accept': 'application/pdf'},
      ),
    );
    return target;
  }
}

final pdfDownloaderProvider = Provider<PdfDownloader>(
  (ref) => PdfDownloader(ref.watch(dioProvider)),
);

Future<void> openPdf(BuildContext context, String path) async {
  final result = await OpenFilex.open(path, type: 'application/pdf');
  if (result.type != ResultType.done && context.mounted) {
    showMessage(context, 'เปิดไฟล์ไม่ได้: ${result.message}');
  }
}

Future<void> sharePdf(String path, {required String subject}) async {
  await SharePlus.instance.share(
    ShareParams(
      files: [XFile(path, mimeType: 'application/pdf')],
      subject: subject,
    ),
  );
}

/// Bottom sheet offering to open or share a downloaded PDF.
Future<void> showPdfActions(
  BuildContext context, {
  required String path,
  required String title,
}) {
  return showModalBottomSheet<void>(
    context: context,
    builder: (sheetContext) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ListTile(title: Text(title), subtitle: Text(p.basename(path))),
          ListTile(
            leading: const Icon(Icons.open_in_new),
            title: const Text('เปิดไฟล์'),
            onTap: () {
              Navigator.of(sheetContext).pop();
              openPdf(context, path);
            },
          ),
          ListTile(
            leading: const Icon(Icons.share_outlined),
            title: const Text('แชร์ / ส่งไปพิมพ์'),
            onTap: () {
              Navigator.of(sheetContext).pop();
              sharePdf(path, subject: title);
            },
          ),
        ],
      ),
    ),
  );
}
