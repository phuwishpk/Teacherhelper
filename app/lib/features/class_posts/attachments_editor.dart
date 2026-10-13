import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import 'class_post_models.dart';
import 'class_posts_repository.dart';
import 'post_files.dart';

/// "ไฟล์และลิงก์" of a post or an assignment (DESIGN §30.2, §30.12): the
/// list so far, "แนบไฟล์" (picked and uploaded at once) and "แนบลิงก์".
/// [locked] shows the list without any way to change it (the copy is in
/// Classroom already).
class AttachmentsEditor extends ConsumerStatefulWidget {
  const AttachmentsEditor({
    super.key,
    required this.attachments,
    required this.onChanged,
    this.locked = false,
    this.enabled = true,
    this.onUploading,
    this.onError,
  });

  final List<PostAttachment> attachments;
  final ValueChanged<List<PostAttachment>> onChanged;
  final bool locked;
  final bool enabled;

  /// Told while files go up, so the form can hold its submit button.
  final ValueChanged<bool>? onUploading;
  final ValueChanged<String>? onError;

  @override
  ConsumerState<AttachmentsEditor> createState() => _AttachmentsEditorState();
}

class _AttachmentsEditorState extends ConsumerState<AttachmentsEditor> {
  bool _uploading = false;

  Future<void> _pickFiles() async {
    final picked = await ref.read(postFilePickerProvider)();
    if (picked.isEmpty || !mounted) return;
    setState(() => _uploading = true);
    widget.onUploading?.call(true);
    final repo = ref.read(classPostsRepositoryProvider);
    final list = [...widget.attachments];
    try {
      for (final f in picked) {
        final file = await repo.upload(name: f.name, bytes: f.bytes);
        if (!mounted) return;
        list.add(PostAttachment(title: file.name, file: file));
        widget.onChanged(list);
      }
    } catch (e) {
      widget.onError?.call(apiErrorMessage(e));
    } finally {
      ref.invalidate(postStorageUsageProvider);
      if (mounted) {
        setState(() => _uploading = false);
        widget.onUploading?.call(false);
      }
    }
  }

  Future<void> _addLink() async {
    final link = await showAddLinkDialog(context);
    if (link != null && mounted) {
      widget.onChanged([...widget.attachments, link]);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final busy = !widget.enabled;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (i, a) in widget.attachments.indexed) ...[
          PostAttachmentTile(
            key: ValueKey('attached_$i'),
            attachment: a,
            onRemove: busy || widget.locked
                ? null
                : () => widget.onChanged([
                    ...widget.attachments.take(i),
                    ...widget.attachments.skip(i + 1),
                  ]),
          ),
          const SizedBox(height: 8),
        ],
        if (widget.locked)
          Text(
            'ส่งไป Google Classroom แล้ว แก้ไฟล์แนบไม่ได้ '
            'ถ้าต้องเปลี่ยนไฟล์ ให้ลบแล้วสร้างใหม่',
            key: const ValueKey('post_files_locked'),
            style: muted,
          )
        else ...[
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              OutlinedButton.icon(
                key: const ValueKey('post_attach_file'),
                onPressed: busy || _uploading ? null : _pickFiles,
                icon: _uploading
                    ? const SizedBox.square(
                        dimension: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.attach_file),
                label: Text(_uploading ? 'กำลังอัปโหลด…' : 'แนบไฟล์'),
              ),
              OutlinedButton.icon(
                key: const ValueKey('post_attach_link'),
                onPressed: busy ? null : _addLink,
                icon: const Icon(Icons.link),
                label: const Text('แนบลิงก์'),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            [
              'PDF รูปภาพ และไฟล์ Word, Excel, PowerPoint ไฟล์ละไม่เกิน 10 MB รวมกับลิงก์ไม่เกิน 5 รายการ',
              ?ref.watch(postStorageUsageProvider).value?.label,
            ].join(' · '),
            style: muted,
          ),
        ],
      ],
    );
  }
}

/// "แนบลิงก์": an http(s) URL and an optional name. Pops the attachment.
Future<PostAttachment?> showAddLinkDialog(BuildContext context) =>
    showDialog<PostAttachment>(
      context: context,
      builder: (context) => const _LinkDialog(),
    );

class _LinkDialog extends StatefulWidget {
  const _LinkDialog();

  @override
  State<_LinkDialog> createState() => _LinkDialogState();
}

class _LinkDialogState extends State<_LinkDialog> {
  final _formKey = GlobalKey<FormState>();
  final _url = TextEditingController();
  final _title = TextEditingController();

  @override
  void dispose() {
    _url.dispose();
    _title.dispose();
    super.dispose();
  }

  void _done() {
    if (!_formKey.currentState!.validate()) return;
    final url = _url.text.trim();
    final title = _title.text.trim();
    Navigator.of(
      context,
    ).pop(PostAttachment(title: title.isEmpty ? url : title, url: url));
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('แนบลิงก์'),
      content: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextFormField(
              key: const ValueKey('link_url'),
              controller: _url,
              autofocus: true,
              keyboardType: TextInputType.url,
              decoration: const InputDecoration(
                labelText: 'ลิงก์',
                hintText: 'https://',
              ),
              validator: (v) {
                final uri = Uri.tryParse((v ?? '').trim());
                final ok =
                    uri != null &&
                    (uri.scheme == 'http' || uri.scheme == 'https') &&
                    uri.host.isNotEmpty;
                return ok ? null : 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://';
              },
            ),
            const SizedBox(height: 16),
            TextFormField(
              key: const ValueKey('link_title'),
              controller: _title,
              maxLength: 200,
              decoration: const InputDecoration(
                labelText: 'ชื่อที่แสดง (ไม่บังคับ)',
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('link_add'),
          onPressed: _done,
          child: const Text('แนบ'),
        ),
      ],
    );
  }
}
