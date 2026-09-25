import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../../platform/scan_pipeline.dart';
import '../classrooms/classroom.dart';
import '../upload_queue/upload_queue_providers.dart';
import 'page_layout.dart';
import 'scan_camera.dart';
import 'scan_processor.dart';
import 'scan_quality.dart';

/// Continuous worksheet scanning (DESIGN §6.3): capture -> markers, QR and
/// blur -> layout -> crops -> the teacher confirms -> `scan_queue` -> upload,
/// then straight back to the camera for the next page. No student is picked
/// beforehand; the QR says whose page it is.
class ScanScreen extends ConsumerStatefulWidget {
  const ScanScreen({super.key});

  @override
  ConsumerState<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends ConsumerState<ScanScreen>
    with WidgetsBindingObserver {
  late final ScanProcessor _processor = ref.read(scanProcessorProvider);
  ScanCamera? _camera;
  bool _cameraReady = false;
  String? _cameraError;
  bool _torch = false;

  /// Non-null while a capture, analysis or save is running.
  String? _busy;
  ScanAnalysis? _result;
  int _saved = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    if (_processor.isSupported) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _openCamera();
      });
      unawaited(_finishWaitingScans());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _closeCamera();
    final pending = _result;
    if (pending != null) unawaited(_processor.discard(pending));
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!_processor.isSupported) return;
    // The camera must be released while the app is in the background. A
    // camera that is still initialising is left alone: its permission
    // dialog also makes the app inactive.
    if (_cameraReady &&
        (state == AppLifecycleState.inactive ||
            state == AppLifecycleState.paused)) {
      _closeCamera();
      if (mounted) setState(() => _cameraReady = false);
    } else if (state == AppLifecycleState.resumed && _camera == null) {
      _openCamera();
    }
  }

  Future<void> _openCamera() async {
    final camera = ref.read(scanCameraFactoryProvider)();
    _camera = camera;
    setState(() {
      _cameraError = null;
      _cameraReady = false;
    });
    try {
      await camera.initialize();
      if (!mounted || _camera != camera) {
        await camera.dispose();
        return;
      }
      setState(() {
        _cameraReady = true;
        _torch = false;
      });
    } catch (e) {
      final current = _camera == camera;
      if (current) _camera = null;
      await camera.dispose();
      // A camera closed while it was starting is not an error.
      if (!current || !mounted) return;
      setState(
        () => _cameraError = e is ScanCameraException
            ? e.message
            : 'เปิดกล้องไม่ได้ ลองใหม่อีกครั้ง',
      );
    }
  }

  void _closeCamera() {
    final camera = _camera;
    _camera = null;
    _cameraReady = false;
    if (camera != null) unawaited(camera.dispose());
  }

  /// Scans that waited for a layout are cropped as soon as the screen opens
  /// (best effort; the upload queue screen has a button for the same).
  Future<void> _finishWaitingScans() async {
    try {
      final run = await _processor.processNeedsLayout();
      if (run.processed > 0 && mounted) {
        showMessage(
          context,
          'ตัดภาพสแกนที่รอ layout แล้ว ${run.processed} หน้า',
        );
      }
    } catch (e) {
      debugPrint('needs_layout run: $e');
    }
  }

  Future<void> _capture() async {
    final camera = _camera;
    if (_busy != null || camera == null || !_cameraReady) return;
    setState(() => _busy = 'กำลังถ่ายภาพ…');
    try {
      final path = await camera.takePicture();
      if (!mounted) return;
      setState(() => _busy = 'กำลังตรวจใบงาน…');
      final result = await _processor.analyze(path);
      if (!mounted) {
        unawaited(_processor.discard(result));
        return;
      }
      setState(() => _result = result);
    } on ScanCameraException catch (e) {
      if (mounted) showMessage(context, e.message);
    } catch (e) {
      debugPrint('scan failed: $e');
      if (mounted) showMessage(context, 'ประมวลผลภาพไม่สำเร็จ ลองถ่ายใหม่');
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  Future<void> _retake() async {
    final result = _result;
    setState(() => _result = null);
    if (result != null) await _processor.discard(result);
  }

  Future<void> _run(String label, Future<void> Function() action) async {
    setState(() => _busy = label);
    try {
      await action();
    } catch (e) {
      debugPrint('scan action failed: $e');
      if (mounted) showMessage(context, 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  Future<void> _confirm(ScanReady ready) => _run('กำลังบันทึก…', () async {
    await _processor.confirm(ready);
    if (!mounted) return;
    setState(() {
      _saved++;
      _result = null;
    });
    showMessage(
      context,
      'บันทึก ${studentLabel(ready.student, ready.qr.studentId)} '
      'หน้า ${ready.qr.page} แล้ว สแกนแผ่นต่อไปได้เลย',
    );
  });

  Future<void> _keepForLater(ScanNeedsLayout scan) =>
      _run('กำลังบันทึก…', () async {
        await _processor.keepForLater(scan);
        if (!mounted) return;
        setState(() {
          _saved++;
          _result = null;
        });
        showMessage(
          context,
          'เก็บไว้ในคิวแล้ว จะตัดภาพและอัปโหลดเมื่อต่อเน็ตได้',
        );
      });

  Future<void> _acceptBlur(ScanRejected rejected) =>
      _run('กำลังตัดภาพ…', () async {
        final next = await _processor.acceptDespiteBlur(rejected);
        if (mounted) setState(() => _result = next);
      });

  Future<void> _toggleTorch() async {
    final camera = _camera;
    if (camera == null) return;
    try {
      await camera.setTorch(!_torch);
      if (mounted) setState(() => _torch = !_torch);
    } on ScanCameraException catch (e) {
      if (mounted) showMessage(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final open = ref.watch(uploadQueueOpenCountProvider);
    final queueButton = IconButton(
      tooltip: 'คิวอัปโหลด',
      onPressed: () => context.push(AppRoutes.uploadQueue),
      icon: Badge(
        isLabelVisible: open > 0,
        label: Text('$open'),
        child: const Icon(Icons.cloud_upload_outlined),
      ),
    );

    if (!_processor.isSupported) {
      return Scaffold(
        appBar: AppBar(title: const Text('สแกนใบงาน'), actions: [queueButton]),
        body: const EmptyView(
          icon: Icons.document_scanner_outlined,
          title: 'สแกนใบงานได้เฉพาะในแอป Android',
          message:
              'การหา marker, อ่าน QR และตัดภาพทำบนโทรศัพท์ Android '
              'เปิดหน้านี้ในแอปบนมือถือหรือแท็บเล็ตของครู',
        ),
      );
    }

    final result = _result;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          _saved > 0 ? 'สแกนใบงาน · บันทึกแล้ว $_saved หน้า' : 'สแกนใบงาน',
        ),
        actions: [
          if (result == null && _cameraReady)
            IconButton(
              tooltip: _torch ? 'ปิดไฟฉาย' : 'เปิดไฟฉาย',
              onPressed: _toggleTorch,
              icon: Icon(_torch ? Icons.flash_on : Icons.flash_off),
            ),
          queueButton,
        ],
      ),
      body: Stack(
        children: [
          Positioned.fill(
            child: switch (result) {
              null => _cameraView(),
              ScanReady() => _ReadyView(
                ready: result,
                onRetake: _retake,
                onConfirm: () => _confirm(result),
              ),
              ScanNeedsLayout() => _NeedsLayoutView(
                scan: result,
                onDiscard: _retake,
                onKeep: () => _keepForLater(result),
              ),
              ScanRejected() => _RejectedView(
                rejected: result,
                onRetake: _retake,
                onAccept: result.canOverride ? () => _acceptBlur(result) : null,
              ),
            },
          ),
          if (_busy case final label?)
            Positioned.fill(child: _BusyOverlay(label: label)),
        ],
      ),
    );
  }

  Widget _cameraView() {
    if (_cameraError case final error?) {
      return EmptyView(
        icon: Icons.no_photography_outlined,
        title: 'เปิดกล้องไม่ได้',
        message: error,
        action: FilledButton.tonal(
          onPressed: _openCamera,
          child: const Text('ลองใหม่'),
        ),
      );
    }
    final camera = _camera;
    if (camera == null || !_cameraReady) {
      return const Center(child: CircularProgressIndicator());
    }
    return ColoredBox(
      color: Colors.black,
      child: Column(
        children: [
          Expanded(
            child: Center(
              child: AspectRatio(
                aspectRatio: camera.previewAspectRatio,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    camera.buildPreview(),
                    const IgnorePointer(child: _FrameGuide()),
                  ],
                ),
              ),
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text(
                    'วางใบงานให้เห็นสัญลักษณ์สี่เหลี่ยมครบทั้ง 4 มุม แล้วกดถ่าย',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Colors.white),
                  ),
                  const SizedBox(height: 12),
                  SizedBox.square(
                    dimension: 72,
                    child: FilledButton(
                      key: const Key('scan-shutter'),
                      style: FilledButton.styleFrom(
                        shape: const CircleBorder(),
                        padding: EdgeInsets.zero,
                      ),
                      onPressed: _busy == null ? _capture : null,
                      child: const Icon(
                        Icons.camera_alt,
                        size: 32,
                        semanticLabel: 'ถ่ายภาพ',
                      ),
                    ),
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

/// "ด.ญ. สมหญิง (เลขที่ 12)", or the id when the roster is not cached.
String studentLabel(RosterStudent? student, int studentId) => student == null
    ? 'นักเรียนรหัส $studentId'
    : '${student.name} (เลขที่ ${student.studentNumber})';

/// Corner brackets with the aspect ratio of the marker frame, so the
/// teacher knows how much of the page must be in view.
class _FrameGuide extends StatelessWidget {
  const _FrameGuide();

  @override
  Widget build(BuildContext context) => CustomPaint(
    painter: _FrameGuidePainter(Theme.of(context).colorScheme.primary),
  );
}

class _FrameGuidePainter extends CustomPainter {
  _FrameGuidePainter(this.color);

  final Color color;

  /// Width / height of the A4 marker frame (178 x 265 mm).
  static const frameAspect = 178 / 265;

  @override
  void paint(Canvas canvas, Size size) {
    final maxW = size.width * 0.86;
    final maxH = size.height * 0.9;
    var w = maxW;
    var h = w / frameAspect;
    if (h > maxH) {
      h = maxH;
      w = h * frameAspect;
    }
    final rect = Rect.fromCenter(
      center: size.center(Offset.zero),
      width: w,
      height: h,
    );
    final arm = w * 0.12;
    final paint = Paint()
      ..color = color
      ..strokeWidth = 4
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;
    for (final (corner, dx, dy) in [
      (rect.topLeft, 1.0, 1.0),
      (rect.topRight, -1.0, 1.0),
      (rect.bottomRight, -1.0, -1.0),
      (rect.bottomLeft, 1.0, -1.0),
    ]) {
      canvas
        ..drawLine(corner, corner.translate(arm * dx, 0), paint)
        ..drawLine(corner, corner.translate(0, arm * dy), paint);
    }
  }

  @override
  bool shouldRepaint(_FrameGuidePainter old) => old.color != color;
}

class _BusyOverlay extends StatelessWidget {
  const _BusyOverlay({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) => ColoredBox(
    color: Colors.black54,
    child: Center(
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const CircularProgressIndicator(),
              const SizedBox(height: 16),
              Text(label),
            ],
          ),
        ),
      ),
    ),
  );
}

class _ReadyView extends StatelessWidget {
  const _ReadyView({
    required this.ready,
    required this.onRetake,
    required this.onConfirm,
  });

  final ScanReady ready;
  final VoidCallback onRetake;
  final VoidCallback onConfirm;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final student = ready.student;
    final byId = {for (final c in ready.crops.regions) c.regionId: c};
    final tiles = <Widget>[
      _CropTile(
        path: ready.crops.warpedPagePath,
        label: 'ทั้งหน้า',
        caption: 'หน้า ${ready.qr.page}/${ready.layout.pageCount}',
      ),
    ];
    var boxNumber = 0;
    for (final region in ready.layout.regions) {
      boxNumber++;
      final crop = byId[region.regionId];
      if (crop == null) continue;
      tiles.add(
        _CropTile(
          path: crop.imagePath,
          label: 'กรอบที่ $boxNumber',
          caption: _caption(region.kind, crop),
        ),
      );
      final finalCrop = region.finalCropId == null
          ? null
          : byId[region.finalCropId];
      if (finalCrop != null) {
        tiles.add(
          _CropTile(
            path: finalCrop.imagePath,
            label: 'กรอบที่ $boxNumber · คำตอบสุดท้าย',
            caption: _caption(RegionKind.box, finalCrop),
          ),
        );
      }
    }

    return Column(
      children: [
        Expanded(
          child: ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
            child: CustomScrollView(
              slivers: [
                SliverToBoxAdapter(
                  child: Card(
                    child: ListTile(
                      leading: CircleAvatar(
                        child: Text(
                          student == null ? '?' : '${student.studentNumber}',
                        ),
                      ),
                      title: Text(
                        student?.name ?? 'นักเรียนรหัส ${ready.qr.studentId}',
                        style: theme.textTheme.titleMedium,
                      ),
                      subtitle: Text(
                        [
                          'การบ้าน #${ready.qr.assignmentId}',
                          'หน้า ${ready.qr.page}/${ready.layout.pageCount}',
                          if (student == null) 'ไม่มีในรายชื่อที่เตรียมไว้',
                        ].join(' · '),
                      ),
                    ),
                  ),
                ),
                if (ready.alreadyQueued)
                  SliverToBoxAdapter(
                    child: Card(
                      color: theme.colorScheme.tertiaryContainer,
                      child: const ListTile(
                        leading: Icon(Icons.info_outline),
                        title: Text(
                          'หน้านี้สแกนไว้แล้วและยังรออยู่ในคิว '
                          'ถ้ายืนยัน ระบบจะใช้ภาพล่าสุดแทน',
                        ),
                      ),
                    ),
                  ),
                const SliverToBoxAdapter(
                  child: Padding(
                    padding: EdgeInsets.symmetric(vertical: 8),
                    child: Text('ตรวจว่าภาพแต่ละกรอบครบและอ่านออก'),
                  ),
                ),
                SliverGrid.extent(
                  maxCrossAxisExtent: 240,
                  mainAxisSpacing: 8,
                  crossAxisSpacing: 8,
                  childAspectRatio: 1.1,
                  children: tiles,
                ),
              ],
            ),
          ),
        ),
        _ActionBar(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: onRetake,
                icon: const Icon(Icons.replay),
                label: const Text('ถ่ายใหม่'),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              flex: 2,
              child: FilledButton.icon(
                key: const Key('scan-confirm'),
                onPressed: onConfirm,
                icon: const Icon(Icons.check),
                label: const Text('ยืนยัน แล้วสแกนต่อ'),
              ),
            ),
          ],
        ),
      ],
    );
  }

  static String _caption(RegionKind kind, RegionCrop crop) {
    if (kind == RegionKind.mcq) {
      final filled = filledOptions(crop.bubbleFill);
      return switch (filled.length) {
        0 => 'ไม่ได้ฝน',
        1 => 'ฝน ${filled.first}',
        _ => 'ฝนหลายตัวเลือก: ${filled.join(', ')}',
      };
    }
    return crop.inkRatio <= emptyInkRatio ? 'ว่าง' : 'มีคำตอบ';
  }
}

class _CropTile extends StatelessWidget {
  const _CropTile({
    required this.path,
    required this.label,
    required this.caption,
  });

  final String path;
  final String label;
  final String caption;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: ColoredBox(
              color: theme.colorScheme.surfaceContainerHighest,
              child: Image.file(
                File(path),
                fit: BoxFit.contain,
                cacheWidth: 480,
                errorBuilder: (_, _, _) =>
                    const Center(child: Icon(Icons.broken_image_outlined)),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 6, 8, 8),
            child: Text(
              '$label\n$caption',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
          ),
        ],
      ),
    );
  }
}

class _RejectedView extends StatelessWidget {
  const _RejectedView({
    required this.rejected,
    required this.onRetake,
    required this.onAccept,
  });

  final ScanRejected rejected;
  final VoidCallback onRetake;
  final VoidCallback? onAccept;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return _MessageLayout(
      icon: Icons.replay_circle_filled_outlined,
      iconColor: theme.colorScheme.error,
      title: 'ถ่ายใหม่อีกครั้ง',
      body: [
        for (final issue in rejected.issues)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Padding(
                  padding: EdgeInsets.only(top: 2, right: 8),
                  child: Icon(Icons.error_outline, size: 18),
                ),
                Expanded(child: Text(issue.message)),
              ],
            ),
          ),
      ],
      actions: [
        if (onAccept != null)
          Expanded(
            child: OutlinedButton(
              onPressed: onAccept,
              child: const Text('ใช้ภาพนี้ต่อ'),
            ),
          ),
        if (onAccept != null) const SizedBox(width: 12),
        Expanded(
          child: FilledButton.icon(
            key: const Key('scan-retake'),
            onPressed: onRetake,
            icon: const Icon(Icons.camera_alt),
            label: const Text('ถ่ายใหม่'),
          ),
        ),
      ],
    );
  }
}

class _NeedsLayoutView extends StatelessWidget {
  const _NeedsLayoutView({
    required this.scan,
    required this.onDiscard,
    required this.onKeep,
  });

  final ScanNeedsLayout scan;
  final VoidCallback onDiscard;
  final VoidCallback onKeep;

  @override
  Widget build(BuildContext context) {
    final student = scan.student;
    return _MessageLayout(
      icon: Icons.cloud_off_outlined,
      title: 'ยังไม่มี layout ของใบงานนี้ในเครื่อง',
      body: [
        Text(
          '${student?.name ?? 'นักเรียนรหัส ${scan.qr.studentId}'} · '
          'การบ้าน #${scan.qr.assignmentId} · หน้า ${scan.qr.page}',
          style: Theme.of(context).textTheme.titleSmall,
        ),
        const SizedBox(height: 8),
        const Text(
          'เก็บภาพไว้ในคิวก่อนได้ ระบบจะตัดภาพและอัปโหลดให้เมื่อต่อเน็ตได้ '
          'ครั้งหน้ากด "เตรียมสแกนออฟไลน์" ในหน้าห้องเรียนก่อนสแกน',
        ),
        const SizedBox(height: 8),
        Text(scan.reason, style: Theme.of(context).textTheme.bodySmall),
      ],
      actions: [
        Expanded(
          child: OutlinedButton(
            onPressed: onDiscard,
            child: const Text('ทิ้ง'),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          flex: 2,
          child: FilledButton(
            key: const Key('scan-keep'),
            onPressed: onKeep,
            child: const Text('เก็บไว้ในคิว'),
          ),
        ),
      ],
    );
  }
}

class _MessageLayout extends StatelessWidget {
  const _MessageLayout({
    required this.icon,
    required this.title,
    required this.body,
    required this.actions,
    this.iconColor,
  });

  final IconData icon;
  final Color? iconColor;
  final String title;
  final List<Widget> body;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      children: [
        Expanded(
          child: ContentColumn(
            padding: const EdgeInsets.all(24),
            child: ListView(
              children: [
                Icon(
                  icon,
                  size: 56,
                  color: iconColor ?? theme.colorScheme.primary,
                ),
                const SizedBox(height: 12),
                Text(
                  title,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.titleLarge,
                ),
                const SizedBox(height: 16),
                ...body,
              ],
            ),
          ),
        ),
        _ActionBar(children: actions),
      ],
    );
  }
}

class _ActionBar extends StatelessWidget {
  const _ActionBar({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) => SafeArea(
    top: false,
    child: ContentColumn(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      child: Row(children: children),
    ),
  );
}
