import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/notes/teacher_notes_store.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter_test/flutter_test.dart';

final _photo = Uint8List.fromList([1, 2, 3, 4]);
final _thumb = Uint8List.fromList([9, 9]);

void main() {
  late AppDatabase db;
  late FakeFirebaseFirestore firestore;
  var clock = DateTime.utc(2026, 10, 27, 3);
  var ids = 0;

  setUp(() {
    db = AppDatabase(NativeDatabase.memory());
    firestore = FakeFirebaseFirestore();
    clock = DateTime.utc(2026, 10, 27, 3);
    ids = 0;
  });
  tearDown(() => db.close());

  TeacherNotesStore store({
    int userId = 7,
    bool local = true,
    bool cloud = true,
    String device = 'android',
  }) => TeacherNotesStore(
    userId: userId,
    db: local ? db : null,
    cloud: cloud ? (db: firestore, uid: 'u$userId') : null,
    now: () => clock,
    newId: () => 'n${++ids}',
    thumbnail: (_) async => _thumb,
    device: device,
  );

  CollectionReference<Map<String, dynamic>> notes(int userId) =>
      firestore.collection('users/u$userId/notes');

  test('offline the note stays here, and goes to Firestore once it '
      'can (DESIGN §40.3)', () async {
    final offline = store(cloud: false);
    await offline.add(body: 'สอนถึงข้อ 3', photo: _photo, classroomId: 2);

    var listed = await offline.watch().first;
    expect(listed.single.sync, NoteSync.localOnly);
    expect(listed.single.photo, _photo);
    expect((await notes(7).get()).docs, isEmpty);

    final online = store();
    expect(await online.syncPending(), 1);

    final doc = await notes(7).doc('n1').get();
    expect(doc.data()!['body'], 'สอนถึงข้อ 3');
    expect((doc.data()!['thumb'] as Blob).bytes, _thumb);
    expect(doc.data()!['classroom_id'], 2);
    expect(doc.data()!['device'], 'android');
    expect(doc.data()!.containsKey('photo'), isFalse);
    listed = await online.watch().first;
    expect(listed.single.sync, NoteSync.synced);
    expect(listed.single.photo, _photo, reason: 'the full photo stays here');
    expect(await online.syncPending(), 0);
  });

  test('a note from another device is read from Firestore', () async {
    await store(device: 'web', local: false).add(body: 'จากคอม');
    clock = clock.add(const Duration(minutes: 1));
    await store().add(body: 'จากมือถือ', photo: _photo);

    final listed = await store().watch().first;

    expect(listed.map((n) => n.body), ['จากมือถือ', 'จากคอม']);
    expect(listed.last.fromCloud, isTrue);
    expect(listed.last.device, 'web');
    expect(listed.first.fromCloud, isFalse);
  });

  test('delete removes the note in both places, after reconnecting when '
      'offline', () async {
    final online = store();
    final note = await online.add(body: 'ลบได้');
    expect((await notes(7).get()).docs, hasLength(1));

    await store(cloud: false).delete(note);
    expect(await store(cloud: false).watch().first, isEmpty);
    expect((await notes(7).get()).docs, hasLength(1), reason: 'tombstone');

    await online.syncPending();
    expect((await notes(7).get()).docs, isEmpty);
    expect(await db.select(db.teacherNotes).get(), isEmpty);
  });

  test('each teacher on a shared device sees only their own notes', () async {
    await store(userId: 7, cloud: false).add(body: 'ครู 7');
    await store(userId: 8, cloud: false).add(body: 'ครู 8');

    expect((await store(userId: 8).watch().first).map((n) => n.body), [
      'ครู 8',
    ]);
    await store(userId: 8).syncPending();
    expect((await notes(7).get()).docs, isEmpty);
  });

  test('a browser writes Firestore only, and needs it', () async {
    await store(local: false, device: 'web').add(body: 'บนเว็บ');
    expect((await notes(7).get()).docs.single.data()['device'], 'web');
    expect(await db.select(db.teacherNotes).get(), isEmpty);

    expect(
      () => store(local: false, cloud: false).add(body: 'x'),
      throwsA(isA<NoteStoreException>()),
    );
  });

  test('an empty note or a too long text is refused', () async {
    expect(() => store().add(body: '  '), throwsA(isA<NoteStoreException>()));
    expect(
      () => store().add(body: 'ก' * (kNoteBodyMax + 1)),
      throwsA(isA<NoteStoreException>()),
    );
  });

  testWidgets('the thumbnail is a small PNG within the rule\'s limit', (
    tester,
  ) async {
    final result = await tester.runAsync(() async {
      final recorder = ui.PictureRecorder();
      final canvas = ui.Canvas(recorder);
      for (var i = 0; i < 40; i++) {
        canvas.drawRect(
          ui.Rect.fromLTWH(i * 50.0, 0, 50, 1500),
          ui.Paint()..color = ui.Color(0xFF000000 + i * 0x060504),
        );
      }
      final image = await recorder.endRecording().toImage(2000, 1500);
      final png = await image.toByteData(format: ui.ImageByteFormat.png);
      image.dispose();
      final thumb = await makeNoteThumb(png!.buffer.asUint8List());
      final codec = await ui.instantiateImageCodec(thumb!);
      final frame = (await codec.getNextFrame()).image;
      return (thumb.length, frame.width, frame.height);
    });
    final (bytes, width, height) = result!;
    expect(bytes, lessThanOrEqualTo(kNoteThumbMaxBytes));
    expect(width, kNoteThumbMaxSide);
    expect(height, 270);
  });
}
