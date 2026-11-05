import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../api/submission_pages.dart';
import 'content_column.dart';
import 'inline_pdf.dart';
import 'pdf_page_images.dart';

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
/// On the web a PDF is drawn by the browser's viewer instead (DESIGN §25.7).
/// A PDF answer whose page is known is drawn as that page with its box, on
/// the web (pdf.js) and on the phone (DESIGN §46).
class SubmissionPageImage extends ConsumerWidget {
  const SubmissionPageImage({
    super.key,
    required this.pageId,
    this.mimeType,
    this.answerBox,
    this.answerPage,
    this.height = 360,
  });

  final int pageId;
  final String? mimeType;

  /// `[ymin, xmin, ymax, xmax]`, 0–1000.
  final List<double>? answerBox;

  /// The page of the file [answerBox] is on (1-based); a PDF is drawn as
  /// that page when it is known.
  final int? answerPage;
  final double height;

  bool get _isPdf => mimeType == 'application/pdf';

  static const double _pdfHeight = 560;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final file = ref.watch(submissionPageProvider(pageId));
    final inlinePdf = _isPdf ? ref.watch(inlinePdfProvider) : null;
    final pdfPages = _isPdf && answerBox != null && answerPage != null
        ? ref.watch(pdfPageImagesProvider)
        : null;
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
      loading: () => box(const CircularProgressIndicator.adaptive()),
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
      data: (bytes) {
        if (!_isPdf) {
          return _DecodedPage(
            key: ValueKey(pageId),
            pageId: pageId,
            bytes: bytes,
            mimeType: mimeType,
            answerBox: answerBox,
            height: height,
            frame: box,
          );
        }
        final whole = inlinePdf != null
            ? _InlinePdfPage(
                pageId: pageId,
                bytes: bytes,
                viewer: inlinePdf,
                // A page of text needs more room than a photo's box.
                height: height < _pdfHeight ? _pdfHeight : height,
              )
            : box(
                _Undrawable(pageId: pageId, bytes: bytes, mimeType: mimeType),
              );
        final page = answerPage;
        if (pdfPages == null || page == null) return whole;
        return _PdfAnswerPage(
          key: ValueKey('pdf_${pageId}_$page'),
          pageId: pageId,
          pdf: bytes,
          pageNo: page,
          pages: pdfPages,
          answerBox: answerBox,
          height: height,
          frame: box,
          inlinePdf: inlinePdf,
          fallback: whole,
        );
      },
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
          return widget.frame(const CircularProgressIndicator.adaptive());
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

/// A PDF page on the web (DESIGN §25.7): the browser draws it in the pane;
/// "เปิดในแท็บใหม่" shows it full screen (and is the way on a phone browser
/// that does not draw a PDF inside a page), "ดาวน์โหลดไฟล์" keeps a copy.
class _InlinePdfPage extends StatelessWidget {
  const _InlinePdfPage({
    required this.pageId,
    required this.bytes,
    required this.viewer,
    required this.height,
  });

  final int pageId;
  final Uint8List bytes;
  final InlinePdf viewer;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Column(
      key: const ValueKey('page_pdf_inline'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Semantics(
          label: 'ภาพงานทั้งหน้า (PDF)',
          child: ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: SizedBox(height: height, child: viewer.view(bytes)),
          ),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            OutlinedButton.icon(
              key: const ValueKey('page_pdf_new_tab'),
              onPressed: () => viewer.openInNewTab(bytes),
              icon: const Icon(Icons.open_in_new),
              label: const Text('เปิดในแท็บใหม่'),
            ),
            _DownloadButton(
              pageId: pageId,
              bytes: bytes,
              mimeType: 'application/pdf',
            ),
          ],
        ),
      ],
    );
  }
}

/// The page of a PDF the answer is on, drawn with its red box like a
/// photo (DESIGN §46), with the page number and the whole file a tap away.
/// [fallback] (the whole PDF) when the page cannot be drawn.
class _PdfAnswerPage extends StatefulWidget {
  const _PdfAnswerPage({
    super.key,
    required this.pageId,
    required this.pdf,
    required this.pageNo,
    required this.pages,
    required this.answerBox,
    required this.height,
    required this.frame,
    required this.inlinePdf,
    required this.fallback,
  });

  final int pageId;
  final Uint8List pdf;
  final int pageNo;
  final PdfPageImages pages;
  final List<double>? answerBox;
  final double height;
  final Widget Function(Widget child) frame;
  final InlinePdf? inlinePdf;
  final Widget fallback;

  @override
  State<_PdfAnswerPage> createState() => _PdfAnswerPageState();
}

class _PdfAnswerPageState extends State<_PdfAnswerPage> {
  late final Future<PdfPageImage> _page = widget.pages.render(
    widget.pdf,
    widget.pageNo,
  );

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<PdfPageImage>(
      future: _page,
      builder: (context, snap) {
        if (snap.hasError) return widget.fallback;
        final page = snap.data;
        if (page == null) {
          return widget.frame(const CircularProgressIndicator.adaptive());
        }
        final theme = Theme.of(context);
        final total = page.pages;
        return Column(
          key: const ValueKey('page_pdf_answer'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            _DecodedPage(
              pageId: widget.pageId,
              bytes: page.bytes,
              mimeType: 'image/png',
              answerBox: widget.answerBox,
              height: widget.height,
              frame: widget.frame,
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  total != null
                      ? 'หน้า ${page.page} จาก $total ของไฟล์ PDF'
                      : 'หน้า ${page.page} ของไฟล์ PDF',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                if (widget.inlinePdf case final viewer?)
                  OutlinedButton.icon(
                    key: const ValueKey('page_pdf_new_tab'),
                    onPressed: () => viewer.openInNewTab(widget.pdf),
                    icon: const Icon(Icons.open_in_new),
                    label: const Text('เปิดทั้งไฟล์ในแท็บใหม่'),
                  ),
                _DownloadButton(
                  pageId: widget.pageId,
                  bytes: widget.pdf,
                  mimeType: 'application/pdf',
                ),
              ],
            ),
          ],
        );
      },
    );
  }
}

/// "แสดงภาพนี้บนเครื่องนี้ไม่ได้" + "ดาวน์โหลดไฟล์".
class _Undrawable extends StatelessWidget {
  const _Undrawable({
    required this.pageId,
    required this.bytes,
    required this.mimeType,
  });

  final int pageId;
  final Uint8List bytes;
  final String? mimeType;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final pdf = mimeType == 'application/pdf';
    final how = kIsWeb
        ? 'ดาวน์โหลดไปเปิดในเครื่องได้'
        : 'เปิดด้วยแอปอื่นในเครื่องได้';
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
              ? 'ไฟล์ PDF $how (AI อ่านไฟล์นี้ได้ตามปกติ)'
              : '$how (AI อ่านไฟล์นี้ได้ตามปกติ)',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        _DownloadButton(pageId: pageId, bytes: bytes, mimeType: mimeType),
      ],
    );
  }
}

/// "ดาวน์โหลดไฟล์": another app opens it on Android, the browser saves it
/// on the web.
class _DownloadButton extends ConsumerStatefulWidget {
  const _DownloadButton({
    required this.pageId,
    required this.bytes,
    required this.mimeType,
  });

  final int pageId;
  final Uint8List bytes;
  final String? mimeType;

  @override
  ConsumerState<_DownloadButton> createState() => _DownloadButtonState();
}

class _DownloadButtonState extends ConsumerState<_DownloadButton> {
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
  Widget build(BuildContext context) => OutlinedButton.icon(
    key: const ValueKey('page_download'),
    onPressed: _busy ? null : _download,
    icon: const Icon(Icons.download_outlined),
    label: const Text('ดาวน์โหลดไฟล์'),
  );
}
