import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/content_column.dart';
import '../scan/scan_camera.dart';
import 'exam_models.dart';
import 'exam_scan_camera.dart';
import 'exam_scan_repository.dart';
import 'exam_sheet_scanner.dart';

/// What the key-sheet scan hands back to the key grid: the proposals of
/// every page read, for one version.
class KeySheetScanResult {
  const KeySheetScanResult({required this.versionNo, required this.items});

  final int versionNo;
  final List<KeySheetProposalItem> items;
}

/// "สแกนกระดาษเฉลย" (DESIGN §22.3 way 2): the teacher bubbled the key of one
/// version on the key sheet (QR student 0). Each page is read on the phone
/// and sent to `POST /exams/{id}/key-sheet-read`, which answers a proposal
/// in original positions; nothing is saved until the teacher saves the
/// grid. Page 2 has no version bubbles, so the version of page 1 goes with
/// it. Android only.
class ExamKeySheetScanScreen extends ConsumerStatefulWidget {
  const ExamKeySheetScanScreen({super.key, required this.examId});

  final int examId;

  @override
  ConsumerState<ExamKeySheetScanScreen> createState() =>
      _ExamKeySheetScanScreenState();
}

class _ExamKeySheetScanScreenState
    extends ConsumerState<ExamKeySheetScanScreen> {
  late final ExamSheetScanner _scanner = ref.read(examSheetScannerProvider);
  late final bool _supported = !kIsWeb && _scanner.isSupported;

  FrameScanCamera? _camera;
  bool _ready = false;
  String? _cameraError;
  bool _busy = false;
  String? _error;

  int? _versionNo;

  /// Page -> proposal of that page (a rescan of a page replaces it).
  final _pages = <int, KeySheetProposal>{};

  @override
  void initState() {
    super.initState();
    if (_supported) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) unawaited(_open());
      });
    }
  }

  @override
  void dispose() {
    final camera = _camera;
    _camera = null;
    if (camera != null) unawaited(camera.dispose());
    super.dispose();
  }

  Future<void> _open() async {
    final camera = ref.read(frameScanCameraFactoryProvider)();
    _camera = camera;
    try {
      await camera.initialize();
      if (!mounted || _camera != camera) {
        await camera.dispose();
        return;
      }
      setState(() => _ready = true);
    } catch (e) {
      if (_camera == camera) _camera = null;
      await camera.dispose();
      if (!mounted) return;
      setState(
        () => _cameraError = e is ScanCameraException
            ? e.message
            : 'เปิดกล้องไม่ได้ ลองใหม่อีกครั้ง',
      );
    }
  }

  Future<void> _capture() async {
    final camera = _camera;
    if (camera == null || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final path = await camera.takePicture();
      final pagesOf = ref.read(examLayoutPagesProvider);
      final outcome = await _scanner.readKeySheet(
        path,
        widget.examId,
        layoutPages: (version) => pagesOf(widget.examId, version),
        repository: ref.read(examScanRepositoryProvider),
        versionNo: _versionNo,
      );
      if (!mounted) return;
      switch (outcome) {
        case KeySheetRejected(:final message):
          setState(() => _error = message);
        case KeySheetRead(:final proposal):
          setState(() {
            if (_versionNo != null && _versionNo != proposal.versionNo) {
              // Another version's sheet: start over with it.
              _pages.clear();
            }
            _versionNo = proposal.versionNo;
            _pages[proposal.page] = proposal;
          });
      }
    } catch (e) {
      if (mounted) setState(() => _error = 'ถ่ายภาพไม่สำเร็จ ลองใหม่อีกครั้ง');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _finish() {
    final pages = _pages.keys.toList()..sort();
    Navigator.of(context).pop(
      KeySheetScanResult(
        versionNo: _versionNo!,
        items: [for (final p in pages) ..._pages[p]!.items],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    const title = Text('สแกนกระดาษเฉลย');
    if (!_supported) {
      return Scaffold(
        appBar: AppBar(title: title),
        body: const Center(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Text(
              'การสแกนกระดาษเฉลยต้องใช้แอป Krucheck บนโทรศัพท์ Android '
              'บนเว็บกรอกเฉลยในตารางแทน',
              textAlign: TextAlign.center,
            ),
          ),
        ),
      );
    }
    final theme = Theme.of(context);
    final camera = _camera;
    final pages = _pages.keys.toList()..sort();
    final items = [for (final p in pages) ..._pages[p]!.items];
    return Scaffold(
      appBar: AppBar(title: title),
      body: ContentColumn(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'ฝนเฉลยของชุดใดชุดหนึ่งบนกระดาษเฉลย (ฝนวงชุดที่หน้า 1 ด้วย) '
              'แล้วถ่ายทีละหน้า ข้อที่ยอมรับหลายตัวเลือกฝนได้หลายวง '
              'ค่าตัวเลขเพิ่มเติมพิมพ์ในตาราง',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 8),
            Expanded(
              child: _cameraError != null
                  ? Center(child: Text(_cameraError!))
                  : camera == null || !_ready
                  ? const Center(child: CircularProgressIndicator())
                  : Center(
                      child: AspectRatio(
                        aspectRatio: camera.previewAspectRatio,
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: camera.buildPreview(),
                        ),
                      ),
                    ),
            ),
            if (_error != null)
              Card(
                key: const ValueKey('key_sheet_error'),
                color: theme.colorScheme.errorContainer,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(_error!),
                ),
              ),
            if (_pages.isNotEmpty)
              Card(
                key: const ValueKey('key_sheet_result'),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    'ชุด ${examVersionLabel(_versionNo!)} '
                    'หน้า ${pages.join(', ')}: อ่านได้ '
                    '${items.where((i) => i.key != null).length} ข้อ '
                    '· ต่างจากเฉลยเดิม ${items.where((i) => i.differs).length} ข้อ '
                    '· อ่านไม่ชัด ${items.where((i) => i.doubtful).length} ข้อ',
                  ),
                ),
              ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    key: const ValueKey('key_sheet_shutter'),
                    onPressed: _ready && !_busy ? _capture : null,
                    icon: const Icon(Icons.camera_alt),
                    label: Text(
                      _busy
                          ? 'กำลังอ่าน…'
                          : _pages.isEmpty
                          ? 'ถ่ายกระดาษเฉลย'
                          : 'ถ่ายอีกหน้า',
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton(
                    key: const ValueKey('key_sheet_use'),
                    onPressed: _pages.isEmpty || _busy ? null : _finish,
                    child: const Text('เติมในตารางเฉลย'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
