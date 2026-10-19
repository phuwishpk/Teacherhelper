import 'dart:async';

import 'package:eduvision/features/upload_queue/upload_worker.dart';
import 'package:flutter_test/flutter_test.dart';

/// iOS uploads in the foreground (DESIGN §33): drain at once, retry while
/// anything is left.
void main() {
  const retry = Duration(milliseconds: 20);

  test('drains at once and stops when nothing is left', () async {
    var drains = 0;
    final scheduler = ForegroundUploadScheduler(
      drain: () async => drains++,
      awaiting: () async => 0,
      retryEvery: retry,
    );
    await scheduler.requestUpload();
    await Future<void>.delayed(retry * 3);
    expect(drains, 1);
    scheduler.dispose();
  });

  test('drains again while scans are left, then stops', () async {
    var drains = 0;
    var left = 2;
    final scheduler = ForegroundUploadScheduler(
      drain: () async {
        drains++;
        left--;
      },
      awaiting: () async => left,
      retryEvery: retry,
    );
    await scheduler.requestUpload();
    await Future<void>.delayed(retry * 6);
    expect(drains, 2);
    expect(left, 0);
    scheduler.dispose();
  });

  test('cancelPending stops the retry', () async {
    var drains = 0;
    final scheduler = ForegroundUploadScheduler(
      drain: () async => drains++,
      awaiting: () async => 1,
      retryEvery: retry,
    );
    await scheduler.requestUpload();
    await scheduler.cancelPending();
    await Future<void>.delayed(retry * 3);
    expect(drains, 1);
    scheduler.dispose();
  });

  test('a request during a drain makes one more pass', () async {
    var drains = 0;
    final first = Completer<void>();
    late ForegroundUploadScheduler scheduler;
    scheduler = ForegroundUploadScheduler(
      drain: () async {
        drains++;
        if (drains == 1) {
          unawaited(scheduler.requestUpload());
          await first.future;
        }
      },
      awaiting: () async => 0,
      retryEvery: retry,
    );
    final run = scheduler.requestUpload();
    first.complete();
    await run;
    expect(drains, 2);
    scheduler.dispose();
  });

  test('a failed drain still schedules the retry', () async {
    var drains = 0;
    final scheduler = ForegroundUploadScheduler(
      drain: () async {
        drains++;
        if (drains == 1) throw StateError('offline');
      },
      awaiting: () async => drains < 2 ? 1 : 0,
      retryEvery: retry,
    );
    await scheduler.requestUpload();
    await Future<void>.delayed(retry * 4);
    expect(drains, 2);
    scheduler.dispose();
  });
}
