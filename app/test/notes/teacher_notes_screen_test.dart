import 'dart:typed_data';

import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/notes/teacher_notes_screen.dart';
import 'package:eduvision/features/notes/teacher_notes_store.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../assignments/answer_key_fixtures.dart' show FakeDocumentPicker;
import '../helpers/pump_screen.dart';

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 2,
      name: 'ม.4/9',
      gradeLevel: 4,
      academicYear: 2569,
      classCode: 'abc123',
    ),
  ];
}

void main() {
  late AppDatabase db;
  late FakeFirebaseFirestore firestore;

  setUp(() {
    db = AppDatabase(NativeDatabase.memory());
    firestore = FakeFirebaseFirestore();
  });

  Future<void> pump(WidgetTester tester, Size size, {bool cloud = true}) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    var ids = 0;
    await pumpScreen(
      tester,
      const TeacherNotesScreen(),
      overrides: [
        teacherNotesStoreProvider.overrideWith(
          (ref) => TeacherNotesStore(
            userId: 7,
            db: db,
            cloud: cloud ? (db: firestore, uid: 'u7') : null,
            newId: () => 'n${++ids}',
            thumbnail: (_) async => Uint8List.fromList([1]),
            device: 'android',
          ),
        ),
        classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
        documentFilePickerProvider.overrideWithValue(
          FakeDocumentPicker([
            PickedDocument(name: 'board.jpg', bytes: Uint8List(4)),
          ]),
        ),
      ],
    );
  }

  for (final size in const [Size(390, 844), Size(820, 1180), Size(1440, 900)]) {
    testWidgets('a new note is saved here and in Firestore '
        '(${size.width.toInt()} px)', (tester) async {
      await tester.runAsync(() async {
        await pump(tester, size);
        expect(find.text('ยังไม่มีบันทึก'), findsOneWidget);

        await tester.tap(find.byKey(const ValueKey('notes_new')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('note_pick')));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byKey(const ValueKey('note_body')),
          'สอนสมการกำลังสองถึงข้อ 3',
        );
        await tester.ensureVisible(find.byKey(const ValueKey('note_save')));
        await tester.tap(find.byKey(const ValueKey('note_save')));
        await tester.pumpAndSettle();
        await Future<void>.delayed(const Duration(milliseconds: 50));
        await tester.pumpAndSettle();
      });

      expect(find.text('สอนสมการกำลังสองถึงข้อ 3'), findsOneWidget);
      expect(find.text('ซิงก์แล้ว'), findsOneWidget);
      expect(await db.select(db.teacherNotes).get(), hasLength(1));
      final docs = await firestore.collection('users/u7/notes').get();
      expect(docs.docs.single.data()['body'], 'สอนสมการกำลังสองถึงข้อ 3');
      expect(tester.takeException(), isNull);
      await db.close();
    });
  }

  testWidgets('without the live layer a note stays on this device', (
    tester,
  ) async {
    await tester.runAsync(() async {
      await pump(tester, const Size(390, 844), cloud: false);
      await tester.tap(find.byKey(const ValueKey('notes_new')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('note_body')),
        'ออฟไลน์',
      );
      await tester.tap(find.byKey(const ValueKey('note_save')));
      await tester.pumpAndSettle();
      await Future<void>.delayed(const Duration(milliseconds: 50));
      await tester.pumpAndSettle();
    });

    expect(find.text('อยู่ในเครื่องนี้เท่านั้น'), findsOneWidget);
    expect(find.textContaining('ยังไม่ได้เชื่อมข้อมูลสด'), findsOneWidget);
    await db.close();
  });
}
