import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../api/scan_pages.dart';
import 'submission_page_image.dart';

/// A warped answer-sheet page (`GET /scans/{id}/page`, DESIGN §22.11) with
/// [box] (`[ymin, xmin, ymax, xmax]`, 0–1000) highlighted: the row or digit
/// block of the answer under review. Tapping opens it full screen with
/// pinch-zoom. The page is deleted after publishing (410).
class ScanPageImage extends ConsumerWidget {
  const ScanPageImage({
    super.key,
    required this.scanId,
    this.box,
    this.height = 420,
    this.semanticLabel = 'ภาพหน้ากระดาษคำตอบ',
  });

  final int scanId;
  final List<double>? box;
  final double height;
  final String semanticLabel;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final file = ref.watch(scanPageProvider(scanId));
    final theme = Theme.of(context);
    Widget frame(Widget child) => Container(
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
    Widget problem(String message, {VoidCallback? retry}) => frame(
      Column(
        key: const ValueKey('scan_page_problem'),
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.image_not_supported_outlined,
            color: theme.colorScheme.onSurfaceVariant,
          ),
          const SizedBox(height: 4),
          Text(message, textAlign: TextAlign.center),
          if (retry != null)
            TextButton(onPressed: retry, child: const Text('ลองใหม่')),
        ],
      ),
    );

    return file.when(
      loading: () => frame(const CircularProgressIndicator()),
      error: (e, _) {
        final code = apiStatusCode(e);
        return problem(
          switch (code) {
            410 => 'ภาพหน้ากระดาษถูกลบหลังประกาศผลแล้ว',
            404 => 'ไม่พบภาพหน้ากระดาษนี้',
            403 => 'ไม่มีสิทธิ์ดูภาพนี้',
            _ => 'โหลดภาพหน้ากระดาษไม่ได้',
          },
          retry: code == null || code >= 500
              ? () => ref.invalidate(scanPageProvider(scanId))
              : null,
        );
      },
      data: (bytes) => _Decoded(
        key: ValueKey(scanId),
        bytes: bytes,
        box: box,
        height: height,
        semanticLabel: semanticLabel,
        frame: frame,
        problem: problem,
      ),
    );
  }
}

class _Decoded extends ConsumerStatefulWidget {
  const _Decoded({
    super.key,
    required this.bytes,
    required this.box,
    required this.height,
    required this.semanticLabel,
    required this.frame,
    required this.problem,
  });

  final Uint8List bytes;
  final List<double>? box;
  final double height;
  final String semanticLabel;
  final Widget Function(Widget child) frame;
  final Widget Function(String message) problem;

  @override
  ConsumerState<_Decoded> createState() => _DecodedState();
}

class _DecodedState extends ConsumerState<_Decoded> {
  late final Future<ui.Image> _image = ref.read(pageDecoderProvider)(
    widget.bytes,
  );
  ui.Image? _decoded;

  @override
  void initState() {
    super.initState();
    _image.then(
      (image) {
        if (mounted) {
          _decoded = image;
        } else {
          image.dispose();
        }
      },
      onError: (Object _) {}, // the FutureBuilder shows the problem
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
          return widget.problem('แสดงภาพหน้ากระดาษบนเครื่องนี้ไม่ได้');
        }
        final image = snap.data;
        if (image == null) {
          return widget.frame(const CircularProgressIndicator());
        }
        final page = AspectRatio(
          aspectRatio: image.width / image.height,
          child: CustomPaint(
            key: const ValueKey('scan_page_canvas'),
            painter: PagePainter(
              image: image,
              answerBox: widget.box,
              color: Theme.of(context).colorScheme.error,
            ),
          ),
        );
        return Semantics(
          label: widget.semanticLabel,
          image: true,
          child: InkWell(
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                fullscreenDialog: true,
                builder: (_) => Scaffold(
                  appBar: AppBar(title: const Text('ภาพหน้ากระดาษคำตอบ')),
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
