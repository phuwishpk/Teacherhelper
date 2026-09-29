import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../assignments/answer_key_models.dart';
import 'scan_processor.dart';

/// Local path of a picked file for the native marker pipeline, which reads
/// files only: the picker's own copy when it has one, else [file]'s bytes
/// written to the cache. Null when neither is available.
typedef PickedFileStager = Future<String?> Function(PickedDocument file);

Future<String?> _stage(PickedDocument file) async {
  final path = file.path;
  if (path != null) return path;
  final bytes = file.bytes;
  if (bytes == null) return null;
  final dir = Directory(p.join((await getTemporaryDirectory()).path, 'picks'));
  await dir.create(recursive: true);
  final target = File(
    p.join(
      dir.path,
      '${DateTime.now().microsecondsSinceEpoch}_${p.basename(file.name)}',
    ),
  );
  await target.writeAsBytes(bytes, flush: true);
  return target.path;
}

final pickedFileStagerProvider = Provider<PickedFileStager>((ref) => _stage);

/// A picked file the marker pipeline could not use goes to the whole-page
/// path (DESIGN §19.6): no markers, no or a foreign QR, a layout that
/// cannot be found, an unreadable image. Only a photo that failed nothing
/// but the blur check stays on the scan screen, where the teacher may keep
/// it anyway.
bool needsWholePage(ScanAnalysis analysis) =>
    analysis is ScanRejected && !analysis.canOverride;
