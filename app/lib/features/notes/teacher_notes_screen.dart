import 'dart:typed_data';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme/breakpoints.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../../core/util/thai_date.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/key_document_sources.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_providers.dart';
import '../scan/scan_camera.dart';
import 'teacher_notes_store.dart';

/// "บันทึกครู" (DESIGN §40): a teacher's photos and short notes, saved on
/// this device first and copied to Firestore for the teacher's other
/// devices. Newest first; a card says where the note is.
class TeacherNotesScreen extends ConsumerStatefulWidget {
  const TeacherNotesScreen({super.key});

  @override
  ConsumerState<TeacherNotesScreen> createState() => _TeacherNotesScreenState();
}

class _TeacherNotesScreenState extends ConsumerState<TeacherNotesScreen> {
  @override
  void initState() {
    super.initState();
    // Whatever waited offline goes up when the screen opens.
    WidgetsBinding.instance.addPostFrameCallback((_) => _sync(quiet: true));
  }

  Future<void> _sync({bool quiet = false}) async {
    final store = ref.read(teacherNotesStoreProvider);
    if (store == null) return;
    final done = await store.syncPending();
    if (!quiet && mounted) {
      showMessage(
        context,
        store.cloud == null
            ? 'ยังไม่ได้เชื่อมข้อมูลสด บันทึกยังอยู่ในเครื่องนี้'
            : done == 0
            ? 'ทุกบันทึกซิงก์แล้ว'
            : 'ซิงก์แล้ว $done รายการ',
      );
    }
  }

  Future<void> _new() async {
    final saved = await Navigator.of(
      context,
    ).push<bool>(MaterialPageRoute(builder: (_) => const TeacherNoteEditor()));
    if (saved == true && mounted) showMessage(context, 'บันทึกแล้ว');
  }

  Future<void> _open(TeacherNote note) async {
    final delete = await showDialog<bool>(
      context: context,
      builder: (context) => _NoteDialog(note: note),
    );
    if (delete != true || !mounted) return;
    final ok = await confirm(
      context,
      title: 'ลบบันทึกนี้?',
      message: 'บันทึกจะถูกลบจากเครื่องนี้และทุกเครื่องที่ซิงก์ไว้',
      confirmLabel: 'ลบ',
      destructive: true,
    );
    if (!ok) return;
    await ref.read(teacherNotesStoreProvider)?.delete(note);
    if (mounted) showMessage(context, 'ลบบันทึกแล้ว');
  }

  @override
  Widget build(BuildContext context) {
    // The store changes when the live layer connects: send what waited.
    ref.listen(teacherNotesStoreProvider, (previous, next) {
      if (previous?.cloud == null && next?.cloud != null) _sync(quiet: true);
    });
    final store = ref.watch(teacherNotesStoreProvider);
    final notes = ref.watch(teacherNotesProvider);
    final theme = Theme.of(context);
    final cloud = store?.cloud != null;
    return Scaffold(
      appBar: AppBar(
        title: const Text('บันทึกครู'),
        actions: [
          if (!kIsWeb)
            IconButton(
              key: const ValueKey('notes_sync'),
              tooltip: 'ซิงก์ตอนนี้',
              onPressed: () => _sync(),
              icon: const Icon(Icons.cloud_sync_outlined),
            ),
        ],
      ),
      floatingActionButton: kIsWeb && !cloud
          ? null
          : FloatingActionButton.extended(
              key: const ValueKey('notes_new'),
              onPressed: _new,
              icon: const Icon(Icons.add_a_photo_outlined),
              label: const Text('บันทึกใหม่'),
            ),
      body: kIsWeb && !cloud
          ? const EmptyView(
              icon: Icons.cloud_off_outlined,
              title: 'ยังไม่ได้เชื่อมข้อมูลสด',
              message:
                  'บนเว็บ บันทึกครูอ่านและเขียนจาก Firestore '
                  'เปิดหน้านี้อีกครั้งเมื่อการ์ด "ข้อมูลสด" ในหน้าตั้งค่าขึ้นว่าเชื่อมแล้ว '
                  'หรือใช้แอป Android ซึ่งเก็บบันทึกในเครื่องได้เลย',
            )
          : AsyncView(
              value: notes,
              onRetry: () => ref.invalidate(teacherNotesProvider),
              data: (list) => CustomScrollView(
                slivers: [
                  SliverPadding(
                    padding: EdgeInsets.fromLTRB(
                      context.pageGutter,
                      12,
                      context.pageGutter,
                      0,
                    ),
                    sliver: SliverToBoxAdapter(
                      child: Text(
                        kIsWeb
                            ? 'บันทึกของคุณจากทุกเครื่อง (Firestore)'
                            : cloud
                            ? 'เก็บในเครื่องนี้ทันที แล้วซิงก์รูปย่อและข้อความไปทุกเครื่องที่เข้าบัญชีนี้ (Firestore)'
                            : 'ยังไม่ได้เชื่อมข้อมูลสด บันทึกเก็บในเครื่องนี้ก่อน แล้วซิงก์เองเมื่อเชื่อมได้',
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                  ),
                  if (list.isEmpty)
                    const SliverFillRemaining(
                      hasScrollBody: false,
                      child: EmptyView(
                        icon: Icons.photo_library_outlined,
                        title: 'ยังไม่มีบันทึก',
                        message:
                            'ถ่ายรูปกระดานหรือสื่อการสอน แล้วจดสั้น ๆ ว่าสอนอะไรไปบ้าง',
                      ),
                    )
                  else
                    SliverPadding(
                      padding: EdgeInsets.all(context.pageGutter),
                      sliver: SliverGrid.builder(
                        gridDelegate:
                            const SliverGridDelegateWithMaxCrossAxisExtent(
                              maxCrossAxisExtent: 420,
                              mainAxisExtent: 300,
                              crossAxisSpacing: 12,
                              mainAxisSpacing: 12,
                            ),
                        itemCount: list.length,
                        itemBuilder: (context, i) => _NoteCard(
                          note: list[i],
                          onTap: () => _open(list[i]),
                        ),
                      ),
                    ),
                  const SliverToBoxAdapter(child: SizedBox(height: 88)),
                ],
              ),
            ),
    );
  }
}

String _when(TeacherNote note) => formatThaiDateTime(note.createdAt);

String _deviceLabel(String? device) => switch (device) {
  'web' => 'เว็บ',
  'ios' => 'iPhone/iPad',
  'android' => 'Android',
  _ => '',
};

class _NoteCard extends StatelessWidget {
  const _NoteCard({required this.note, required this.onTap});

  final TeacherNote note;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final image = note.image;
    return Card(
      key: ValueKey('note_${note.id}'),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: image == null
                  ? ColoredBox(
                      color: scheme.surfaceContainerHighest,
                      child: Icon(
                        Icons.notes_outlined,
                        size: 40,
                        color: scheme.onSurfaceVariant,
                      ),
                    )
                  : _NoteImage(bytes: image),
            ),
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (note.body.isNotEmpty)
                    Text(
                      note.body,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium,
                    ),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 6,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      StatusChip(
                        label: note.sync.label,
                        color: switch (note.sync) {
                          NoteSync.synced => scheme.primary,
                          NoteSync.pending => scheme.tertiary,
                          NoteSync.localOnly => scheme.secondary,
                        },
                      ),
                      if (note.fromCloud && _deviceLabel(note.device) != '')
                        StatusChip(label: 'จาก${_deviceLabel(note.device)}'),
                      Text(
                        [?note.classroomName, _when(note)].join(' · '),
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _NoteImage extends StatelessWidget {
  const _NoteImage({required this.bytes, this.fit = BoxFit.cover});

  final Uint8List bytes;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) => Image.memory(
    bytes,
    fit: fit,
    gaplessPlayback: true,
    errorBuilder: (_, _, _) =>
        const Center(child: Icon(Icons.broken_image_outlined)),
  );
}

/// The whole note; pops true when the teacher asks to delete it.
class _NoteDialog extends StatelessWidget {
  const _NoteDialog({required this.note});

  final TeacherNote note;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final image = note.image;
    return AlertDialog(
      title: Text(note.classroomName ?? 'บันทึกครู'),
      content: SizedBox(
        width: 560,
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              if (image != null) _NoteImage(bytes: image, fit: BoxFit.contain),
              if (image != null && note.photo == null) ...[
                const SizedBox(height: 4),
                Text(
                  'รูปย่อจาก Firestore รูปเต็มอยู่ในเครื่องที่ถ่าย',
                  style: theme.textTheme.bodySmall,
                ),
              ],
              const SizedBox(height: 12),
              if (note.body.isNotEmpty) SelectableText(note.body),
              const SizedBox(height: 8),
              Text(
                '${_when(note)} · ${note.sync.label}',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          key: const ValueKey('note_delete'),
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('ลบ'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('ปิด'),
        ),
      ],
    );
  }
}

/// "บันทึกใหม่": one photo (camera or file), a short text and, if the
/// teacher wants, the classroom. Pops true once saved.
class TeacherNoteEditor extends ConsumerStatefulWidget {
  const TeacherNoteEditor({super.key});

  @override
  ConsumerState<TeacherNoteEditor> createState() => _TeacherNoteEditorState();
}

class _TeacherNoteEditorState extends ConsumerState<TeacherNoteEditor> {
  final _body = TextEditingController();
  Uint8List? _photo;
  Classroom? _classroom;
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _body.dispose();
    super.dispose();
  }

  Future<void> _shoot() async {
    final photos = await Navigator.of(context).push<List<PickedDocument>>(
      MaterialPageRoute(
        builder: (_) => const KeyPhotoScreen(
          title: 'ถ่ายรูปบันทึก',
          hint: 'ถ่ายกระดานหรือสื่อการสอน ไม่ถ่ายหน้าหรือผลงานของนักเรียน',
          maxPhotos: 1,
          namePrefix: 'note',
        ),
      ),
    );
    final photo = photos?.firstOrNull;
    if (photo == null || !mounted) return;
    await _use(photo);
  }

  Future<void> _pick() async {
    final picked = await ref
        .read(documentFilePickerProvider)
        .pick(imagesOnly: true, dialogTitle: 'เลือกรูปบันทึก');
    final photo = picked.firstOrNull;
    if (photo == null || !mounted) return;
    await _use(photo);
  }

  Future<void> _use(PickedDocument photo) async {
    final bytes =
        photo.bytes ?? await ref.read(cameraPhotoBytesProvider)(photo.path!);
    if (mounted) setState(() => _photo = bytes);
  }

  Future<void> _save() async {
    final store = ref.read(teacherNotesStoreProvider);
    if (store == null || _saving) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await store.add(
        body: _body.text,
        classroomId: _classroom?.id,
        classroomName: _classroom?.name,
        photo: _photo,
      );
      if (mounted) Navigator.of(context).pop(true);
    } on NoteStoreException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final classrooms = ref.watch(classroomsProvider).value ?? const [];
    final photo = _photo;
    return Scaffold(
      appBar: AppBar(title: const Text('บันทึกใหม่')),
      body: FormColumn(
        maxWidth: 640,
        children: [
          if (photo != null)
            ConstrainedBox(
              constraints: const BoxConstraints(maxHeight: 320),
              child: _NoteImage(bytes: photo, fit: BoxFit.contain),
            ),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilledButton.tonalIcon(
                key: const ValueKey('note_camera'),
                onPressed: _saving ? null : _shoot,
                icon: const Icon(Icons.photo_camera_outlined),
                label: Text(photo == null ? 'ถ่ายรูป' : 'ถ่ายใหม่'),
              ),
              FilledButton.tonalIcon(
                key: const ValueKey('note_pick'),
                onPressed: _saving ? null : _pick,
                icon: const Icon(Icons.photo_library_outlined),
                label: const Text('เลือกรูป'),
              ),
              if (photo != null)
                TextButton(
                  onPressed: _saving
                      ? null
                      : () => setState(() => _photo = null),
                  child: const Text('ไม่ใส่รูป'),
                ),
            ],
          ),
          const SizedBox(height: 16),
          TextField(
            key: const ValueKey('note_body'),
            controller: _body,
            minLines: 3,
            maxLines: 8,
            maxLength: kNoteBodyMax,
            decoration: const InputDecoration(
              labelText: 'ข้อความ',
              hintText:
                  'เช่น สอนสมการกำลังสองถึงข้อ 3 พรุ่งนี้ทวนการแยกตัวประกอบ',
            ),
          ),
          const SizedBox(height: 8),
          DropdownButtonFormField<int?>(
            key: const ValueKey('note_classroom'),
            initialValue: _classroom?.id,
            decoration: const InputDecoration(
              labelText: 'ห้องเรียน (ไม่บังคับ)',
            ),
            items: [
              const DropdownMenuItem<int?>(value: null, child: Text('ไม่ระบุ')),
              for (final c in classrooms)
                DropdownMenuItem<int?>(value: c.id, child: Text(c.name)),
            ],
            onChanged: _saving
                ? null
                : (id) => setState(
                    () => _classroom = classrooms
                        .where((c) => c.id == id)
                        .firstOrNull,
                  ),
          ),
          const SizedBox(height: 8),
          Text(
            'รูปเต็มเก็บในเครื่องนี้ รูปย่อและข้อความซิงก์ไป Firestore '
            'ของบัญชีคุณ ไม่ถ่ายหน้า ชื่อ หรือผลงานของนักเรียน',
            style: theme.textTheme.bodySmall,
          ),
          if (_error case final error?) ...[
            const SizedBox(height: 12),
            Text(error, style: TextStyle(color: theme.colorScheme.error)),
          ],
          const SizedBox(height: 16),
          FilledButton.icon(
            key: const ValueKey('note_save'),
            onPressed: _saving ? null : _save,
            icon: const Icon(Icons.save_outlined),
            label: const Text('บันทึก'),
          ),
        ],
      ),
    );
  }
}
