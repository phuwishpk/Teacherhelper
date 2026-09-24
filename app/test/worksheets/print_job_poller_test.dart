import 'package:eduvision/features/worksheets/print_job.dart';
import 'package:eduvision/features/worksheets/print_job_poller.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('polls until ready without real delays', () async {
    final statuses = ['queued', 'rendering', 'ready'];
    var i = 0;
    final delays = <Duration>[];
    final job = await waitForPrintJob(
      () async => PrintJob(
        id: 1,
        status: statuses[i++],
        downloadUrl: i == 3 ? '/api/v1/worksheet-prints/1/file' : null,
      ),
      delay: (d) async => delays.add(d),
    );
    expect(job.isReady, isTrue);
    expect(delays.length, 2);
  });

  test('a failed job throws PrintJobFailed', () {
    expect(
      waitForPrintJob(
        () async => const PrintJob(id: 1, status: 'failed', error: 'mPDF'),
        delay: (_) async {},
      ),
      throwsA(isA<PrintJobFailed>()),
    );
  });

  test('fromJson accepts the alternative field names', () {
    final job = PrintJob.fromJson({
      'id': 9,
      'status': 'ready',
      'file_url': 'https://x/y.pdf',
      'status_url': '/api/v1/login-card-prints/9',
    });
    expect(job.isReady, isTrue);
    expect(job.pollUrl, '/api/v1/login-card-prints/9');
  });
}
