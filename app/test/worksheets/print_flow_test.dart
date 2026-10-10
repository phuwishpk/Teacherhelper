import 'dart:async';

import 'package:dio/dio.dart';
import 'package:eduvision/features/worksheets/pdf_actions.dart';
import 'package:eduvision/features/worksheets/pdf_files.dart';
import 'package:eduvision/features/worksheets/print_flow.dart';
import 'package:eduvision/features/worksheets/print_job.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../review/review_fixtures.dart';

/// Stands in for the Android cache file or, with [web], for the bytes the
/// browser build keeps in memory (DESIGN §25.2).
class _Files extends PdfFiles {
  _Files({this.web = false}) : super(Dio(), PdfDownloader(Dio()));

  final bool web;
  final calls = <(String url, String fileName)>[];
  final opened = <String>[];
  final saved = <String>[];
  final shared = <(String name, String subject)>[];

  @override
  bool get canOpen => !web;

  @override
  String get saveLabel => web ? 'ดาวน์โหลด' : 'บันทึกลงเครื่อง';

  @override
  Future<PdfFile> fetch(String url, {required String fileName}) async {
    calls.add((url, fileName));
    return PdfFile(name: fileName, path: web ? null : '/cache/pdf/$fileName');
  }

  @override
  Future<void> open(BuildContext context, PdfFile file) async {
    opened.add(file.name);
  }

  @override
  Future<bool> save(PdfFile file) async {
    saved.add(file.name);
    return true;
  }

  @override
  Future<void> share(PdfFile file, {required String subject}) async {
    shared.add((file.name, subject));
  }
}

Widget _host({
  required Future<PrintJob> Function() request,
  required Future<PrintJob> Function(PrintJob) poll,
  required _Files downloader,
}) {
  return ProviderScope(
    overrides: [pdfFilesProvider.overrideWithValue(downloader)],
    child: MaterialApp(
      home: Scaffold(
        body: Consumer(
          builder: (context, ref, _) => Center(
            child: FilledButton(
              onPressed: () => runPrintFlow(
                context,
                ref,
                title: 'ใบงาน บวกเลข',
                fileName: 'worksheets-5-v1.pdf',
                request: request,
                poll: poll,
              ),
              child: const Text('พิมพ์'),
            ),
          ),
        ),
      ),
    ),
  );
}

void main() {
  testWidgets('queued -> ready: progress dialog, download, open/share sheet', (
    tester,
  ) async {
    final downloader = _Files();
    final requested = Completer<PrintJob>();
    final polled = Completer<PrintJob>();
    var polls = 0;
    await tester.pumpWidget(
      _host(
        request: () => requested.future,
        poll: (job) {
          polls++;
          expect(job.id, 1);
          return polled.future;
        },
        downloader: downloader,
      ),
    );

    await tester.tap(find.text('พิมพ์'));
    await tester.pump();
    expect(find.text('ใบงาน บวกเลข'), findsOneWidget);
    expect(find.text('กำลังส่งคำขอ…'), findsOneWidget);

    requested.complete(const PrintJob(id: 1, status: 'queued'));
    await tester.pump();
    expect(find.text('อยู่ในคิว'), findsOneWidget);
    expect(polls, 1);

    polled.complete(
      const PrintJob(
        id: 1,
        status: 'ready',
        downloadUrl: '/api/v1/worksheet-prints/1/file',
      ),
    );
    await tester.pumpAndSettle();

    expect(downloader.calls.single, (
      '/api/v1/worksheet-prints/1/file',
      'worksheets-5-v1.pdf',
    ));
    expect(find.text('กำลังส่งคำขอ…'), findsNothing);
    expect(find.text('เปิดไฟล์'), findsOneWidget);
    expect(find.text('บันทึกลงเครื่อง'), findsOneWidget);
    expect(find.text('แชร์ / ส่งไปพิมพ์'), findsOneWidget);
    expect(find.text('worksheets-5-v1.pdf'), findsOneWidget);

    await tester.tap(find.text('เปิดไฟล์'));
    await tester.pumpAndSettle();
    expect(downloader.opened, ['worksheets-5-v1.pdf']);
    expect(find.text('แชร์ / ส่งไปพิมพ์'), findsNothing);
  });

  testWidgets('on the web the sheet offers a download instead of opening', (
    tester,
  ) async {
    final files = _Files(web: true);
    await tester.pumpWidget(
      _host(
        request: () async => const PrintJob(
          id: 3,
          status: 'ready',
          downloadUrl: '/api/v1/login-card-prints/3/file',
        ),
        poll: (job) async => job,
        downloader: files,
      ),
    );

    await tester.tap(find.text('พิมพ์'));
    await tester.pumpAndSettle();

    expect(files.calls.single.$1, '/api/v1/login-card-prints/3/file');
    expect(find.text('เปิดไฟล์'), findsNothing);
    expect(find.text('ดาวน์โหลด'), findsOneWidget);
    expect(find.text('แชร์ / ส่งไปพิมพ์'), findsOneWidget);

    await tester.tap(find.text('ดาวน์โหลด'));
    await tester.pumpAndSettle();
    expect(files.saved, ['worksheets-5-v1.pdf']);
    expect(find.text('บันทึก worksheets-5-v1.pdf แล้ว'), findsOneWidget);
  });

  testWidgets('sharing passes the title as the subject', (tester) async {
    final files = _Files();
    await tester.pumpWidget(
      _host(
        request: () async => const PrintJob(
          id: 4,
          status: 'ready',
          downloadUrl: '/api/v1/worksheet-prints/4/file',
        ),
        poll: (job) async => job,
        downloader: files,
      ),
    );

    await tester.tap(find.text('พิมพ์'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('แชร์ / ส่งไปพิมพ์'));
    await tester.pumpAndSettle();

    expect(files.shared, [('worksheets-5-v1.pdf', 'ใบงาน บวกเลข')]);
  });

  testWidgets('a failed render closes the dialog and shows the reason', (
    tester,
  ) async {
    final downloader = _Files();
    await tester.pumpWidget(
      _host(
        request: () async => const PrintJob(id: 2, status: 'queued'),
        poll: (_) async =>
            const PrintJob(id: 2, status: 'failed', error: 'mPDF: ไม่พบฟอนต์'),
        downloader: downloader,
      ),
    );

    await tester.tap(find.text('พิมพ์'));
    await tester.pumpAndSettle();

    expect(find.text('mPDF: ไม่พบฟอนต์'), findsOneWidget);
    expect(find.text('เปิดไฟล์'), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(downloader.calls, isEmpty);
  });

  testWidgets('an API error shows the server message', (tester) async {
    final downloader = _Files();
    await tester.pumpWidget(
      _host(
        request: () async => throw apiError(422, {
          'message': 'สร้าง layout ก่อนพิมพ์',
          'code': 'no_layout',
        }),
        poll: (job) async => job,
        downloader: downloader,
      ),
    );

    await tester.tap(find.text('พิมพ์'));
    await tester.pumpAndSettle();

    expect(find.text('สร้าง layout ก่อนพิมพ์'), findsOneWidget);
    expect(find.byType(AlertDialog), findsNothing);
    expect(downloader.calls, isEmpty);
  });

  test('printStatusLabel covers every server status', () {
    expect(printStatusLabel('queued'), 'อยู่ในคิว');
    expect(printStatusLabel('rendering'), 'กำลังสร้าง PDF');
    expect(printStatusLabel('ready'), 'พร้อมดาวน์โหลด');
    expect(printStatusLabel('failed'), 'สร้างไม่สำเร็จ');
    expect(printStatusLabel('odd'), 'odd');
  });
}
