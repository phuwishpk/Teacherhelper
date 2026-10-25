import 'dart:typed_data';

import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/hand_in/hand_in_repository.dart';
import 'package:eduvision/features/scan/qr_image_reader.dart';
import 'package:eduvision/features/scan/scan_camera.dart';
import 'package:eduvision/features/scan/web_scan_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../hand_in/hand_in_fakes.dart';
import '../helpers/pump_screen.dart';

const _homework = Assignment(
  id: 11,
  classroomId: 2,
  subjectId: 1,
  title: 'เศษส่วน',
  status: 'ready',
);

class _Camera implements ScanCamera {
  _Camera({this.initError});

  final ScanCameraException? initError;
  int shots = 0;

  @override
  Future<void> initialize() async {
    if (initError case final e?) throw e;
  }

  @override
  double get previewAspectRatio => 3 / 4;

  @override
  Widget buildPreview() => const ColoredBox(color: Colors.black);

  @override
  Future<String> takePicture() async => 'blob:shot${++shots}';

  @override
  Future<void> setTorch(bool on) async {}

  @override
  Future<void> dispose() async {}
}

/// Answers the QR texts in order, one per photo.
class _Qr implements QrImageReader {
  _Qr(this.payloads);

  final List<String?> payloads;
  int calls = 0;

  @override
  Future<String?> read(Uint8List image) async {
    return payloads[calls++];
  }
}

class _Assignments extends Fake implements AssignmentsRepository {
  @override
  Future<List<Assignment>> list({int? classroomId}) async => const [_homework];
}

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(studentId: 40, studentNumber: 1, name: 'ด.ช. กล้า'),
    RosterStudent(studentId: 41, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
  ];
}

Future<void> _pump(
  WidgetTester tester, {
  required FakeHandIn handIn,
  required _Qr qr,
  _Camera? camera,
  Size size = const Size(390, 844),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await pumpScreen(
    tester,
    const WebScanScreen(),
    overrides: [
      webScanCameraFactoryProvider.overrideWithValue(() => camera ?? _Camera()),
      // Each photo's bytes say which shot it was.
      cameraPhotoBytesProvider.overrideWithValue(
        (path) async => Uint8List.fromList([int.parse(path.substring(9))]),
      ),
      qrImageReaderProvider.overrideWithValue(qr),
      handInRepositoryProvider.overrideWithValue(handIn),
      assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
      classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
    ],
    extraRoutes: [
      GoRoute(
        path: '/hand-ins/upload',
        builder: (_, state) => const Text('teacher-upload'),
      ),
    ],
  );
}

Future<void> _shoot(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('web_scan_shutter')));
  await tester.pumpAndSettle();
}

void main() {
  for (final size in const [Size(390, 844), Size(820, 1180), Size(1440, 900)]) {
    testWidgets('photos are grouped by the QR and sent per student '
        '(${size.width.toInt()} px)', (tester) async {
      final handIn = FakeHandIn();
      await _pump(
        tester,
        handIn: handIn,
        size: size,
        qr: _Qr([
          'EV1.11.40.2.3.sig',
          'EV1.11.40.1.3.sig',
          null,
          'EV1.11.41.1.3.sig',
        ]),
      );

      for (var i = 0; i < 4; i++) {
        await _shoot(tester);
      }

      expect(find.text('ด.ช. กล้า'), findsOneWidget);
      expect(find.textContaining('หน้า 1, 2'), findsOneWidget);
      expect(find.text('ใช้ QR ไม่ได้ 1 รูป'), findsOneWidget);
      expect(find.text('ส่งตรวจ 2 คน'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('web_scan_send')));
      await tester.pumpAndSettle();

      expect(handIn.uploaded.map((u) => (u.$1, u.$2)), [(11, 40), (11, 41)]);
      // Page order, whatever order they were taken in.
      expect(handIn.uploaded.first.$3.map((f) => f.bytes!.single), [2, 1]);
      // The photo without a usable QR waits for "ส่งแบบเลือกนักเรียนเอง".
      expect(find.text('ใช้ QR ไม่ได้ 1 รูป'), findsOneWidget);
      await tester.scrollUntilVisible(find.text('ส่งแล้ว 2 คน'), 100);
      expect(find.text('ส่งแล้ว 2 คน'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('a photo without a QR goes to อัปโหลดรูปเพื่อตรวจ', (
    tester,
  ) async {
    await _pump(tester, handIn: FakeHandIn(), qr: _Qr([null]));
    await _shoot(tester);

    await tester.ensureVisible(
      find.byKey(const ValueKey('web_scan_upload_unread')),
    );
    await tester.tap(find.byKey(const ValueKey('web_scan_upload_unread')));
    await tester.pumpAndSettle();

    expect(find.text('teacher-upload'), findsOneWidget);
  });

  testWidgets('a failed hand-in stays with its reason', (tester) async {
    final handIn = FakeHandIn()
      ..error = apiError(422, 'การบ้านนี้ปิดรับแล้ว', 'assignment_closed');
    await _pump(tester, handIn: handIn, qr: _Qr(['EV1.11.40.1.3.sig']));
    await _shoot(tester);

    await tester.tap(find.byKey(const ValueKey('web_scan_send')));
    await tester.pumpAndSettle();

    expect(find.textContaining('การบ้านนี้ปิดรับแล้ว'), findsOneWidget);
    expect(find.text('ด.ช. กล้า'), findsOneWidget);
  });

  testWidgets('leaving with photos not sent asks first', (tester) async {
    await _pump(tester, handIn: FakeHandIn(), qr: _Qr(['EV1.11.40.1.3.sig']));
    await _shoot(tester);

    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.text('ยังไม่ได้ส่งตรวจ'), findsOneWidget);

    await tester.tap(find.text('ออกจากหน้านี้'));
    await tester.pumpAndSettle();
    expect(find.text('stub-home'), findsOneWidget);
  });

  testWidgets('a camera that cannot open offers photos from the device', (
    tester,
  ) async {
    await _pump(
      tester,
      handIn: FakeHandIn(),
      qr: _Qr(const []),
      camera: _Camera(
        initError: const ScanCameraException('ไม่พบกล้องในเครื่องนี้'),
      ),
    );

    expect(find.text('ไม่พบกล้องในเครื่องนี้'), findsOneWidget);
    expect(find.byKey(const ValueKey('web_scan_pick_instead')), findsOneWidget);
  });
}
