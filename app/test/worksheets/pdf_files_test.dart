import 'package:dio/dio.dart';
import 'package:eduvision/features/worksheets/pdf_actions.dart';
import 'package:eduvision/features/worksheets/pdf_files.dart';
import 'package:flutter_test/flutter_test.dart';

class _Downloader extends PdfDownloader {
  _Downloader() : super(Dio());

  final calls = <(String, String)>[];

  @override
  Future<String> download(String url, {required String fileName}) async {
    calls.add((url, fileName));
    return '/cache/pdf/$fileName';
  }
}

/// PdfFiles off the web: the PDF lands in the app cache through the
/// authenticated downloader and can be opened in a viewer.
void main() {
  test('fetch downloads to a cache file on Android', () async {
    final downloader = _Downloader();
    final files = PdfFiles(Dio(), downloader);

    final f = await files.fetch(
      '/api/v1/worksheet-prints/9/file',
      fileName: 'exam-40-key-sheet-v2.pdf',
    );

    expect(downloader.calls.single, (
      '/api/v1/worksheet-prints/9/file',
      'exam-40-key-sheet-v2.pdf',
    ));
    expect(f.name, 'exam-40-key-sheet-v2.pdf');
    expect(f.path, '/cache/pdf/exam-40-key-sheet-v2.pdf');
    expect(f.bytes, isNull);
    expect(files.canOpen, isTrue);
    expect(files.saveLabel, 'บันทึกลงเครื่อง');
  });
}
