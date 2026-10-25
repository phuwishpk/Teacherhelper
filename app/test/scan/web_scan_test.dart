import 'dart:typed_data';

import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/scan/web_scan.dart';
import 'package:flutter_test/flutter_test.dart';

const _worksheet = Assignment(
  id: 11,
  subjectId: 1,
  classroomId: 2,
  title: 'เศษส่วน',
  status: 'ready',
);

const _draft = Assignment(
  id: 12,
  classroomId: 2,
  subjectId: 1,
  title: 'ยังไม่พิมพ์',
);

const _chosen = Assignment(
  id: 13,
  subjectId: 1,
  classroomId: 2,
  title: 'งานเฉพาะบางคน',
  status: 'ready',
  studentIds: [41],
);

Future<List<RosterStudent>> _roster(int classroomId) async => const [
  RosterStudent(studentId: 40, studentNumber: 1, name: 'ด.ช. กล้า'),
  RosterStudent(studentId: 41, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
];

Future<({WebScanTarget? target, String? problem})> _resolve(String? qr) =>
    resolveWebScanQr(
      qr,
      assignments: const [_worksheet, _draft, _chosen],
      roster: _roster,
    );

WebScanShot _shot(int id, {int student = 40, int page = 1}) => WebScanShot(
  id: id,
  file: PickedDocument(name: 'p$id.jpg', bytes: Uint8List(1)),
  target: WebScanTarget(
    assignmentId: 11,
    assignmentTitle: 'เศษส่วน',
    studentId: student,
    studentNumber: student == 40 ? 1 : 12,
    studentName: student == 40 ? 'ด.ช. กล้า' : 'ด.ญ. สมหญิง',
    page: page,
  ),
);

void main() {
  test('a worksheet QR of the teacher becomes the student and page', () async {
    final r = await _resolve('EV1.11.41.2.3.sig');

    expect(r.problem, isNull);
    expect(r.target!.studentName, 'ด.ญ. สมหญิง');
    expect(r.target!.studentNumber, 12);
    expect(r.target!.page, 2);
    expect(r.target!.assignmentTitle, 'เศษส่วน');
  });

  test('every unusable QR says why in Thai', () async {
    expect((await _resolve(null)).problem, contains('อ่าน QR ไม่ได้'));
    expect(
      (await _resolve('EVX1.30.40.1.1.sig')).problem,
      contains('กระดาษคำตอบข้อสอบ'),
    );
    expect(
      (await _resolve('EVC1.30.0.1.1.sig')).problem,
      contains('กระดาษคำตอบข้อสอบ'),
    );
    expect(
      (await _resolve('https://example.com')).problem,
      'QR นี้ไม่ใช่ QR ของใบงาน Krucheck',
    );
    expect(
      (await _resolve('EV1.99.40.1.1.sig')).problem,
      contains('ไม่ใช่การบ้านของคุณ'),
    );
    expect((await _resolve('EV1.12.40.1.1.sig')).problem, contains('ฉบับร่าง'));
    expect(
      (await _resolve('EV1.11.77.1.1.sig')).problem,
      contains('ไม่อยู่ในรายชื่อ'),
    );
    // Homework for chosen students only (DESIGN §30.13).
    expect(
      (await _resolve('EV1.13.40.1.1.sig')).problem,
      contains('ไม่อยู่ในรายชื่อ'),
    );
    expect((await _resolve('EV1.13.41.1.1.sig')).target, isNotNull);
  });

  test('pages group per student in page order, a retaken page wins', () {
    final shots = [
      _shot(1, page: 2),
      _shot(2, student: 41),
      _shot(3, page: 1),
      _shot(4, page: 2),
      const WebScanShot(
        id: 5,
        file: PickedDocument(name: 'x', path: 'x'),
      ),
    ];

    final groups = groupWebScanShots(shots);

    expect(groups.map((g) => g.target.studentId), [40, 41]);
    expect(groups.first.pages.map((p) => p.id), [3, 4]);
    expect(groups.first.problem, isNull);
    expect(replacedWebScanShots(shots), {1});
  });

  test('more pages than one hand-in takes is a problem', () {
    final groups = groupWebScanShots([
      for (var p = 1; p <= 6; p++) _shot(p, page: p),
    ]);

    expect(groups.single.problem, contains('ไม่เกิน 5 หน้า'));
  });
}
