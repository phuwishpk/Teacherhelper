import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../api/submission_pages.dart';
import 'content_column.dart';

/// Decodes the bytes of a handed-in file into an image; throws when this
/// device cannot (a PDF, HEIC without the codec, a damaged file).
typedef PageDecoder = Future<ui.Image> Function(Uint8List bytes);

Future<ui.Image> decodePageImage(Uint8List bytes) async {
  final codec = await ui.instantiateImageCodec(bytes);
  try {
    final frame = await codec.getNextFrame();
    return frame.image;
  } finally {
    codec.dispose();
  }
}

/// The decoder the page widget uses (tests replace it: real decoding needs
/// `tester.runAsync`).
final pageDecoderProvider = Provider<PageDecoder>((ref) => decodePageImage);

/// A whole page as the student handed it in (DESIGN §19.4), with the
/// answer's `answer_box` highlighted when there is one. What the device
/// cannot draw (a PDF, HEIC on some phones and on the web) becomes the box
/// "แสดงภาพนี้บนเครื่องนี้ไม่ได้" with "ดาวน์โหลดไฟล์" (opened by another
/// app); grading is not affected because Gemini reads those files itself.
class SubmissionPageImage extends ConsumerWidget {
  const SubmissionPageImage({
    super.key,
    required this.pageId,
    this.mimeType,
    this.answerBox,
    this.height = 360,
  });

  final int pageId;
  final String? mimeType;

  /// `[ymin, xmin, ymax, xmax]`, 0–1000.
  final List<double>? answerBox;
  final double height;

  bool get _isPdf => mimeType == 'application/pdf';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final file = ref.watch(submissionPageProvider(pageId));
    final theme = Theme.of(context);
    Widget box(Widget child) => Container(
      height: height,
      width: double.infinity,
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      alignment: Alignment.center,
      padding: const EdgeInsets.all(12),
      child: child,
    );

    return file.when(
      loading: () => box(const CircularProgressIndicator()),
      error: (e, _) {
        final code = apiStatusCode(e);
        final message = switch (code) {
          410 => 'ไฟล์งานนี้ถูกลบตามนโยบายการเก็บข้อมูลแล้ว',
          404 => 'ไม่พบไฟล์งานนี้',
          403 => 'ไม่มีสิทธิ์ดูไฟล์นี้',
          _ => 'โหลดไฟล์งานไม่ได้',
        };
        return box(
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.image_not_supported_outlined,
                color: theme.colorScheme.onSurfaceVariant,
              ),
              const SizedBox(height: 4),
              Text(message, textAlign: TextAlign.center),
              if (code == null || code >= 500)
                TextButton(
                  onPressed: () =>
                      ref.invalidate(submissionPageProvider(pageId)),
                  child: const Text('ลองใหม่'),
                ),
            ],
          ),
        );
      },
      data: (bytes) => _isPdf
          ? box(_Undrawable(pageId: pageId, bytes: bytes, mimeType: mimeType))
          : _DecodedPage(
              key: ValueKey(pageId),
              pageId: pageId,
              bytes: bytes,
              mimeType: mimeType,
              answerBox: answerBox,
              height: height,
              frame: box,
            ),
    );
  }
}

class _DecodedPage extends ConsumerStatefulWidget {
  const _DecodedPage({
    super.key,
    required this.pageId,
    required this.bytes,
    required this.mimeType,
    required this.answerBox,
    required this.height,
    required this.frame,
  });

  final int pageId;
  final Uint8List bytes;
  final String? mimeType;
  final List<double>? answerBox;
  final double height;
  final Widget Function(Widget child) frame;

  @override
  ConsumerState<_DecodedPage> createState() => _DecodedPageState();
}

class _DecodedPageState extends ConsumerState<_DecodedPage> {
  late final Future<ui.Image> _image = ref.read(pageDecoderProvider)(
    widget.bytes,
  );
  ui.Image? _decoded;

  @override
  void initState() {
    super.initState();
    _image.then(
      (image) {
        // Freed on dispose, or right away when the widget is already gone.
        if (mounted) {
          _decoded = image;
        } else {
          image.dispose();
        }
      },
      onError: (Object _) {}, // the FutureBuilder shows the fallback
    );
  }

  @override
  void dispose() {
    _decoded?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<ui.Image>(
      future: _image,
      builder: (context, snap) {
        if (snap.hasError) {
          return widget.frame(
            _Undrawable(
              pageId: widget.pageId,
              bytes: widget.bytes,
              mimeType: widget.mimeType,
            ),
          );
        }
        final image = snap.data;
        if (image == null) {
          return widget.frame(const CircularProgressIndicator());
        }
        final page = _PageCanvas(image: image, answerBox: widget.answerBox);
        return Semantics(
          label: widget.answerBox == null
              ? 'ภาพงานทั้งหน้า'
              : 'ภาพงานทั้งหน้า ไฮไลต์ตำแหน่งคำตอบ',
          image: true,
          child: InkWell(
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                fullscreenDialog: true,
                builder: (_) => Scaffold(
                  appBar: AppBar(title: const Text('ภาพงานทั้งหน้า')),
                  body: InteractiveViewer(
                    maxScale: 8,
                    child: Center(child: page),
                  ),
                ),
              ),
            ),
            child: ConstrainedBox(
              constraints: BoxConstraints(maxHeight: widget.height),
              child: Center(child: page),
            ),
          ),
        );
      },
    );
  }
}

/// The decoded page at its aspect ratio, with the answer box drawn on top.
class _PageCanvas extends StatelessWidget {
  const _PageCanvas({required this.image, required this.answerBox});

  final ui.Image image;
  final List<double>? answerBox;

  @override
  Widget build(BuildContext context) {
    return AspectRatio(
      aspectRatio: image.width / image.height,
      child: CustomPaint(
        key: const ValueKey('page_canvas'),
        painter: PagePainter(
          image: image,
          answerBox: answerBox,
          color: Theme.of(context).colorScheme.error,
        ),
      ),
    );
  }
}

/// Paints [image] over the whole canvas and outlines [answerBox]
/// (`[ymin, xmin, ymax, xmax]`, 0–1000 of the page).
class PagePainter extends CustomPainter {
  PagePainter({
    required this.image,
    required this.answerBox,
    required this.color,
  });

  final ui.Image image;
  final List<double>? answerBox;
  final Color color;

  /// The box in canvas coordinates, or null.
  static Rect? boxRect(List<double>? box, Size size) {
    if (box == null) return null;
    return Rect.fromLTRB(
      box[1] / 1000 * size.width,
      box[0] / 1000 * size.height,
      box[3] / 1000 * size.width,
      box[2] / 1000 * size.height,
    );
  }

  @override
  void paint(Canvas canvas, Size size) {
    paintImage(
      canvas: canvas,
      rect: Offset.zero & size,
      image: image,
      fit: BoxFit.fill,
      filterQuality: FilterQuality.medium,
    );
    final rect = boxRect(answerBox, size);
    if (rect == null) return;
    canvas.drawRect(rect, Paint()..color = color.withValues(alpha: 0.12));
    canvas.drawRect(
      rect.inflate(2),
      Paint()
        ..color = color
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3,
    );
  }

  @override
  bool shouldRepaint(PagePainter old) =>
      old.image != image || old.answerBox != answerBox || old.color != color;
}

/// "แสดงภาพนี้บนเครื่องนี้ไม่ได้" + "ดาวน์โหลดไฟล์".
class _Undrawable extends ConsumerStatefulWidget {
  const _Undrawable({
    required this.pageId,
    required this.bytes,
    required this.mimeType,
  });

  final int pageId;
  final Uint8List bytes;
  final String? mimeType;

  @override
  ConsumerState<_Undrawable> createState() => _UndrawableState();
}

class _UndrawableState extends ConsumerState<_Undrawable> {
  bool _busy = false;

  Future<void> _download() async {
    setState(() => _busy = true);
    try {
      final problem = await ref
          .read(pageFileOpenerProvider)
          .open(
            widget.bytes,
            fileName:
                'page-${widget.pageId}.${pageFileExtension(widget.mimeType)}',
            mimeType: widget.mimeType,
          );
      if (problem != null && mounted) {
        showMessage(context, 'เปิดไฟล์ไม่ได้: $problem');
      }
    } catch (e) {
      if (mounted) showMessage(context, 'เปิดไฟล์ไม่ได้: $e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final pdf = widget.mimeType == 'application/pdf';
    return Column(
      key: const ValueKey('page_undrawable'),
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(
          pdf ? Icons.picture_as_pdf_outlined : Icons.broken_image_outlined,
          color: theme.colorScheme.onSurfaceVariant,
        ),
        const SizedBox(height: 4),
        const Text('แสดงภาพนี้บนเครื่องนี้ไม่ได้', textAlign: TextAlign.center),
        Text(
          pdf
              ? 'ไฟล์ PDF เปิดด้วยแอปอื่นในเครื่องได้ (AI อ่านไฟล์นี้ได้ตามปกติ)'
              : 'เปิดด้วยแอปอื่นในเครื่องได้ (AI อ่านไฟล์นี้ได้ตามปกติ)',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          key: const ValueKey('page_download'),
          onPressed: _busy ? null : _download,
          icon: const Icon(Icons.download_outlined),
          label: const Text('ดาวน์โหลดไฟล์'),
        ),
      ],
    );
  }
}
