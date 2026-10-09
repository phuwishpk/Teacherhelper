import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/db/app_database.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/content_column.dart';
import '../../platform/answer_sheet_pipeline.dart';
import '../scan/scan_camera.dart';
import '../upload_queue/queued_scan.dart';
import '../upload_queue/upload_queue_providers.dart';
import 'exam_models.dart';
import 'exam_scan_camera.dart';
import 'exam_scan_models.dart';
import 'exam_scan_repository.dart';
import 'exam_sheet_scanner.dart';

/// Signals after a read (DESIGN §22.10): the vibration pattern and the
/// card colour carry the result, the click sound only adds to it (Flutter
/// has no other system sound on Android). Tests override it.
class ScanFeedback {
  const ScanFeedback();

  Future<void> success() async {
    await HapticFeedback.mediumImpact();
    await SystemSound.play(SystemSoundType.click);
  }

  Future<void> failure() async {
    await HapticFeedback.heavyImpact();
    await Future<void>.delayed(const Duration(milliseconds: 150));
    await HapticFeedback.heavyImpact();
  }
}

final scanFeedbackProvider = Provider<ScanFeedback>(
  (ref) => const ScanFeedback(),
);

/// Loads the scan kit of an exam: from the server when online (and caches
/// it), else the copy prepared earlier (DESIGN §22.9 step 1).
class ExamKitLoad {
  const ExamKitLoad({
    this.kit,
    this.fetchedAt,
    this.offline = false,
    this.error,
  });

  final ExamScanKit? kit;
  final DateTime? fetchedAt;

  /// The server could not be reached; [kit] is the cached copy.
  final bool offline;
  final String? error;
}

Future<ExamKitLoad> loadExamKit(
  ExamScanRepository repository,
  ExamKitCache cache,
  int examId,
) async {
  final cached = await cache.load(examId);
  try {
    final kit = await repository.scanKit(examId);
    await cache.save(kit);
    return ExamKitLoad(kit: kit, fetchedAt: DateTime.now());
  } on DioException catch (e) {
    final status = e.response?.statusCode;
    if (status != null &&
        status >= 400 &&
        status < 500 &&
        status != 401 &&
        status != 408 &&
        status != 429) {
      // Not approved any more, graded by hand, or gone: the old key must
      // not be used.
      await cache.remove(examId);
      return ExamKitLoad(error: apiErrorMessage(e));
    }
    if (cached == null) {
      return const ExamKitLoad(
        error:
            'ยังไม่ได้เตรียมสแกน ต้องต่ออินเทอร์เน็ตครั้งแรกเพื่อโหลดเฉลยและรายชื่อ '
            'แล้วจึงสแกนแบบออฟไลน์ได้',
      );
    }
    return ExamKitLoad(
      kit: cached.kit,
      fetchedAt: cached.fetchedAt,
      offline: true,
    );
  }
}

/// "สแกนกระดาษคำตอบ" (DESIGN §22.9, §22.10): reads each answer sheet on the
/// phone, shows the score at once and queues the page for
/// `POST /exam-sheets`. "สแกนต่อเนื่อง" takes the photo by itself when two
/// frames in a row show the whole sheet sharply; the bar on top counts the
/// students with every page in. Android only.
class ExamScanScreen extends ConsumerStatefulWidget {
  const ExamScanScreen({
    super.key,
    required this.examId,
    this.clock = DateTime.now,
    this.frameInterval = const Duration(milliseconds: 300),
  });

  final int examId;

  /// Time of the duplicate rule (tests use a fake clock).
  final DateTime Function() clock;

  /// About how often a camera frame is checked (§22.10: every ~300 ms).
  final Duration frameInterval;

  @override
  ConsumerState<ExamScanScreen> createState() => _ExamScanScreenState();
}

class _ExamScanScreenState extends ConsumerState<ExamScanScreen>
    with WidgetsBindingObserver {
  late final ExamSheetScanner _scanner = ref.read(examSheetScannerProvider);
  late final bool _supported = !kIsWeb && _scanner.isSupported;

  ExamKitLoad? _load;
  ExamScanSession? _session;
  late FrameGate _gate = FrameGate(examId: widget.examId);

  FrameScanCamera? _camera;
  bool _cameraReady = false;
  String? _cameraError;
  bool _torch = false;
  bool _continuous = true;
  bool _streaming = false;

  bool _busy = false;
  bool _frameBusy = false;
  DateTime _lastFrame = DateTime.fromMillisecondsSinceEpoch(0);

  /// Sheets whose read failed, skipped for a moment in continuous mode.
  final _cooldown = <String, DateTime>{};

  ExamSheetOutcome? _card;

  /// A sheet scanned earlier in this session is in view again.
  ExamQr? _replace;
  int _doneCount = -1;

  /// Shared sheets (§22.19) all carry the same QR, so continuous mode
  /// takes the next photo only after the sheet it just read left the frame.
  bool _awaitClear = false;

  @override
  void initState() {
    super.initState();
    if (!_supported) return;
    WidgetsBinding.instance.addObserver(this);
    unawaited(_loadKit());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _closeCamera();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (_cameraReady &&
        (state == AppLifecycleState.inactive ||
            state == AppLifecycleState.paused)) {
      _closeCamera();
      if (mounted) setState(() => _cameraReady = false);
    } else if (state == AppLifecycleState.resumed &&
        _camera == null &&
        _session != null) {
      unawaited(_openCamera());
    }
  }

  Future<void> _loadKit({bool reset = false}) async {
    if (reset) setState(() => _load = null);
    final load = await loadExamKit(
      ref.read(examScanRepositoryProvider),
      ref.read(examKitCacheProvider),
      widget.examId,
    );
    if (!mounted) return;
    final kit = load.kit;
    setState(() {
      _load = load;
      _session = kit != null && kit.printed ? ExamScanSession(kit) : null;
      _gate = FrameGate(examId: widget.examId);
      _awaitClear = false;
    });
    if (_session == null) return;
    _applyQueue(ref.read(uploadQueueProvider).value);
    unawaited(_refreshStatus());
    if (_camera == null) unawaited(_openCamera());
  }

  Future<void> _refreshStatus() async {
    final session = _session;
    if (session == null) return;
    try {
      final status = await ref
          .read(examScanRepositoryProvider)
          .sheetStatus(widget.examId);
      if (!mounted) return;
      setState(() => session.server = status);
    } catch (e) {
      logStatusError(e);
    }
  }

  void _applyQueue(List<QueuedScan>? scans) {
    final session = _session;
    if (session == null || scans == null) return;
    session.setQueue(scans);
    // A page of this exam reached the server: ask for its score.
    final done = scans
        .where(
          (s) =>
              s.state == ScanState.done &&
              ExamQr.tryParse(s.qrPayload)?.assignmentId == widget.examId,
        )
        .length;
    if (_doneCount >= 0 && done != _doneCount) unawaited(_refreshStatus());
    _doneCount = done;
  }

  Future<void> _openCamera() async {
    final camera = ref.read(frameScanCameraFactoryProvider)();
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
      await _syncStream();
    } catch (e) {
      final current = _camera == camera;
      if (current) _camera = null;
      await camera.dispose();
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
    _streaming = false;
    if (camera != null) {
      unawaited(camera.stopFrames().then((_) => camera.dispose()));
    }
  }

  /// Streams frames exactly while continuous mode is on and nothing runs.
  Future<void> _syncStream() async {
    final camera = _camera;
    if (camera == null || !_cameraReady) return;
    final want = _continuous && !_busy && _session != null;
    if (want == _streaming) return;
    _streaming = want;
    if (want) {
      _gate.reset();
      await camera.startFrames(_onFrame);
    } else {
      await camera.stopFrames();
    }
  }

  void _onFrame(CameraFrame frame) {
    if (_busy || _frameBusy || !_continuous || _session == null) return;
    final now = widget.clock();
    if (now.difference(_lastFrame) < widget.frameInterval) return;
    _lastFrame = now;
    _frameBusy = true;
    _scanner.pipeline
        .detectFrame(
          frame.yPlane,
          frame.width,
          frame.height,
          frame.bytesPerRow,
          frame.rotation,
        )
        .then((d) => _onDetection(d, widget.clock()))
        .catchError((Object e) => debugPrint('detectFrame: $e'))
        .whenComplete(() => _frameBusy = false);
  }

  Future<void> _onDetection(FrameDetection detection, DateTime now) async {
    final session = _session;
    if (session == null || _busy || !mounted) return;
    if (session.kit.codeSheets) {
      // The QR says nothing about the student: no duplicate rule by QR.
      if (_awaitClear) {
        if (detection.markersFound < 4) {
          _gate.reset();
          setState(() => _awaitClear = false);
        }
        return;
      }
      final shared = _gate.offer(detection);
      if (ExamQr.tryParse(shared) != null) await _capture();
      return;
    }
    final payload = _gate.offer(detection);
    final qr = ExamQr.tryParse(payload);
    if (qr == null) return;
    final cooling = _cooldown[payload!];
    if (cooling != null &&
        now.difference(cooling) < ExamScanSession.silentWindow) {
      return;
    }
    switch (session.check(qr, now)) {
      case DuplicateVerdict.silent:
        return;
      case DuplicateVerdict.seen:
        if (_replace?.studentId == qr.studentId && _replace?.page == qr.page) {
          return;
        }
        setState(() {
          _replace = qr;
          _card = null;
        });
      case DuplicateVerdict.fresh:
        await _capture(expectedQr: payload);
    }
  }

  Future<void> _capture({
    String? photo,
    bool acceptBlur = false,
    String? expectedQr,
  }) async {
    final session = _session;
    final camera = _camera;
    if (session == null || _busy) return;
    setState(() {
      _busy = true;
      _replace = null;
    });
    await _syncStream();
    try {
      final path = photo ?? await camera!.takePicture();
      final outcome = await _scanner.scan(
        path,
        session.kit,
        pageOneVersion: session.pageOneVersion,
        alreadyScanned: (studentId, page) =>
            session.pagesOf(studentId).contains(page),
        acceptBlur: acceptBlur,
      );
      final feedback = ref.read(scanFeedbackProvider);
      ExamSheetOutcome shown = outcome;
      switch (outcome) {
        case ExamSheetQueued():
          session.record(outcome);
          unawaited(feedback.success());
        case ExamSheetRejected():
          if (expectedQr != null) _cooldown[expectedQr] = widget.clock();
          unawaited(feedback.failure());
        case ExamSheetNeedsStudent():
          // The ID on a shared sheet did not settle the student (§22.19).
          unawaited(feedback.failure());
          final picked = mounted ? await _pickStudent(outcome, session) : null;
          if (picked == null) {
            await _scanner.discard(outcome.pending);
            shown = const ExamSheetRejected(
              'ยังไม่ได้เลือกนักเรียน จึงไม่ได้เก็บภาพนี้ สแกนใบนี้ใหม่ได้',
            );
          } else {
            final queued = await _scanner.assign(
              outcome.pending,
              session.kit,
              picked,
              pageOneVersion: session.pageOneVersion,
            );
            session.record(queued);
            unawaited(feedback.success());
            shown = queued;
          }
      }
      if (mounted) setState(() => _card = shown);
    } catch (e) {
      if (mounted) {
        setState(
          () => _card = ExamSheetRejected(
            e is Exception ? 'ถ่ายภาพไม่สำเร็จ ลองใหม่อีกครั้ง' : '$e',
          ),
        );
      }
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _awaitClear = session.kit.codeSheets;
        });
        await _syncStream();
      }
    }
  }

  /// The roster to choose the student of a shared sheet from; null when
  /// the teacher closes it (the photo is dropped).
  Future<ExamKitStudent?> _pickStudent(
    ExamSheetNeedsStudent need,
    ExamScanSession session,
  ) {
    final roster = [...session.kit.roster]
      ..sort((a, b) => a.studentNumber.compareTo(b.studentNumber));
    final page = need.pending.qr.page;
    return showModalBottomSheet<ExamKitStudent>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => SafeArea(
        child: FractionallySizedBox(
          heightFactor: 0.75,
          child: Column(
            key: const ValueKey('exam_scan_pick_student'),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: Text(
                  need.message,
                  style: Theme.of(context).textTheme.titleSmall,
                ),
              ),
              Expanded(
                child: ListView(
                  children: [
                    for (final s in roster)
                      ListTile(
                        key: ValueKey('pick_student_${s.studentId}'),
                        selected: need.suggested?.studentId == s.studentId,
                        leading: CircleAvatar(
                          child: Text('${s.studentNumber}'),
                        ),
                        title: Text(s.name),
                        subtitle: Text(
                          [
                            s.studentCode ?? 'ไม่มีเลขประจำตัว',
                            if (session.pagesOf(s.studentId).contains(page))
                              'สแกนหน้า $page แล้ว (เลือกเพื่อสแกนแทน)',
                          ].join(' · '),
                        ),
                        onTap: () => Navigator.of(context).pop(s),
                      ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
                child: OutlinedButton(
                  key: const ValueKey('exam_scan_pick_discard'),
                  onPressed: () => Navigator.of(context).pop(),
                  child: const Text('ไม่ใช่กระดาษของห้องนี้ ทิ้งภาพ'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _setContinuous(bool on) async {
    setState(() {
      _continuous = on;
      _replace = null;
      _awaitClear = false;
    });
    await _syncStream();
  }

  Future<void> _toggleTorch() async {
    final camera = _camera;
    if (camera == null) return;
    final on = !_torch;
    try {
      await camera.setTorch(on);
      if (mounted) setState(() => _torch = on);
    } catch (_) {
      // Some phones have no torch.
    }
  }

  void _showMissing(ExamScanSummary summary) {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: summary.missing.isEmpty
            ? const Padding(
                padding: EdgeInsets.all(24),
                child: Text('สแกนครบทุกคนแล้ว'),
              )
            : ListView(
                shrinkWrap: true,
                children: [
                  for (final m in summary.missing)
                    ListTile(
                      key: ValueKey('missing_${m.student.studentId}'),
                      leading: CircleAvatar(
                        child: Text('${m.student.studentNumber}'),
                      ),
                      title: Text(m.student.name),
                      subtitle: Text(
                        (_session?.kit.pageCount ?? 1) > 1
                            ? 'ยังขาดหน้า ${m.missingPages.join(', ')}'
                            : 'ยังไม่ได้สแกน',
                      ),
                    ),
                ],
              ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(uploadQueueProvider, (_, next) => _applyQueue(next.value));
    final title = const Text('สแกนกระดาษคำตอบ');
    if (!_supported) {
      return Scaffold(
        appBar: AppBar(title: title),
        body: const _Message(
          icon: Icons.phone_android,
          text:
              'การสแกนกระดาษคำตอบต้องใช้แอป Krucheck บนโทรศัพท์ Android '
              '(บนเว็บไม่มีตัวอ่านกระดาษคำตอบ)',
        ),
      );
    }
    final load = _load;
    if (load == null) {
      return Scaffold(
        appBar: AppBar(title: title),
        body: const Center(child: CircularProgressIndicator()),
      );
    }
    final kit = load.kit;
    if (kit == null || !kit.printed) {
      return Scaffold(
        appBar: AppBar(title: title),
        body: _Message(
          icon: Icons.info_outline,
          text:
              load.error ??
              'ยังไม่ได้พิมพ์กระดาษคำตอบ พิมพ์กระดาษคำตอบก่อนแล้วจึงสแกน',
          action: FilledButton(
            key: const ValueKey('exam_scan_retry'),
            onPressed: () => _loadKit(reset: true),
            child: const Text('ลองใหม่'),
          ),
        ),
      );
    }
    final session = _session!;
    final summary = session.summary();
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: title,
        actions: [
          IconButton(
            key: const ValueKey('exam_scan_torch'),
            tooltip: _torch ? 'ปิดไฟฉาย' : 'เปิดไฟฉาย',
            onPressed: _cameraReady ? _toggleTorch : null,
            icon: Icon(_torch ? Icons.flash_on : Icons.flash_off),
          ),
          IconButton(
            key: const ValueKey('exam_scan_reload'),
            tooltip: 'เตรียมสแกนใหม่',
            onPressed: _busy ? null : () => _loadKit(reset: true),
            icon: const Icon(Icons.sync),
          ),
        ],
      ),
      body: ContentColumn(
        padding: EdgeInsets.zero,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Material(
              color: theme.colorScheme.secondaryContainer,
              child: InkWell(
                key: const ValueKey('exam_scan_summary'),
                onTap: () => _showMissing(summary),
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 10,
                  ),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          summary.text,
                          style: theme.textTheme.bodyMedium,
                        ),
                      ),
                      const Icon(Icons.expand_more),
                    ],
                  ),
                ),
              ),
            ),
            if (kit.codeSheets && kit.studentsWithoutUsableCode > 0)
              Padding(
                key: const ValueKey('exam_scan_no_code'),
                padding: const EdgeInsets.fromLTRB(16, 6, 16, 0),
                child: Text(
                  'นักเรียน ${kit.studentsWithoutUsableCode} คนไม่มีเลขประจำตัว'
                  'ที่ฝนได้ ต้องเลือกชื่อเองตอนสแกน',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            if (load.offline)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 6, 16, 0),
                child: Text(
                  'ออฟไลน์: ใช้เฉลยและรายชื่อที่เตรียมไว้'
                  '${load.fetchedAt == null ? '' : 'เมื่อ ${formatThaiDateTime(load.fetchedAt!)}'} '
                  'ใบที่สแกนจะส่งเมื่อออนไลน์',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            SwitchListTile(
              key: const ValueKey('exam_scan_continuous'),
              title: const Text('สแกนต่อเนื่อง'),
              subtitle: const Text(
                'ถ่ายอัตโนมัติเมื่อเห็นกระดาษทั้งแผ่นและภาพคมชัด',
              ),
              value: _continuous,
              onChanged: _busy ? null : _setContinuous,
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: _preview(theme),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: _cardView(theme, session),
            ),
            SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: _continuous
                    ? Text(
                        _busy
                            ? 'กำลังอ่านกระดาษคำตอบ…'
                            : _awaitClear
                            ? 'ยกกระดาษใบนี้ออก แล้ววางใบถัดไป'
                            : 'วางกระดาษให้เห็นสัญลักษณ์ครบ 4 มุม แล้วถือให้นิ่ง',
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodySmall,
                      )
                    : FilledButton.icon(
                        key: const ValueKey('exam_scan_shutter'),
                        onPressed: _cameraReady && !_busy
                            ? () => _capture()
                            : null,
                        icon: const Icon(Icons.camera_alt),
                        label: Text(_busy ? 'กำลังอ่าน…' : 'ถ่ายกระดาษคำตอบ'),
                      ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _preview(ThemeData theme) {
    final camera = _camera;
    if (_cameraError != null) {
      return _Message(
        icon: Icons.no_photography_outlined,
        text: _cameraError!,
        action: OutlinedButton(
          onPressed: _openCamera,
          child: const Text('เปิดกล้องอีกครั้ง'),
        ),
      );
    }
    if (camera == null || !_cameraReady) {
      return const Center(child: CircularProgressIndicator());
    }
    return Center(
      child: AspectRatio(
        aspectRatio: camera.previewAspectRatio,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: Stack(
            fit: StackFit.expand,
            children: [
              camera.buildPreview(),
              if (_busy)
                const ColoredBox(
                  color: Color(0x55000000),
                  child: Center(child: CircularProgressIndicator()),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _cardView(ThemeData theme, ExamScanSession session) {
    final scheme = theme.colorScheme;
    final replace = _replace;
    if (replace != null) {
      final student = session.kit.student(replace.studentId);
      return Card(
        key: const ValueKey('exam_scan_seen'),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('สแกนใบนี้แล้ว', style: theme.textTheme.titleSmall),
              Text(
                'เลขที่ ${student?.studentNumber ?? '-'} ${student?.name ?? ''} '
                'หน้า ${replace.page}',
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                children: [
                  FilledButton.tonal(
                    key: const ValueKey('exam_scan_replace'),
                    onPressed: _busy ? null : () => _capture(),
                    child: const Text('สแกนแทนใบเดิม'),
                  ),
                  TextButton(
                    onPressed: () => setState(() => _replace = null),
                    child: const Text('ข้าม'),
                  ),
                ],
              ),
            ],
          ),
        ),
      );
    }
    final card = _card;
    switch (card) {
      case null:
      case ExamSheetNeedsStudent():
        // Settled in _capture before a card is shown.
        return const SizedBox.shrink();
      case ExamSheetRejected(:final message, :final keptPhoto):
        return Card(
          key: const ValueKey('exam_scan_rejected'),
          color: scheme.errorContainer,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.error_outline, color: scheme.onErrorContainer),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        message,
                        style: TextStyle(color: scheme.onErrorContainer),
                      ),
                    ),
                  ],
                ),
                if (keptPhoto != null)
                  Wrap(
                    spacing: 8,
                    children: [
                      TextButton(
                        key: const ValueKey('exam_scan_accept_blur'),
                        onPressed: _busy
                            ? null
                            : () =>
                                  _capture(photo: keptPhoto, acceptBlur: true),
                        child: const Text('ใช้ภาพนี้'),
                      ),
                    ],
                  ),
              ],
            ),
          ),
        );
      case ExamSheetQueued():
        final server = session.server?.of(card.qr.studentId);
        final student = card.student;
        final score = card.score;
        return Card(
          key: const ValueKey('exam_scan_result'),
          color: card.reviewCount > 0 || card.waiting != null
              ? scheme.tertiaryContainer
              : scheme.primaryContainer,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'เลขที่ ${student?.studentNumber ?? '-'} '
                        '${student?.name ?? ''}',
                        style: theme.textTheme.titleSmall,
                      ),
                      Text(
                        [
                          'หน้า ${card.qr.page}/${card.pageCount}',
                          if (card.versionLabel != null &&
                              session.kit.versionCount > 1)
                            'ชุด ${card.versionLabel}',
                          if (card.version.doubtful) 'วงชุดไม่ชัด',
                          if (card.identifiedBy ==
                              ExamSheetQueued.identifiedByTeacher)
                            'ครูเลือกชื่อ',
                        ].join(' · '),
                      ),
                      if (card.reviewCount > 0)
                        Text(
                          'ต้องตรวจ ${card.reviewCount} ข้อ',
                          style: TextStyle(color: scheme.onTertiaryContainer),
                        ),
                      if (server?.score != null &&
                          server!.pagesReceived.length >= card.pageCount)
                        Text(
                          'คะแนนจากเซิร์ฟเวอร์ ${formatPoints(server.score!)}'
                          '/${formatPoints(session.server!.maxScore)}',
                          style: theme.textTheme.bodySmall,
                        ),
                    ],
                  ),
                ),
                Text(
                  score == null
                      ? card.waiting!
                      : '${formatPoints(score.score)}/${formatPoints(score.maxScore)}',
                  key: const ValueKey('exam_scan_score'),
                  style: score == null
                      ? theme.textTheme.titleSmall
                      : theme.textTheme.headlineSmall,
                ),
              ],
            ),
          ),
        );
    }
  }
}

class _Message extends StatelessWidget {
  const _Message({required this.icon, required this.text, this.action});

  final IconData icon;
  final String text;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 48, color: Theme.of(context).colorScheme.outline),
          const SizedBox(height: 12),
          Text(text, textAlign: TextAlign.center),
          if (action != null) ...[const SizedBox(height: 12), action!],
        ],
      ),
    ),
  );
}
