import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../scan/scan_camera.dart';
import 'answer_key_models.dart';

/// Files for the teacher's key or question sheet ("แนบไฟล์", DESIGN §19.5),
/// behind an interface so widget tests run without the plugin.
abstract class DocumentFilePicker {
  /// PDFs and photos; [imagesOnly] for "เลือกรูปจากเครื่อง". Empty when the
  /// teacher cancels.
  Future<List<PickedDocument>> pick({bool imagesOnly = false});
}

/// `package:file_picker`: the system picker (Storage Access Framework on
/// Android). Only PDF and photo extensions are offered; Word and Google
/// Docs are refused by the server with "บันทึกเป็น PDF แล้วแนบใหม่".
class PluginDocumentFilePicker implements DocumentFilePicker {
  @override
  Future<List<PickedDocument>> pick({bool imagesOnly = false}) async {
    final files = await FilePicker.pickFiles(
      dialogTitle: imagesOnly ? 'เลือกรูปเฉลย' : 'เลือกไฟล์เฉลย',
      type: imagesOnly ? FileType.image : FileType.custom,
      allowedExtensions: imagesOnly ? null : kDocumentExtensions,
    );
    return [
      for (final f in files)
        // Bytes rather than a path: a picked Android file may be a
        // content:// URI, and the web runner has no path at all. Each file
        // is at most 10 MB (server limit).
        PickedDocument(name: f.name, bytes: await f.readAsBytes()),
    ];
  }
}

final documentFilePickerProvider = Provider<DocumentFilePicker>(
  (ref) => PluginDocumentFilePicker(),
);

/// Most files one upload takes (server `eduvision.documents.max_files`).
const kMaxDocumentFiles = 10;

/// "ถ่ายรูปเฉลย": several photos in a row with the key-photo camera, popped
/// as [PickedDocument]s. When the camera cannot open (no permission, the
/// Chrome preview) the teacher can pick photos from the device instead.
class KeyPhotoScreen extends ConsumerStatefulWidget {
  const KeyPhotoScreen({super.key});

  @override
  ConsumerState<KeyPhotoScreen> createState() => _KeyPhotoScreenState();
}

class _KeyPhotoScreenState extends ConsumerState<KeyPhotoScreen> {
  ScanCamera? _camera;
  bool _ready = false;
  bool _busy = false;
  String? _error;
  final _photos = <String>[];

  @override
  void initState() {
    super.initState();
    _open();
  }

  Future<void> _open() async {
    final camera = ref.read(keyPhotoCameraFactoryProvider)();
    _camera = camera;
    try {
      await camera.initialize();
      if (mounted) setState(() => _ready = true);
    } on ScanCameraException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'เปิดกล้องบนเครื่องนี้ไม่ได้');
    }
  }

  @override
  void dispose() {
    _camera?.dispose();
    super.dispose();
  }

  Future<void> _shoot() async {
    final camera = _camera;
    if (camera == null || _busy || _photos.length >= kMaxDocumentFiles) return;
    setState(() => _busy = true);
    try {
      final path = await camera.takePicture();
      if (mounted) setState(() => _photos.add(path));
    } on ScanCameraException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickInstead() async {
    final picked = await ref
        .read(documentFilePickerProvider)
        .pick(imagesOnly: true);
    if (!mounted || picked.isEmpty) return;
    Navigator.of(context).pop(picked.take(kMaxDocumentFiles).toList());
  }

  void _done() {
    Navigator.of(context).pop([
      for (var i = 0; i < _photos.length; i++)
        PickedDocument(name: 'key-photo-${i + 1}.jpg', path: _photos[i]),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final camera = _camera;
    final full = _photos.length >= kMaxDocumentFiles;
    return Scaffold(
      appBar: AppBar(
        title: const Text('ถ่ายรูปเฉลย'),
        actions: [
          TextButton(
            onPressed: _photos.isEmpty ? null : _done,
            child: Text('เสร็จ (${_photos.length})'),
          ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: _error != null && !_ready
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.no_photography_outlined,
                            size: 48,
                            color: theme.colorScheme.error,
                          ),
                          const SizedBox(height: 12),
                          Text(_error!, textAlign: TextAlign.center),
                          const SizedBox(height: 16),
                          FilledButton.tonalIcon(
                            onPressed: _pickInstead,
                            icon: const Icon(Icons.photo_library_outlined),
                            label: const Text('เลือกรูปจากเครื่องแทน'),
                          ),
                        ],
                      ),
                    ),
                  )
                : !_ready || camera == null
                ? const Center(child: CircularProgressIndicator())
                : Center(
                    child: AspectRatio(
                      aspectRatio: camera.previewAspectRatio,
                      child: camera.buildPreview(),
                    ),
                  ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    full
                        ? 'ถ่ายครบ $kMaxDocumentFiles รูปแล้ว (สูงสุดต่อครั้ง)'
                        : 'ถ่ายเฉลยทีละหน้า ให้เห็นทั้งหน้าและอ่านชัด',
                    style: theme.textTheme.bodySmall,
                    textAlign: TextAlign.center,
                  ),
                  if (_photos.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      alignment: WrapAlignment.center,
                      children: [
                        for (var i = 0; i < _photos.length; i++)
                          InputChip(
                            label: Text('หน้า ${i + 1}'),
                            deleteButtonTooltipMessage: 'ลบรูปนี้',
                            onDeleted: () =>
                                setState(() => _photos.removeAt(i)),
                          ),
                      ],
                    ),
                  ],
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      TextButton.icon(
                        onPressed: _pickInstead,
                        icon: const Icon(Icons.photo_library_outlined),
                        label: const Text('เลือกรูป'),
                      ),
                      const SizedBox(width: 16),
                      FilledButton.icon(
                        key: const ValueKey('key_photo_shutter'),
                        onPressed: !_ready || _busy || full ? null : _shoot,
                        icon: const Icon(Icons.photo_camera),
                        label: const Text('ถ่าย'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
