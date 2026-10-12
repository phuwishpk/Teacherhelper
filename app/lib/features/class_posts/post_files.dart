import 'dart:io' show Directory, File;
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/widgets/content_column.dart';
import 'class_post_models.dart';

/// Opens the attachments of a post: a link in the browser, a file through
/// the authenticated API (opened in a viewer on Android, a browser download
/// on the web, which has no file system). Behind a provider so tests can
/// fake it.
class PostFiles {
  PostFiles(this._dio);

  final Dio _dio;

  Future<bool> openLink(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null) return false;
    return launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  /// Null on success, else the Thai message to show.
  Future<String?> openFile(PostFile file) async {
    final res = await _dio.get<List<int>>(
      resolveApiPath(file.downloadUrl),
      options: Options(
        responseType: ResponseType.bytes,
        receiveTimeout: const Duration(minutes: 2),
      ),
    );
    final data = res.data ?? const <int>[];
    final bytes = data is Uint8List ? data : Uint8List.fromList(data);
    if (kIsWeb) {
      await FilePicker.saveFile(
        fileName: file.name,
        bytes: bytes,
        mimeType: file.mimeType,
      );
      return null;
    }
    final dir = await getTemporaryDirectory();
    final target = p.join(dir.path, 'class-posts', '${file.id}', file.name);
    await Directory(p.dirname(target)).create(recursive: true);
    await File(target).writeAsBytes(bytes, flush: true);
    final result = await OpenFilex.open(target, type: file.mimeType);
    return result.type == ResultType.done
        ? null
        : 'เปิดไฟล์ไม่ได้: ${result.message}';
  }
}

typedef PickedPostFile = ({String name, Uint8List bytes});

/// The system file picker for "แนบไฟล์". Bytes rather than a path: a picked
/// Android file may be a content:// URI, and the web has no path at all.
Future<List<PickedPostFile>> pickPostFiles() async {
  final files = await FilePicker.pickFiles(
    dialogTitle: 'เลือกไฟล์ที่จะแนบ',
    type: FileType.custom,
    allowedExtensions: const [
      'pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', //
      'docx', 'xlsx', 'pptx', 'doc', 'xls', 'ppt',
    ],
  );
  return [for (final f in files) (name: f.name, bytes: await f.readAsBytes())];
}

final postFilePickerProvider =
    Provider<Future<List<PickedPostFile>> Function()>((ref) => pickPostFiles);

final postFilesProvider = Provider<PostFiles>(
  (ref) => PostFiles(ref.watch(dioProvider)),
);

/// One attachment of a post as a row: tap opens the link or the file.
class PostAttachmentTile extends ConsumerStatefulWidget {
  const PostAttachmentTile({
    super.key,
    required this.attachment,
    this.onRemove,
  });

  final PostAttachment attachment;

  /// The compose form: a remove button instead of opening on tap.
  final VoidCallback? onRemove;

  @override
  ConsumerState<PostAttachmentTile> createState() => _PostAttachmentTileState();
}

class _PostAttachmentTileState extends ConsumerState<PostAttachmentTile> {
  bool _busy = false;

  Future<void> _open() async {
    final a = widget.attachment;
    final files = ref.read(postFilesProvider);
    setState(() => _busy = true);
    try {
      final file = a.file;
      if (file == null) {
        final ok = await files.openLink(a.url ?? '');
        if (!ok && mounted) showMessage(context, 'เปิดลิงก์ไม่ได้');
      } else {
        final problem = await files.openFile(file);
        if (problem != null && mounted) showMessage(context, problem);
      }
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.attachment;
    final file = a.file;
    final theme = Theme.of(context);
    final icon = file == null
        ? Icons.link
        : file.isPdf
        ? Icons.picture_as_pdf_outlined
        : file.isImage
        ? Icons.image_outlined
        : Icons.insert_drive_file_outlined;
    return Material(
      color: theme.colorScheme.surfaceContainerHighest,
      shape: theme.cardTheme.shape,
      clipBehavior: Clip.antiAlias,
      child: ListTile(
        dense: true,
        leading: _busy
            ? const SizedBox.square(
                dimension: 24,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Icon(icon),
        title: Text(a.title, maxLines: 1, overflow: TextOverflow.ellipsis),
        subtitle: Text(
          file == null ? (a.url ?? '') : file.sizeLabel,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: widget.onRemove != null
            ? IconButton(
                tooltip: 'เอาออก',
                icon: const Icon(Icons.close),
                onPressed: widget.onRemove,
              )
            : Icon(
                file == null ? Icons.open_in_new : Icons.download_outlined,
                size: 20,
              ),
        onTap: widget.onRemove != null || _busy ? null : _open,
      ),
    );
  }
}
