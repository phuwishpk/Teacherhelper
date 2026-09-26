import 'dart:async';

import 'package:dio/dio.dart';
import 'package:eduvision/features/worksheets/pdf_actions.dart';
import 'package:eduvision/features/worksheets/print_flow.dart';
import 'package:eduvision/features/worksheets/print_job.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../review/review_fixtures.dart';

class _Downloader extends PdfDownloader {
  _Downloader() : super(Dio());

  final calls = <(String url, String fileName)>[];

  @override
  Future<String> download(String url, {required String fileName}) async {
    calls.add((url, fileName));
    return '/cache/pdf/$fileName';
  }
}

Widget _host({
  required Future<PrintJob> Function() request,
  required Future<PrintJob> Function(PrintJob) poll,
  required _Downloader downloader,
}) {
  return ProviderScope(
    overrides: [pdfDownloaderProvider.overrideWithValue(downloader)],
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
    final downloader = _Downloader();
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
    expect(find.text('แชร์ / ส่งไปพิมพ์'), findsOneWidget);
    expect(find.text('worksheets-5-v1.pdf'), findsOneWidget);
  });

  testWidgets('a failed render closes the dialog and shows the reason', (
    tester,
  ) async {
    final downloader = _Downloader();
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
    final downloader = _Downloader();
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
