import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/key_document_sources.dart';
import 'exam_providers.dart';
import 'exams_repository.dart';

/// Largest question/option image the server takes (5 MB, §22.15).
const kExamImageMaxBytes = 5 * 1024 * 1024;

/// Why [file] cannot be a question image, or null. The server takes JPEG,
/// PNG and WebP up to 5 MB (no PDF or HEIC).
String? examImageProblem(PickedDocument file) {
  final type = file.mimeType;
  if (type != 'image/jpeg' && type != 'image/png' && type != 'image/webp') {
    return 'ใช้ได้เฉพาะรูป JPG, PNG หรือ WebP';
  }
  final bytes = file.bytes;
  if (bytes != null && bytes.length > kExamImageMaxBytes) {
    return 'รูปใหญ่เกิน 5 MB';
  }
  return null;
}

/// "ถ่ายรูป" (not in the Chrome preview) or "เลือกรูป" for one image;
/// null when the teacher cancels or the file is refused (a message says
/// why).
Future<PickedDocument?> pickExamImage(
  BuildContext context,
  WidgetRef ref,
) async {
  final source = kIsWeb
      ? 'pick'
      : await showModalBottomSheet<String>(
          context: context,
          builder: (context) => SafeArea(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                ListTile(
                  leading: const Icon(Icons.photo_camera_outlined),
                  title: const Text('ถ่ายรูป'),
                  onTap: () => Navigator.of(context).pop('camera'),
                ),
                ListTile(
                  leading: const Icon(Icons.photo_library_outlined),
                  title: const Text('เลือกรูปจากเครื่อง'),
                  onTap: () => Navigator.of(context).pop('pick'),
                ),
              ],
            ),
          ),
        );
  if (source == null || !context.mounted) return null;
  final List<PickedDocument> picked;
  if (source == 'camera') {
    picked =
        await Navigator.of(context).push<List<PickedDocument>>(
          MaterialPageRoute(
            builder: (_) => const KeyPhotoScreen(
              title: 'ถ่ายภาพประกอบ',
              hint: 'ถ่ายเฉพาะภาพประกอบ ให้ตรงและชัด',
              maxPhotos: 1,
              namePrefix: 'figure',
            ),
          ),
        ) ??
        const [];
  } else {
    picked = await ref
        .read(documentFilePickerProvider)
        .pick(imagesOnly: true, dialogTitle: 'เลือกภาพประกอบ');
  }
  if (picked.isEmpty || !context.mounted) return null;
  final file = picked.first;
  final problem = examImageProblem(file);
  if (problem != null) {
    showMessage(context, problem);
    return null;
  }
  return file;
}

/// A question or option image loaded through the authenticated API.
class ExamImageView extends ConsumerWidget {
  const ExamImageView({super.key, required this.imageKey, this.height = 160});

  final ExamImageKey imageKey;
  final double height;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final image = ref.watch(examImageProvider(imageKey));
    final theme = Theme.of(context);
    Widget box(Widget child) => Container(
      height: height,
      width: double.infinity,
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      alignment: Alignment.center,
      child: child,
    );
    return image.when(
      loading: () => box(const CircularProgressIndicator()),
      error: (e, _) => box(
        TextButton.icon(
          onPressed: () => ref.invalidate(examImageProvider(imageKey)),
          icon: const Icon(Icons.refresh),
          label: Text(
            apiStatusCode(e) == 404 ? 'ไม่พบภาพ' : 'โหลดภาพไม่ได้ ลองใหม่',
          ),
        ),
      ),
      data: (bytes) => box(
        Image.memory(
          bytes,
          fit: BoxFit.contain,
          gaplessPlayback: true,
          errorBuilder: (_, _, _) => const Text('เปิดไฟล์ภาพไม่ได้'),
        ),
      ),
    );
  }
}

/// The image of a question or option in the question form: the saved
/// image ([savedKey]), a new one waiting for the first save ([pending]),
/// or none, with "เพิ่มภาพ"/"เปลี่ยนภาพ" and "ลบภาพ".
class ExamImageField extends ConsumerWidget {
  const ExamImageField({
    super.key,
    required this.label,
    required this.onPick,
    required this.onRemove,
    this.savedKey,
    this.pending,
    this.enabled = true,
    this.compact = false,
    this.fieldKey,
  });

  final String label;
  final ExamImageKey? savedKey;
  final PickedDocument? pending;
  final ValueChanged<PickedDocument> onPick;
  final VoidCallback onRemove;
  final bool enabled;

  /// Smaller preview for an option row.
  final bool compact;

  /// Prefix of the buttons' keys in tests.
  final String? fieldKey;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final saved = savedKey;
    final local = pending;
    final hasImage = local != null || saved != null;
    final height = compact ? 90.0 : 160.0;
    Widget? preview;
    if (local != null) {
      final bytes = local.bytes;
      preview = bytes != null
          ? Container(
              height: height,
              alignment: Alignment.center,
              child: Image.memory(bytes, fit: BoxFit.contain),
            )
          : Chip(
              avatar: const Icon(Icons.image_outlined, size: 18),
              label: Text(local.name, overflow: TextOverflow.ellipsis),
            );
    } else if (saved != null) {
      preview = ExamImageView(imageKey: saved, height: height);
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ?preview,
        if (local != null)
          Text(
            'ภาพใหม่ จะอัปโหลดเมื่อบันทึก',
            style: theme.textTheme.bodySmall,
          ),
        Wrap(
          spacing: 4,
          children: [
            TextButton.icon(
              key: fieldKey == null ? null : ValueKey('${fieldKey}_pick'),
              onPressed: enabled
                  ? () async {
                      final file = await pickExamImage(context, ref);
                      if (file != null) onPick(file);
                    }
                  : null,
              icon: const Icon(Icons.add_photo_alternate_outlined),
              label: Text(hasImage ? 'เปลี่ยน$label' : 'เพิ่ม$label'),
            ),
            if (hasImage)
              TextButton.icon(
                key: fieldKey == null ? null : ValueKey('${fieldKey}_remove'),
                onPressed: enabled ? onRemove : null,
                icon: const Icon(Icons.hide_image_outlined),
                label: Text('ลบ$label'),
              ),
          ],
        ),
      ],
    );
  }
}
