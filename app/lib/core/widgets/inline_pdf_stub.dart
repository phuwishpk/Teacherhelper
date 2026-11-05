import 'inline_pdf.dart';

/// Not the web: there is no browser PDF viewer to embed (DESIGN §25.7).
InlinePdf? platformInlinePdf() => null;
