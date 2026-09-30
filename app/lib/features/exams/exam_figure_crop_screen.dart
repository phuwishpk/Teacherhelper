import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'exam_import_models.dart';
import 'exam_models.dart';
import 'exam_providers.dart';
import 'exams_repository.dart';

/// Opens [ExamFigureCropScreen]; true when the figure was cropped again.
Future<bool> openFigureCrop(
  BuildContext context, {
  required int examId,
  required ExamImageKey target,
  required String title,
  FigureSource? source,
}) async =>
    await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => ExamFigureCropScreen(
          examId: examId,
          target: target,
          title: title,
          source: source,
        ),
      ),
    ) ??
    false;

/// "ลากกรอบใหม่" (DESIGN §22.4): the teacher draws the figure's box on a
/// page image of the exam file; the server crops it again with GD
/// (`PUT /questions/{id}/figure` or `/question-options/{id}/figure`).
/// Drag on the page to draw a new box, inside the box to move it, or from
/// a corner to resize it.
class ExamFigureCropScreen extends ConsumerStatefulWidget {
  const ExamFigureCropScreen({
    super.key,
    required this.examId,
    required this.target,
    required this.title,
    this.source,
  });

  final int examId;
  final ExamImageKey target;

  /// "ภาพโจทย์ข้อ 3" or "ภาพตัวเลือก ข ข้อ 3".
  final String title;

  /// Where the figure came from; its page and box are the starting point.
  final FigureSource? source;

  @override
  ConsumerState<ExamFigureCropScreen> createState() =>
      _ExamFigureCropScreenState();
}

enum _Drag { draw, move, resize }

class _ExamFigureCropScreenState extends ConsumerState<ExamFigureCropScreen> {
  /// Touch distance (logical px) that grabs a corner.
  static const _handle = 28.0;

  int? _pageId;
  FigureBox? _box;
  bool _touched = false;
  bool _saving = false;
  String? _error;

  _Drag? _drag;

  /// The fixed corner while drawing or resizing, in 0–1000 units.
  Offset? _anchor;

  void _init(List<ExamPageImage> pages) {
    if (_pageId != null || pages.isEmpty) return;
    final s = widget.source;
    final match =
        pages.where((p) => p.id == s?.pageImageId).firstOrNull ??
        pages
            .where(
              (p) =>
                  p.sourceDocumentId == s?.sourceDocumentId &&
                  p.pageNo == s?.pageNo,
            )
            .firstOrNull;
    _pageId = (match ?? pages.first).id;
    _box = match != null ? FigureBox.fromList(s?.box) : null;
  }

  Offset _units(Offset local, Size size) => Offset(
    (local.dx / size.width * 1000).clamp(0, 1000).toDouble(),
    (local.dy / size.height * 1000).clamp(0, 1000).toDouble(),
  );

  void _start(Offset local, Size size) {
    final p = _units(local, size);
    final box = _box;
    _touched = true;
    if (box != null) {
      final corners = [
        (Offset(box.xmin, box.ymin), Offset(box.xmax, box.ymax)),
        (Offset(box.xmax, box.ymin), Offset(box.xmin, box.ymax)),
        (Offset(box.xmin, box.ymax), Offset(box.xmax, box.ymin)),
        (Offset(box.xmax, box.ymax), Offset(box.xmin, box.ymin)),
      ];
      for (final (corner, opposite) in corners) {
        final dx = (corner.dx - p.dx) / 1000 * size.width;
        final dy = (corner.dy - p.dy) / 1000 * size.height;
        if (dx * dx + dy * dy <= _handle * _handle) {
          _drag = _Drag.resize;
          _anchor = opposite;
          return;
        }
      }
      if (box.contains(p.dx, p.dy)) {
        _drag = _Drag.move;
        return;
      }
    }
    _drag = _Drag.draw;
    _anchor = p;
    setState(() => _box = FigureBox.span(p.dx, p.dy, p.dx, p.dy));
  }

  void _update(DragUpdateDetails d, Size size) {
    final box = _box;
    switch (_drag) {
      case _Drag.move when box != null:
        setState(
          () => _box = box.moved(
            d.delta.dx / size.width * 1000,
            d.delta.dy / size.height * 1000,
          ),
        );
      case _Drag.draw || _Drag.resize:
        final a = _anchor!;
        final p = _units(d.localPosition, size);
        setState(() => _box = FigureBox.span(a.dx, a.dy, p.dx, p.dy));
      default:
        break;
    }
  }

  Future<void> _save() async {
    final page = _pageId;
    final box = _box;
    if (page == null || box == null || !box.valid) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await ref
          .read(examDetailProvider(widget.examId).notifier)
          .setFigure(widget.target, pageImageId: page, box: box.toList());
      if (!mounted) return;
      showMessage(context, 'ตัดภาพประกอบใหม่แล้ว');
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(examDetailProvider(widget.examId));
    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(examDetailProvider(widget.examId)),
        data: (d) {
          final pages = d.availablePages;
          _init(pages);
          if (pages.isEmpty) {
            return const EmptyView(
              icon: Icons.image_not_supported_outlined,
              title: 'ยังไม่มีภาพหน้าเอกสาร',
              message:
                  'ภาพหน้าเอกสารมาจากไฟล์ข้อสอบที่ให้ AI อ่าน '
                  'และถูกลบพร้อมไฟล์หลัง 30 วัน แนบรูปภาพประกอบเองแทน',
            );
          }
          final page = pages.firstWhere(
            (p) => p.id == _pageId,
            orElse: () => pages.first,
          );
          return _body(context, pages, page);
        },
      ),
    );
  }

  Widget _body(
    BuildContext context,
    List<ExamPageImage> pages,
    ExamPageImage page,
  ) {
    final theme = Theme.of(context);
    final box = _box;
    final docs = <int>[
      for (final p in pages)
        if (!pages
            .takeWhile((q) => q != p)
            .any((q) => q.sourceDocumentId == p.sourceDocumentId))
          p.sourceDocumentId,
    ];
    String pageLabel(ExamPageImage p) => docs.length > 1
        ? 'ไฟล์ ${docs.indexOf(p.sourceDocumentId) + 1} หน้า ${p.pageNo}'
        : 'หน้า ${p.pageNo}';
    final image = ref.watch(examPageImageProvider(page.id));
    // A Column, not a list: a vertical drag on the page draws the box
    // instead of scrolling.
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'ลากบนภาพเพื่อวาดกรอบใหม่ ลากในกรอบเพื่อย้าย ลากที่มุมเพื่อปรับขนาด '
              'server จะเผื่อขอบให้เล็กน้อย',
              style: theme.textTheme.bodySmall,
            ),
            if (pages.length > 1) ...[
              const SizedBox(height: 8),
              DropdownButtonFormField<int>(
                key: const ValueKey('figure_crop_page'),
                initialValue: page.id,
                decoration: const InputDecoration(labelText: 'หน้าเอกสาร'),
                items: [
                  for (final p in pages)
                    DropdownMenuItem(value: p.id, child: Text(pageLabel(p))),
                ],
                onChanged: _saving
                    ? null
                    : (id) => setState(() {
                        _pageId = id;
                        _box = null;
                        _touched = true;
                      }),
              ),
            ],
            const SizedBox(height: 12),
            Expanded(
              child: Center(
                child: AspectRatio(
                  aspectRatio: page.aspectRatio,
                  child: LayoutBuilder(
                    builder: (context, constraints) {
                      final size = constraints.biggest;
                      return GestureDetector(
                        key: const ValueKey('figure_crop_canvas'),
                        behavior: HitTestBehavior.opaque,
                        // The box starts where the finger went down.
                        dragStartBehavior: DragStartBehavior.down,
                        onPanStart: _saving
                            ? null
                            : (d) => _start(d.localPosition, size),
                        onPanUpdate: _saving ? null : (d) => _update(d, size),
                        onPanEnd: (_) => setState(() => _drag = null),
                        child: Stack(
                          fit: StackFit.expand,
                          children: [
                            ColoredBox(
                              color: theme.colorScheme.surfaceContainerHighest,
                              child: image.when(
                                loading: () => const Center(
                                  child: CircularProgressIndicator(),
                                ),
                                error: (e, _) => Center(
                                  child: TextButton.icon(
                                    onPressed: () => ref.invalidate(
                                      examPageImageProvider(page.id),
                                    ),
                                    icon: const Icon(Icons.refresh),
                                    label: Text(
                                      apiStatusCode(e) == 404
                                          ? 'ภาพหน้านี้ถูกลบแล้ว'
                                          : 'โหลดภาพไม่ได้ ลองใหม่',
                                    ),
                                  ),
                                ),
                                data: (bytes) => Image.memory(
                                  bytes,
                                  fit: BoxFit.fill,
                                  gaplessPlayback: true,
                                  errorBuilder: (_, _, _) => const Center(
                                    child: Text('เปิดภาพไม่ได้'),
                                  ),
                                ),
                              ),
                            ),
                            if (box != null)
                              CustomPaint(
                                painter: _BoxPainter(
                                  box,
                                  color: theme.colorScheme.primary,
                                  handle: _handle / 2,
                                ),
                              ),
                          ],
                        ),
                      );
                    },
                  ),
                ),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              box == null
                  ? 'ยังไม่มีกรอบ ลากบนภาพเพื่อวาด'
                  : !box.valid
                  ? 'กรอบเล็กเกินไป ลากให้ใหญ่ขึ้น'
                  : 'กรอบ ${box.toList().join(', ')} (บน ซ้าย ล่าง ขวา จาก 1000)',
              key: const ValueKey('figure_crop_status'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: box != null && !box.valid
                    ? theme.colorScheme.error
                    : null,
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 12),
            FilledButton.icon(
              key: const ValueKey('figure_crop_save'),
              onPressed: _saving || box == null || !box.valid || !_touched
                  ? null
                  : _save,
              icon: _saving
                  ? const SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.crop),
              label: const Text('ตัดภาพใหม่ตามกรอบ'),
            ),
          ],
        ),
      ),
    );
  }
}

class _BoxPainter extends CustomPainter {
  _BoxPainter(this.box, {required this.color, required this.handle});

  final FigureBox box;
  final Color color;
  final double handle;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Rect.fromLTRB(
      box.xmin / 1000 * size.width,
      box.ymin / 1000 * size.height,
      box.xmax / 1000 * size.width,
      box.ymax / 1000 * size.height,
    );
    final shade = Paint()..color = Colors.black.withValues(alpha: 0.35);
    final outside = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(Offset.zero & size)
      ..addRect(rect);
    canvas.drawPath(outside, shade);
    canvas.drawRect(
      rect,
      Paint()
        ..color = color
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2,
    );
    final dot = Paint()..color = color;
    for (final c in [
      rect.topLeft,
      rect.topRight,
      rect.bottomLeft,
      rect.bottomRight,
    ]) {
      canvas.drawCircle(c, handle / 2, dot);
    }
  }

  @override
  bool shouldRepaint(_BoxPainter old) =>
      old.box != box || old.color != color || old.handle != handle;
}
