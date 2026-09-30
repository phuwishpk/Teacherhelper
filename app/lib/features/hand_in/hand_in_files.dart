import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/content_column.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/key_document_sources.dart';
import 'hand_in_models.dart';

/// The pages of one hand-in: photos from the camera and/or images and PDFs
/// from `file_picker`, at most [kMaxHandInFiles] (DESIGN §19.6). Shared by
/// the student's "ส่งงาน" and the teacher's "อัปโหลดรูปเพื่อตรวจ".
class HandInFilesPanel extends ConsumerWidget {
  const HandInFilesPanel({
    super.key,
    required this.files,
    required this.onChanged,
    this.allowCamera = true,
    this.enabled = true,
    this.pickerTitle = 'เลือกรูปหรือ PDF ของงาน',
  });

  final List<PickedDocument> files;
  final ValueChanged<List<PickedDocument>> onChanged;

  /// "ถ่ายรูป" (the in-app camera). The Chrome preview has no camera, so
  /// the button is hidden on the web.
  final bool allowCamera;
  final bool enabled;
  final String pickerTitle;

  int get _room => kMaxHandInFiles - files.length;

  void _add(BuildContext context, List<PickedDocument> picked) {
    if (picked.isEmpty) return;
    final room = _room;
    if (picked.length > room) {
      showMessage(
        context,
        'ส่งได้ไม่เกิน $kMaxHandInFiles ไฟล์ต่อครั้ง '
        'ใช้ ${room < 0 ? 0 : room} ไฟล์แรกที่เลือก',
      );
    }
    onChanged([...files, ...picked.take(room < 0 ? 0 : room)]);
  }

  Future<void> _pick(BuildContext context, WidgetRef ref) async {
    final picked = await ref
        .read(documentFilePickerProvider)
        .pick(dialogTitle: pickerTitle);
    if (context.mounted) _add(context, picked);
  }

  Future<void> _shoot(BuildContext context) async {
    final photos = await Navigator.of(context).push<List<PickedDocument>>(
      MaterialPageRoute(
        builder: (_) => KeyPhotoScreen(
          title: 'ถ่ายรูปงาน',
          hint: 'ถ่ายงานทีละหน้า ให้เห็นทั้งหน้าและอ่านออก',
          maxPhotos: _room,
          namePrefix: 'page',
        ),
      ),
    );
    if (photos != null && context.mounted) _add(context, photos);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final full = _room <= 0;
    final problem = files.isEmpty ? null : handInFilesProblem(files);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            if (allowCamera && !kIsWeb)
              FilledButton.tonalIcon(
                key: const ValueKey('hand_in_camera'),
                onPressed: enabled && !full ? () => _shoot(context) : null,
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('ถ่ายรูป'),
              ),
            FilledButton.tonalIcon(
              key: const ValueKey('hand_in_pick'),
              onPressed: enabled && !full ? () => _pick(context, ref) : null,
              icon: const Icon(Icons.upload_file),
              label: const Text('เลือกรูปหรือ PDF'),
            ),
          ],
        ),
        const SizedBox(height: 8),
        Text(
          files.isEmpty
              ? 'รูป (JPG, PNG, WebP, HEIC) หรือ PDF รวมไม่เกิน '
                    '$kMaxHandInFiles หน้า ไฟล์ละไม่เกิน 10 MB'
              : 'เลือกแล้ว ${files.length}/$kMaxHandInFiles ไฟล์',
          style: theme.textTheme.bodySmall,
        ),
        if (files.isNotEmpty) ...[
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (var i = 0; i < files.length; i++)
                InputChip(
                  key: ValueKey('hand_in_file_$i'),
                  avatar: Icon(
                    files[i].mimeType == 'application/pdf'
                        ? Icons.picture_as_pdf_outlined
                        : Icons.image_outlined,
                    size: 18,
                  ),
                  label: Text(
                    'หน้า ${i + 1} · ${files[i].name}',
                    overflow: TextOverflow.ellipsis,
                  ),
                  deleteButtonTooltipMessage: 'เอาไฟล์นี้ออก',
                  onDeleted: enabled
                      ? () => onChanged([...files]..removeAt(i))
                      : null,
                ),
            ],
          ),
        ],
        if (problem != null) ...[
          const SizedBox(height: 8),
          Text(
            problem,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
      ],
    );
  }
}

/// Upload progress bar with the share sent, shown while a hand-in uploads.
class UploadProgressBar extends StatelessWidget {
  const UploadProgressBar({super.key, required this.progress});

  /// 0..1, or null while the size is unknown.
  final double? progress;

  @override
  Widget build(BuildContext context) {
    final p = progress;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        LinearProgressIndicator(value: p),
        const SizedBox(height: 4),
        Text(
          p == null
              ? 'กำลังอัปโหลด…'
              : p >= 1
              ? 'อัปโหลดครบแล้ว รอเซิร์ฟเวอร์รับงาน…'
              : 'กำลังอัปโหลด ${(p * 100).round()}%',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ],
    );
  }
}

/// Share sent from a Dio progress callback (null when the total is unknown).
double? progressShare(int sent, int total) =>
    total <= 0 ? null : (sent / total).clamp(0, 1).toDouble();
