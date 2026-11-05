import 'dart:typed_data';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'inline_pdf_stub.dart'
    if (dart.library.js_interop) 'inline_pdf_web.dart';

/// A PDF shown inside the page (DESIGN §25.7): on the web, the browser's
/// own PDF viewer draws a handed-in PDF (most come from Google Classroom)
/// in the review pane. Elsewhere there is none (null): Android hands the
/// file to another app ("ดาวน์โหลดไฟล์").
abstract class InlinePdf {
  /// The PDF filling the space it is given.
  Widget view(Uint8List bytes);

  /// The PDF in a new browser tab: full screen, and for phone browsers
  /// that do not draw a PDF inside a page.
  void openInNewTab(Uint8List bytes);
}

final inlinePdfProvider = Provider<InlinePdf?>((ref) => platformInlinePdf());
