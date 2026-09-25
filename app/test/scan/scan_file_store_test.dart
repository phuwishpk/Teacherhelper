import 'dart:io';

import 'package:eduvision/features/scan/scan_file_store.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;

void main() {
  late Directory tmp;
  late Directory root;
  late ScanFileStore store;

  setUp(() async {
    tmp = await Directory.systemTemp.createTemp('scan_file_store_test');
    root = Directory(p.join(tmp.path, 'support', 'scan_queue'));
    store = ScanFileStore(() async => root);
  });

  tearDown(() => tmp.delete(recursive: true));

  Future<String> write(String relative) async {
    final f = File(p.join(tmp.path, relative));
    await f.create(recursive: true);
    await f.writeAsString(relative);
    return f.path;
  }

  test('adopt moves files into the scan folder, named by field', () async {
    final page = await write('cache/scan_pipeline/u1/page.webp');
    final crop = await write('cache/scan_pipeline/u1/q501.webp');

    final adopted = await store.adopt('scan-1', {
      'page': page,
      'crop_q501': crop,
    });

    expect(adopted, {
      'page': p.join(root.path, 'scan-1', 'page.webp'),
      'crop_q501': p.join(root.path, 'scan-1', 'crop_q501.webp'),
    });
    expect(await File(adopted['page']!).readAsString(), endsWith('page.webp'));
    expect(File(page).existsSync(), isFalse);

    // Adopting again (e.g. a retried step) is a no-op for files in place.
    expect(await store.adopt('scan-1', adopted), adopted);
  });

  test('discard removes only folders the scan feature owns', () async {
    final photo = await write('cache/CAP123.jpg');
    final crop = await write('cache/scan_pipeline/u2/q1.webp');
    final queued = await write('support/scan_queue/scan-2/page.webp');
    final unrelated = await write('elsewhere/sub/file.txt');

    await store.discard([photo, crop, queued, unrelated, '/no/such/file']);

    expect(File(photo).existsSync(), isFalse);
    expect(Directory(p.join(tmp.path, 'cache')).existsSync(), isTrue);
    expect(
      Directory(p.join(tmp.path, 'cache', 'scan_pipeline', 'u2')).existsSync(),
      isFalse,
    );
    expect(Directory(p.join(root.path, 'scan-2')).existsSync(), isFalse);
    expect(File(unrelated).existsSync(), isFalse);
    expect(
      Directory(p.join(tmp.path, 'elsewhere', 'sub')).existsSync(),
      isTrue,
    );
  });

  test('a folder that still has files is kept', () async {
    final a = await write('cache/scan_pipeline/u3/a.webp');
    final b = await write('cache/scan_pipeline/u3/b.webp');

    await store.discard([a]);

    expect(File(b).existsSync(), isTrue);
    expect(Directory(p.dirname(b)).existsSync(), isTrue);
  });
}
