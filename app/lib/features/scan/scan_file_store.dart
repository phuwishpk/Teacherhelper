import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

/// Where queued scans keep their images until the upload succeeds.
///
/// The camera and the native pipeline write into the cache directory,
/// which Android may clear when storage runs low. A scan that waits offline
/// for days must not lose its crops, so confirmed files are moved into
/// `<app support>/scan_queue/<client_scan_id>/`.
class ScanFileStore {
  ScanFileStore(this._root);

  /// Cache sub-folder the Kotlin pipeline writes each page into
  /// (`ScanPipelineImpl.OUTPUT_DIR`).
  static const nativeOutputFolder = 'scan_pipeline';

  final Future<Directory> Function() _root;

  /// Moves every file of [files] (multipart field -> path) into the folder
  /// of [clientScanId], named after its field, and returns the new map.
  Future<Map<String, String>> adopt(
    String clientScanId,
    Map<String, String> files,
  ) async {
    final dir = Directory(p.join((await _root()).path, clientScanId));
    await dir.create(recursive: true);
    final adopted = <String, String>{};
    for (final entry in files.entries) {
      final source = File(entry.value);
      final target = p.join(
        dir.path,
        '${entry.key}${p.extension(entry.value)}',
      );
      if (p.equals(source.path, target)) {
        adopted[entry.key] = target;
        continue;
      }
      adopted[entry.key] = (await _move(source, target)).path;
    }
    return adopted;
  }

  /// Deletes [paths] (best effort), then removes their folder when it is
  /// left empty and belongs to the scan feature: a per-page output folder
  /// of the native pipeline (`<cache>/scan_pipeline/<uuid>`) or a scan
  /// folder of this store. Other folders (the camera writes straight into
  /// the cache root) are never removed.
  Future<void> discard(Iterable<String> paths) async {
    final root = (await _root()).path;
    final parents = <String>{};
    for (final path in paths) {
      final file = File(path);
      try {
        if (await file.exists()) await file.delete();
      } on FileSystemException {
        // A leftover temp file is harmless.
      }
      parents.add(file.parent.path);
    }
    for (final parent in parents) {
      final owned =
          p.basename(p.dirname(parent)) == nativeOutputFolder ||
          p.isWithin(root, parent);
      if (!owned) continue;
      final dir = Directory(parent);
      try {
        if (await dir.exists() && await dir.list().isEmpty) {
          await dir.delete();
        }
      } on FileSystemException {
        // Same as above.
      }
    }
  }

  Future<File> _move(File source, String target) async {
    try {
      return await source.rename(target);
    } on FileSystemException {
      // Different file systems: copy, then remove the original.
      final copy = await source.copy(target);
      await source.delete();
      return copy;
    }
  }
}

final scanFileStoreProvider = Provider<ScanFileStore>(
  (ref) => ScanFileStore(
    () async => Directory(
      p.join((await getApplicationSupportDirectory()).path, 'scan_queue'),
    ),
  ),
);
