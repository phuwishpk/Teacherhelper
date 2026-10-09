import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exam_scan_models.dart';
import 'package:eduvision/features/exams/exam_sheet_scanner.dart';
import 'package:eduvision/features/scan/scan_file_store.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:eduvision/platform/answer_sheet_pipeline.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_scan_fakes.dart';

/// DESIGN §22.19: the shared answer sheet on which the student fills in
/// their student ID. The ID reader (golden fixture shared with the
/// server), the `EVC1` QR, the kit's IDs, and the scanner: queued when the
/// ID names one student, handed back for the teacher to assign otherwise.
void main() {
  group('StudentCodeReader', () {
    test('reads the IDs of the fixture shared with the server', () {
      final data =
          jsonDecode(
                File(
                  'test/fixtures/exam_scoring/codes.json',
                ).readAsStringSync(),
              )
              as Map<String, dynamic>;
      final cases = (data['cases'] as List).cast<Map<String, dynamic>>();
      expect(cases, isNotEmpty);
      for (final c in cases) {
        final read = StudentCodeReader.read(
          DigitFill.fromJson({'sign': null, 'columns': c['columns']}),
        );
        expect(read.code, c['code'], reason: c['name'] as String);
        expect(read.problem, c['problem'], reason: c['name'] as String);
      }
    });

    test('a sheet without the grid reads blank', () {
      final read = StudentCodeReader.read(null);
      expect((read.code, read.problem), (null, StudentCodeReader.blank));
    });
  });

  group('ExamQr', () {
    test('the shared sheet has its own prefix and no student', () {
      final qr = ExamQr.tryParse(sharedQr(page: 2))!;
      expect(qr.shared, isTrue);
      expect(qr.needsStudent, isTrue);
      expect(qr.isKeySheet, isFalse, reason: 'student 0 here is not the key');
      expect((qr.assignmentId, qr.page, qr.layoutVersion), (examId, 2, 1));

      final named = qr.forStudent(12);
      expect(
        (named.studentId, named.shared, named.needsStudent),
        (12, true, false),
      );
      // A shared QR never carries a student; the key sheet is still EVX1 0.
      expect(ExamQr.tryParse('EVC1.$examId.12.1.1.Q2M7K3PA'), isNull);
      expect(ExamQr.tryParse(sheetQr(student: 0))!.isKeySheet, isTrue);
      expect(ExamQr.tryParse(sheetQr())!.shared, isFalse);
    });
  });

  group('scan kit', () {
    test('carries the sheet identity and every student ID', () {
      final kit = sampleKit(codeDigits: 5);
      expect(kit.codeSheets, isTrue);
      expect(kit.studentCodeDigits, 5);
      expect(kit.roster.map((s) => s.studentCode), ['10001', '10002', null]);
      expect(kit.studentByCode('10002')!.studentId, 12);
      expect(kit.studentByCode('99999'), isNull);
      expect(kit.studentsWithoutUsableCode, 1);
      // The grid is sheet number 0 in the layout, not a question.
      expect(kit.sheetNumbersOn(1), {1, 2, 3, 4});

      final plain = sampleKit();
      expect(plain.codeSheets, isFalse);
      expect(plain.studentCodeDigits, isNull);
    });

    test('an ID two students share names nobody', () {
      final json = kitJson(codeDigits: 5);
      (json['roster'] as List)[2]['student_code'] = '10001';
      final kit = ExamScanKit.fromJson(json);
      expect(kit.studentByCode('10001'), isNull);
    });

    test('IDs with letters or longer than the grid cannot be filled in', () {
      final json = kitJson(codeDigits: 5);
      (json['roster'] as List)[0]['student_code'] = 'A1001';
      (json['roster'] as List)[1]['student_code'] = '123456';
      expect(ExamScanKit.fromJson(json).studentsWithoutUsableCode, 3);
    });
  });

  group('exam settings', () {
    test('the number of ID columns follows the longest numeric ID', () {
      expect(suggestStudentCodeDigits(['66010001', '6601', null]), 8);
      expect(suggestStudentCodeDigits(['123']), kExamMinCodeDigits);
      expect(
        suggestStudentCodeDigits(['12345678901234567']),
        kExamMaxCodeDigits,
      );
      expect(suggestStudentCodeDigits(['AB-12', null]), kExamDefaultCodeDigits);
      expect(suggestStudentCodeDigits(const []), kExamDefaultCodeDigits);
    });

    test('the draft sends the identity only when it is set or changed', () {
      final date = DateTime.utc(2026, 10, 15, 2);
      final plain = ExamSettingsDraft(title: 'สอบ', examDate: date);
      expect(plain.toCreateJson().containsKey('sheet_identity'), isFalse);

      final code = ExamSettingsDraft(
        title: 'สอบ',
        examDate: date,
        codeSheets: true,
        studentCodeDigits: 8,
      );
      expect(code.toCreateJson()['sheet_identity'], 'code');
      expect(code.toCreateJson()['student_code_digits'], 8);

      Assignment exam({String identity = 'qr', int? digits}) =>
          Assignment.fromJson({
            'id': 301,
            'classroom_id': 1,
            'title': 'สอบ',
            'kind': 'exam',
            'grading_method': 'app',
            'due_at': date.toIso8601String(),
            'sheet_identity': identity,
            'student_code_digits': digits,
          });
      expect(exam(identity: 'code', digits: 8).usesCodeSheets, isTrue);
      expect(code.toUpdateJson(exam()), {
        'sheet_identity': 'code',
        'student_code_digits': 8,
      });
      expect(code.toUpdateJson(exam(identity: 'code', digits: 8)), isEmpty);
      expect(code.toUpdateJson(exam(identity: 'code', digits: 10)), {
        'sheet_identity': 'code',
        'student_code_digits': 8,
      });
      expect(plain.toUpdateJson(exam(identity: 'code', digits: 8)), {
        'sheet_identity': 'qr',
      });
      expect(plain.toUpdateJson(exam()), isEmpty);
    });
  });

  group('scanner', () {
    late AppDatabase db;
    late ScanQueueRepository queue;
    late Directory tmp;
    late FakeAnswerSheetPipeline pipeline;
    late ExamSheetScanner scanner;
    var ids = 0;

    setUp(() async {
      db = AppDatabase(NativeDatabase.memory());
      queue = ScanQueueRepository(db);
      tmp = await Directory.systemTemp.createTemp('exam_code_sheet_test');
      pipeline = FakeAnswerSheetPipeline(dir: tmp)
        ..detection = detectionFor(sharedQr());
      scanner = ExamSheetScanner(
        pipeline: pipeline,
        queue: queue,
        files: ScanFileStore(() async => Directory('${tmp.path}/store')),
        onQueued: () {},
        clock: () => DateTime.utc(2026, 10, 15, 3),
        newId: () =>
            '11111111-2222-3333-4444-${(++ids).toString().padLeft(12, '0')}',
      );
    });

    tearDown(() async {
      await db.close();
      await tmp.delete(recursive: true);
    });

    Future<String> photo() async {
      final f = File('${tmp.path}/shot.jpg');
      await f.writeAsBytes([9]);
      return f.path;
    }

    final kit = sampleKit(codeDigits: 5);

    test('queues the page for the student the filled-in ID names', () async {
      pipeline.readings.add(
        (w) => readingJson(
          w,
          marks: {1: 1, 2: 2, 3: 1, 4: '12'},
          version: 2,
          code: '10002',
        ),
      );

      final outcome = await scanner.scan(
        await photo(),
        kit,
        pageOneVersion: (_) => null,
        alreadyScanned: (_, _) => false,
      );

      final q = outcome as ExamSheetQueued;
      expect(q.student!.studentId, 12);
      expect(q.identifiedBy, ExamSheetQueued.identifiedByCode);
      expect((q.qr.studentId, q.qr.shared), (12, true));
      expect(q.score!.score, 4);
      final row = (await queue.find(q.clientScanId))!;
      expect(row.meta['qr'], sharedQr());
      expect(row.meta['student_id'], 12);
      expect(row.meta['student_source'], 'code');
      // The grid's fill travels with the page as the block of sheet number 0.
      expect(
        ((row.meta['digits'] as Map)['0'] as Map)['columns'],
        hasLength(5),
      );
      expect(row.meta['device_score'], 4, reason: 'the grid is not scored');
    });

    test('hands the sheet back when the ID cannot be read', () async {
      for (final (code, reason) in [
        ('', StudentCodeReader.blank),
        ('10_02', StudentCodeReader.invalid),
        ('99999', ExamSheetNeedsStudent.unknown),
      ]) {
        pipeline.readings
          ..clear()
          ..add((w) => readingJson(w, code: code));
        final outcome = await scanner.scan(
          await photo(),
          kit,
          pageOneVersion: (_) => null,
        );
        final need = outcome as ExamSheetNeedsStudent;
        expect(need.reason, reason, reason: 'code "$code"');
        expect(need.suggested, isNull);
        expect(need.message, contains('เลือกนักเรียน'));
        expect(await File(need.pending.reading.warpedPagePath).exists(), true);
      }
      expect(
        await queue.listAll(),
        isEmpty,
        reason: 'nothing without a student',
      );
    });

    test('the teacher assigns the sheet; it is sent as their pick', () async {
      pipeline.readings.add((w) => readingJson(w, marks: {1: 3}, version: 1));
      final need =
          await scanner.scan(await photo(), kit, pageOneVersion: (_) => null)
              as ExamSheetNeedsStudent;

      final q = await scanner.assign(
        need.pending,
        kit,
        kit.student(13)!,
        pageOneVersion: (_) => null,
      );

      expect(q.student!.studentId, 13);
      expect(q.identifiedBy, ExamSheetQueued.identifiedByTeacher);
      expect(q.score!.score, 1);
      final row = (await queue.find(q.clientScanId))!;
      expect(row.meta['student_id'], 13);
      expect(row.meta['student_source'], 'teacher');
      expect(await File(row.files['page']!).exists(), isTrue);
      expect(
        await File(need.pending.reading.warpedPagePath).exists(),
        isFalse,
        reason: 'the warped page moved into the queue',
      );
    });

    test('a second page for the same student asks before replacing', () async {
      pipeline.readings.add((w) => readingJson(w, code: '10001'));
      final need =
          await scanner.scan(
                await photo(),
                kit,
                pageOneVersion: (_) => null,
                alreadyScanned: (studentId, page) =>
                    studentId == 11 && page == 1,
              )
              as ExamSheetNeedsStudent;

      expect(need.reason, ExamSheetNeedsStudent.duplicate);
      expect(need.suggested!.studentId, 11);
      expect(need.message, contains('สแกนหน้า 1 แล้ว'));
      // Picking the same student replaces the page, on the ID's word.
      final q = await scanner.assign(
        need.pending,
        kit,
        need.suggested!,
        pageOneVersion: (_) => null,
      );
      expect(q.identifiedBy, ExamSheetQueued.identifiedByCode);
      expect(
        (await queue.find(q.clientScanId))!.meta['student_source'],
        'code',
      );
    });

    test('a discarded sheet leaves no file and no queue row', () async {
      pipeline.readings.add((w) => readingJson(w, code: ''));
      final need =
          await scanner.scan(await photo(), kit, pageOneVersion: (_) => null)
              as ExamSheetNeedsStudent;

      await scanner.discard(need.pending);

      expect(await File(need.pending.reading.warpedPagePath).exists(), false);
      expect(await queue.listAll(), isEmpty);
    });

    test('a sheet of the other kind is refused', () async {
      pipeline.readings.add((w) => readingJson(w, code: '10001'));
      final onPlain = await scanner.scan(
        await photo(),
        sampleKit(),
        pageOneVersion: (_) => null,
      );
      expect(
        (onPlain as ExamSheetRejected).message,
        contains('ใช้กระดาษคำตอบแบบ QR รายคน'),
      );

      pipeline.detection = detectionFor(sheetQr());
      final onCode = await scanner.scan(
        await photo(),
        kit,
        pageOneVersion: (_) => null,
      );
      expect(
        (onCode as ExamSheetRejected).message,
        contains('ใช้กระดาษคำตอบแบบฝนเลขประจำตัว'),
      );
      expect(await queue.listAll(), isEmpty);
    });

    test('the session counts queued shared pages by their student', () async {
      pipeline.readings.add((w) => readingJson(w, code: '10002'));
      await scanner.scan(await photo(), kit, pageOneVersion: (_) => null);

      final session = ExamScanSession(kit)..setQueue(await queue.listAll());

      expect(session.queued, {
        12: {1},
      });
      expect(session.summary().text, 'สแกนแล้ว 1/3 คน ยังขาด เลขที่ 1, 3');
    });
  });
}
