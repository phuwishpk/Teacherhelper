import 'dart:async';
import 'dart:js_interop';
import 'dart:typed_data';

import 'package:flutter/widgets.dart';

import 'inline_pdf.dart';

@JS('Blob')
extension type _Blob._(JSObject _) implements JSObject {
  external factory _Blob(JSArray<JSAny> parts, _BlobOptions options);
}

extension type _BlobOptions._(JSObject _) implements JSObject {
  external factory _BlobOptions({String type});
}

@JS('URL.createObjectURL')
external String _createObjectUrl(_Blob blob);

@JS('URL.revokeObjectURL')
external void _revokeObjectUrl(String url);

@JS('window.open')
external JSObject? _windowOpen(String url, String target);

extension type _Frame._(JSObject _) implements JSObject {
  external set src(String value);
  external set title(String value);
  external _Style get style;
}

extension type _Style._(JSObject _) implements JSObject {
  external set border(String value);
  external set width(String value);
  external set height(String value);
}

/// The PDF as a blob: URL of the page (the file is fetched with the bearer
/// token, so it has no URL of its own that a frame could load).
String _blobUrl(Uint8List bytes) => _createObjectUrl(
  _Blob([bytes.toJS].toJS, _BlobOptions(type: 'application/pdf')),
);

InlinePdf? platformInlinePdf() => const _BrowserPdf();

class _BrowserPdf implements InlinePdf {
  const _BrowserPdf();

  @override
  Widget view(Uint8List bytes) => _PdfFrame(bytes: bytes);

  @override
  void openInNewTab(Uint8List bytes) {
    final url = _blobUrl(bytes);
    _windowOpen(url, '_blank');
    // The new tab has loaded it by then; the URL is not needed after.
    Timer(const Duration(minutes: 1), () => _revokeObjectUrl(url));
  }
}

/// An <iframe> showing the blob: URL, freed with the widget.
class _PdfFrame extends StatefulWidget {
  const _PdfFrame({required this.bytes});

  final Uint8List bytes;

  @override
  State<_PdfFrame> createState() => _PdfFrameState();
}

class _PdfFrameState extends State<_PdfFrame> {
  late final String _url = _blobUrl(widget.bytes);

  @override
  void dispose() {
    _revokeObjectUrl(_url);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => HtmlElementView.fromTagName(
    tagName: 'iframe',
    onElementCreated: (Object element) {
      final frame = _Frame._(element as JSObject);
      frame.title = 'ภาพงานทั้งหน้า (PDF)';
      frame.style
        ..border = 'none'
        ..width = '100%'
        ..height = '100%';
      frame.src = _url;
    },
  );
}
