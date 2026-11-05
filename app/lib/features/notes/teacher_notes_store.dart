import 'dart:async';
import 'dart:ui' as ui;

import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:drift/drift.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../../core/auth/session.dart';
import '../../core/db/app_database.dart';
import '../../core/db/database_provider.dart';
import '../../core/live/live_scope.dart';

/// Where a note is (DESIGN §40.3).
enum NoteSync {
  /// Only in this device's database: no Firestore to copy it to yet.
  localOnly('อยู่ในเครื่องนี้เท่านั้น'),

  /// In this device's database, its newest version not in Firestore yet.
  pending('รอซิงก์'),

  /// In Firestore (and in this device's database when written here).
  synced('ซิงก์แล้ว');

  const NoteSync(this.label);

  final String label;
}

/// One "บันทึกครู" as the screen shows it: the local row merged with the
/// Firestore document of the same id.
class TeacherNote {
  const TeacherNote({
    required this.id,
    required this.body,
    required this.createdAt,
    required this.updatedAt,
    required this.sync,
    this.classroomId,
    this.classroomName,
    this.photo,
    this.thumb,
    this.device,
  });

  final String id;
  final String body;
  final int? classroomId;
  final String? classroomName;

  /// The photo as taken; only on the device that took it.
  final Uint8List? photo;

  /// The copy in Firestore (PNG, at most [kNoteThumbMaxSide] px).
  final Uint8List? thumb;
  final DateTime createdAt;
  final DateTime updatedAt;
  final NoteSync sync;

  /// `android`, `ios` or `web`: which device wrote it (Firestore only).
  final String? device;

  Uint8List? get image => photo ?? thumb;

  /// True for a note this device only knows from Firestore.
  bool get fromCloud => photo == null && sync == NoteSync.synced;
}

class NoteStoreException implements Exception {
  const NoteStoreException(this.message);

  final String message;

  @override
  String toString() => 'NoteStoreException($message)';
}

/// The most a note's text may hold (Firestore rule, DESIGN §40.4).
const kNoteBodyMax = 2000;

/// The largest thumbnail the Firestore rule accepts.
const kNoteThumbMaxBytes = 300 * 1024;

/// "บันทึกครู" in two places (DESIGN §40): this device's drift database
/// first (works offline, keeps the full photo), then Firestore
/// `users/{uid}/notes/{id}` (text and thumbnail) that every device of the
/// teacher reads. The app writes these documents itself; MariaDB never
/// holds notes. A browser has no drift database and uses Firestore only.
class TeacherNotesStore {
  TeacherNotesStore({
    required this.userId,
    this.db,
    this.cloud,
    DateTime Function()? now,
    String Function()? newId,
    Future<Uint8List?> Function(Uint8List image)? thumbnail,
    String? device,
  }) : _now = now ?? DateTime.now,
       _newId = newId ?? const Uuid().v4,
       _thumbnail = thumbnail ?? makeNoteThumb,
       device = device ?? _deviceName();

  final int userId;
  final AppDatabase? db;
  final ({FirebaseFirestore db, String uid})? cloud;
  final String device;
  final DateTime Function() _now;
  final String Function() _newId;
  final Future<Uint8List?> Function(Uint8List image) _thumbnail;

  CollectionReference<Map<String, dynamic>>? get _collection {
    final c = cloud;
    return c?.db.collection('users/${c.uid}/notes');
  }

  /// Notes newest first: local rows and Firestore documents by id.
  Stream<List<TeacherNote>> watch() {
    final local = db == null
        ? Stream.value(const <TeacherNoteRow>[])
        : _localQuery().watch();
    final collection = _collection;
    final remote = collection == null
        ? Stream.value(const <QueryDocumentSnapshot<Map<String, dynamic>>>[])
        : collection.snapshots().map((s) => s.docs);

    late StreamController<List<TeacherNote>> controller;
    StreamSubscription<Object?>? a;
    StreamSubscription<Object?>? b;
    List<TeacherNoteRow>? rows;
    List<QueryDocumentSnapshot<Map<String, dynamic>>>? docs;
    void emit() {
      if (rows != null && docs != null) controller.add(_merge(rows!, docs!));
    }

    controller = StreamController<List<TeacherNote>>(
      onListen: () {
        a = local.listen((r) {
          rows = r;
          emit();
        }, onError: controller.addError);
        b = remote.listen(
          (d) {
            docs = d;
            emit();
          },
          // Firestore unreachable or refused: the local notes still show.
          onError: (Object _) {
            docs = const [];
            emit();
          },
        );
      },
      onCancel: () async {
        await a?.cancel();
        await b?.cancel();
      },
    );
    return controller.stream;
  }

  /// Saves a note here at once and copies it to Firestore when it can.
  Future<TeacherNote> add({
    required String body,
    int? classroomId,
    String? classroomName,
    Uint8List? photo,
  }) async {
    final text = body.trim();
    if (text.isEmpty && photo == null) {
      throw const NoteStoreException('ใส่รูปหรือข้อความอย่างน้อยหนึ่งอย่าง');
    }
    if (text.length > kNoteBodyMax) {
      throw const NoteStoreException(
        'ข้อความยาวได้ไม่เกิน $kNoteBodyMax ตัวอักษร',
      );
    }
    final database = db;
    if (database == null && _collection == null) {
      throw const NoteStoreException(
        'บนเว็บ บันทึกครูเก็บใน Firestore ซึ่งยังไม่ได้เชื่อม '
        'ลองใหม่เมื่อการ์ด "ข้อมูลสด" ในหน้าตั้งค่าขึ้นว่าเชื่อมแล้ว',
      );
    }
    final thumb = photo == null ? null : await _thumbnail(photo);
    if (photo != null && thumb == null) {
      throw const NoteStoreException(
        'เปิดรูปนี้ไม่ได้ ลองถ่ายหรือเลือกรูปใหม่',
      );
    }
    final now = _now().toUtc();
    final row = TeacherNoteRow(
      id: _newId(),
      userId: userId,
      classroomId: classroomId,
      classroomName: classroomName,
      body: text,
      photo: photo,
      thumb: thumb,
      createdAt: now,
      updatedAt: now,
      deleted: false,
    );
    if (database != null) {
      await database.into(database.teacherNotes).insert(row);
      await syncPending();
    } else {
      await _push(row);
    }
    return _fromRow(row, synced: database == null);
  }

  /// Deletes [note] here and from Firestore; one not yet deleted there is
  /// kept as a tombstone until [syncPending] gets through.
  Future<void> delete(TeacherNote note) async {
    final database = db;
    if (database != null) {
      await (database.update(
        database.teacherNotes,
      )..where((t) => t.id.equals(note.id))).write(
        TeacherNotesCompanion(
          deleted: const Value(true),
          updatedAt: Value(_now().toUtc()),
        ),
      );
      await syncPending();
      return;
    }
    await _collection?.doc(note.id).delete();
  }

  /// Copies every local change Firestore does not have yet. Returns how
  /// many went through; stops quietly when Firestore cannot be reached.
  Future<int> syncPending() async {
    final database = db;
    final collection = _collection;
    if (database == null || collection == null) return 0;
    final rows = await (database.select(
      database.teacherNotes,
    )..where((t) => t.userId.equals(userId))).get();
    var done = 0;
    for (final row in rows) {
      final synced = row.syncedAt;
      final current = synced != null && !synced.isBefore(row.updatedAt);
      try {
        if (row.deleted) {
          if (synced != null) await collection.doc(row.id).delete();
          await (database.delete(
            database.teacherNotes,
          )..where((t) => t.id.equals(row.id))).go();
        } else if (!current) {
          await _push(row);
          await (database.update(database.teacherNotes)
                ..where((t) => t.id.equals(row.id)))
              .write(TeacherNotesCompanion(syncedAt: Value(row.updatedAt)));
        } else {
          continue;
        }
        done++;
      } catch (e) {
        debugPrint('Teacher notes: sync stopped: $e');
        break;
      }
    }
    return done;
  }

  Future<void> _push(TeacherNoteRow row) async {
    final collection = _collection;
    if (collection == null) return;
    await collection.doc(row.id).set({
      'body': row.body,
      'classroom_id': row.classroomId,
      'classroom_name': row.classroomName,
      'thumb': row.thumb == null ? null : Blob(row.thumb!),
      'device': device,
      'created_at': Timestamp.fromDate(row.createdAt),
      'updated_at': Timestamp.fromDate(row.updatedAt),
    });
  }

  SimpleSelectStatement<$TeacherNotesTable, TeacherNoteRow> _localQuery() {
    final database = db!;
    return database.select(database.teacherNotes)
      ..where((t) => t.userId.equals(userId) & t.deleted.equals(false));
  }

  List<TeacherNote> _merge(
    List<TeacherNoteRow> rows,
    List<QueryDocumentSnapshot<Map<String, dynamic>>> docs,
  ) {
    final byId = <String, TeacherNote>{};
    for (final d in docs) {
      final note = _fromDoc(d.id, d.data());
      if (note != null) byId[d.id] = note;
    }
    for (final row in rows) {
      // Local rows win: they carry the full photo and the newest edit.
      byId[row.id] = _fromRow(row);
    }
    return byId.values.toList()
      ..sort((x, y) => y.createdAt.compareTo(x.createdAt));
  }

  TeacherNote _fromRow(TeacherNoteRow row, {bool synced = false}) {
    final at = row.syncedAt;
    return TeacherNote(
      id: row.id,
      body: row.body,
      classroomId: row.classroomId,
      classroomName: row.classroomName,
      photo: row.photo,
      thumb: row.thumb,
      createdAt: row.createdAt,
      updatedAt: row.updatedAt,
      device: device,
      sync: synced || (at != null && !at.isBefore(row.updatedAt))
          ? NoteSync.synced
          : _collection == null
          ? NoteSync.localOnly
          : NoteSync.pending,
    );
  }

  static TeacherNote? _fromDoc(String id, Map<String, dynamic> data) {
    final created = data['created_at'];
    final updated = data['updated_at'];
    if (created is! Timestamp) return null;
    final thumb = data['thumb'];
    final classroomId = data['classroom_id'];
    return TeacherNote(
      id: id,
      body: data['body'] as String? ?? '',
      classroomId: classroomId is num ? classroomId.toInt() : null,
      classroomName: data['classroom_name'] as String?,
      thumb: thumb is Blob ? thumb.bytes : null,
      createdAt: created.toDate().toUtc(),
      updatedAt: (updated is Timestamp ? updated : created).toDate().toUtc(),
      device: data['device'] as String?,
      sync: NoteSync.synced,
    );
  }

  static String _deviceName() {
    if (kIsWeb) return 'web';
    return switch (defaultTargetPlatform) {
      TargetPlatform.iOS => 'ios',
      _ => 'android',
    };
  }
}

/// A PNG of [image] whose longest side is at most [kNoteThumbMaxSide] px
/// and whose size fits the Firestore rule (smaller again if it does not),
/// or null when [image] cannot be decoded.
Future<Uint8List?> makeNoteThumb(Uint8List image) async {
  for (final side in const [kNoteThumbMaxSide, 240, 160]) {
    final png = await _scaledPng(image, side);
    if (png == null) return null;
    if (png.length <= kNoteThumbMaxBytes) return png;
  }
  return null;
}

Future<Uint8List?> _scaledPng(Uint8List image, int maxSide) async {
  ui.Codec? codec;
  ui.Image? frame;
  try {
    final buffer = await ui.ImmutableBuffer.fromUint8List(image);
    codec = await ui.instantiateImageCodecWithSize(
      buffer,
      getTargetSize: (width, height) {
        final longest = width > height ? width : height;
        if (longest <= maxSide) return ui.TargetImageSize();
        final scale = maxSide / longest;
        return ui.TargetImageSize(
          width: (width * scale).round(),
          height: (height * scale).round(),
        );
      },
    );
    frame = (await codec.getNextFrame()).image;
    final data = await frame.toByteData(format: ui.ImageByteFormat.png);
    return data?.buffer.asUint8List();
  } catch (_) {
    return null;
  } finally {
    frame?.dispose();
    codec?.dispose();
  }
}

/// The signed-in teacher's notes store; null when nobody is signed in.
/// Firestore joins when the live layer is connected (DESIGN §38.6).
final teacherNotesStoreProvider = Provider.autoDispose<TeacherNotesStore?>((
  ref,
) {
  final user = ref.watch(currentUserProvider);
  if (user == null) return null;
  return TeacherNotesStore(
    userId: user.id,
    db: kIsWeb ? null : ref.watch(appDatabaseProvider),
    cloud: liveUserScope(ref),
  );
});

final teacherNotesProvider = StreamProvider.autoDispose<List<TeacherNote>>((
  ref,
) {
  final store = ref.watch(teacherNotesStoreProvider);
  return store == null ? Stream.value(const []) : store.watch();
});
