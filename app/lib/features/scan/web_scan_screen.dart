import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/theme/breakpoints.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/assignments_providers.dart';
import '../assignments/key_document_sources.dart';
import '../classrooms/classrooms_providers.dart';
import '../hand_in/hand_in_repository.dart';
import '../hand_in/teacher_upload_screen.dart';
import 'qr_image_reader.dart';
import 'scan_camera.dart';
import 'web_scan.dart';

/// "สแกนใบงาน" in a browser (DESIGN §25.6): the phone's or tablet's camera
/// takes page after page, the worksheet QR in each photo says whose page it
/// is, and "ส่งตรวจ" hands each student's pages in through the whole-page
/// path (`POST /assignments/{id}/students/{studentId}/pages`), the same one
/// as "อัปโหลดรูปเพื่อตรวจ". No marker, crop or local database: those need
/// the native pipeline of the apps. Photos whose QR cannot be used go to
/// "อัปโหลดรูปเพื่อตรวจ", where the teacher picks the student.
class WebScanScreen extends ConsumerStatefulWidget {
  const WebScanScreen({super.key});

  @override
  ConsumerState<WebScanScreen> createState() => _WebScanScreenState();
}

/// What happened to one student's pages after "ส่งตรวจ".
class _Sent {
  const _Sent(this.target, this.pages, this.message);

  final WebScanTarget target;
  final int pages;
  final String message;
}

class _WebScanScreenState extends ConsumerState<WebScanScreen> {
  ScanCamera? _camera;
  bool _ready = false;
  String? _cameraError;

  /// Non-null while a photo is taken or its QR read.
  String? _working;

  /// What the last photo was, above the shutter (a snack bar would cover
  /// it while the teacher takes the next page).
  ({String text, bool problem})? _last;
  bool _sending = false;
  int _nextId = 0;
  final _shots = <WebScanShot>[];
  final _errors = <(int, int), String>{};
  final _sent = <_Sent>[];

  /// Keeps each roster a photo needed alive (the providers dispose
  /// themselves without a listener) until the screen closes.
  final _rosters = <int, ProviderSubscription<Object?>>{};

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _open();
    });
  }

  @override
  void dispose() {
    _camera?.dispose();
    for (final s in _rosters.values) {
      s.close();
    }
    super.dispose();
  }

  Future<void> _open() async {
    final camera = ref.read(webScanCameraFactoryProvider)();
    _camera = camera;
    try {
      await camera.initialize();
      if (mounted) setState(() => _ready = true);
    } catch (e) {
      await camera.dispose();
      if (!mounted) return;
      _camera = null;
      setState(
        () => _cameraError = e is ScanCameraException
            ? e.message
            : 'เปิดกล้องบนเครื่องนี้ไม่ได้ อนุญาตให้เว็บนี้ใช้กล้องในเบราว์เซอร์ '
                  'แล้วเปิดหน้านี้อีกครั้ง หรือเลือกรูปจากเครื่องแทน',
      );
    }
  }

  Future<void> _shoot() async {
    final camera = _camera;
    if (camera == null || _working != null) return;
    setState(() => _working = 'กำลังถ่าย…');
    try {
      final path = await camera.takePicture();
      final bytes = await ref.read(cameraPhotoBytesProvider)(path);
      await _add(PickedDocument(name: 'photo.jpg', bytes: bytes));
    } on ScanCameraException catch (e) {
      if (mounted) showMessage(context, e.message);
    } catch (_) {
      if (mounted) showMessage(context, 'ถ่ายรูปไม่สำเร็จ ลองอีกครั้ง');
    } finally {
      if (mounted) setState(() => _working = null);
    }
  }

  Future<void> _pick() async {
    if (_working != null) return;
    final picked = await ref
        .read(documentFilePickerProvider)
        .pick(imagesOnly: true, dialogTitle: 'เลือกรูปใบงาน');
    if (!mounted || picked.isEmpty) return;
    try {
      for (var i = 0; i < picked.length; i++) {
        setState(() => _working = 'กำลังอ่าน QR ${i + 1}/${picked.length}…');
        final f = picked[i];
        if (f.bytes != null) await _add(f);
      }
    } finally {
      if (mounted) setState(() => _working = null);
    }
  }

  /// Reads the QR of [photo] and adds it to the batch.
  Future<void> _add(PickedDocument photo) async {
    if (mounted) setState(() => _working = 'กำลังอ่าน QR…');
    final payload = await ref.read(qrImageReaderProvider).read(photo.bytes!);
    final resolved = await resolveWebScanQr(
      payload,
      assignments: await ref.read(assignmentsProvider.future),
      roster: (id) {
        _rosters[id] ??= ref.listenManual(rosterProvider(id), (_, _) {});
        return ref.read(rosterProvider(id).future);
      },
    );
    if (!mounted) return;
    final id = ++_nextId;
    final t = resolved.target;
    final shot = WebScanShot(
      id: id,
      file: PickedDocument(
        name: t == null ? 'photo-$id.jpg' : 'page-${t.page}-$id.jpg',
        bytes: photo.bytes,
      ),
      target: t,
      problem: resolved.problem,
    );
    setState(() {
      _shots.add(shot);
      if (t != null) _errors.remove(t.key);
      _last = t == null
          ? (text: resolved.problem!, problem: true)
          : (
              text:
                  'ได้หน้า ${t.page} ของเลขที่ ${t.studentNumber} ${t.studentName}',
              problem: false,
            );
    });
  }

  void _removeGroup(WebScanGroup g) => setState(() {
    _shots.removeWhere((s) => s.target?.key == g.target.key);
    _errors.remove(g.target.key);
  });

  void _removeShot(WebScanShot shot) =>
      setState(() => _shots.removeWhere((s) => s.id == shot.id));

  Future<void> _send() async {
    final groups = groupWebScanShots(
      _shots,
    ).where((g) => g.problem == null).toList();
    if (groups.isEmpty || _sending) return;
    setState(() => _sending = true);
    final repository = ref.read(handInRepositoryProvider);
    for (final g in groups) {
      final key = g.target.key;
      try {
        final result = await repository.uploadForStudent(
          g.target.assignmentId,
          g.target.studentId,
          [for (final p in g.pages) p.file],
        );
        if (!mounted) return;
        ref.invalidate(handedInProvider(g.target.assignmentId));
        setState(() {
          _shots.removeWhere((s) => s.target?.key == key);
          _errors.remove(key);
          _sent.insert(
            0,
            _Sent(
              g.target,
              g.pages.length,
              result.regradePending
                  ? 'รับงานใหม่แล้ว รอครูกดตรวจในหน้าตรวจทาน'
                  : result.waitingKey
                  ? 'เก็บงานไว้แล้ว จะตรวจเมื่ออนุมัติเฉลย'
                  : 'ส่งตรวจแล้ว',
            ),
          );
        });
      } catch (e) {
        if (mounted) setState(() => _errors[key] = apiErrorMessage(e));
      }
    }
    if (!mounted) return;
    ref.invalidate(assignmentsProvider);
    setState(() => _sending = false);
  }

  Future<void> _uploadUnread(List<WebScanShot> unread) async {
    setState(() => _shots.removeWhere((s) => unread.any((u) => u.id == s.id)));
    await context.push(
      AppRoutes.teacherUpload,
      extra: TeacherUploadArgs(files: [for (final s in unread) s.file]),
    );
  }

  Future<void> _confirmLeave() async {
    final leave = await confirm(
      context,
      title: 'ยังไม่ได้ส่งตรวจ',
      message:
          'มีรูปที่ถ่ายไว้ ${_shots.length} รูปที่ยังไม่ได้ส่ง ถ้าออกจากหน้านี้ รูปเหล่านี้จะหายไป',
      confirmLabel: 'ออกจากหน้านี้',
      destructive: true,
    );
    if (leave && mounted) {
      setState(_shots.clear);
      context.pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    // Read by every photo: kept loaded while the screen is open.
    ref.watch(assignmentsProvider);
    final wide = context.windowSize != WindowSize.compact;
    final groups = groupWebScanShots(_shots);
    final ready = groups.where((g) => g.problem == null).length;
    final camera = _camera;
    final preview = _cameraError != null
        ? _CameraProblem(message: _cameraError!, onPick: _pick)
        : !_ready || camera == null
        ? const Center(child: CircularProgressIndicator.adaptive())
        : Center(
            child: AspectRatio(
              aspectRatio: camera.previewAspectRatio,
              child: camera.buildPreview(),
            ),
          );
    final controls = _Controls(
      working: _working,
      last: _last,
      canShoot: _ready && _working == null && !_sending,
      canPick: _working == null && !_sending,
      ready: ready,
      sending: _sending,
      onShoot: _shoot,
      onPick: _pick,
      onSend: _send,
    );
    final batch = _Batch(
      groups: groups,
      unread: [
        for (final s in _shots)
          if (s.target == null) s,
      ],
      errors: _errors,
      sent: _sent,
      sending: _sending,
      onRemoveGroup: _removeGroup,
      onRemoveShot: _removeShot,
      onUploadUnread: _uploadUnread,
    );
    return PopScope(
      canPop: _shots.isEmpty,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _confirmLeave();
      },
      child: Scaffold(
        appBar: AppBar(title: const Text('สแกนใบงานด้วยกล้อง')),
        body: wide
            ? Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(
                    flex: 3,
                    child: Column(
                      children: [
                        Expanded(child: preview),
                        controls,
                      ],
                    ),
                  ),
                  const VerticalDivider(width: 1),
                  SizedBox(width: 400, child: batch),
                ],
              )
            : Column(
                children: [
                  Expanded(flex: 3, child: preview),
                  controls,
                  const Divider(height: 1),
                  Expanded(flex: 2, child: batch),
                ],
              ),
      ),
    );
  }
}

class _CameraProblem extends StatelessWidget {
  const _CameraProblem({required this.message, required this.onPick});

  final String message;
  final VoidCallback onPick;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: SingleChildScrollView(
        padding: EdgeInsets.all(context.pageGutter),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.no_photography_outlined,
              size: 48,
              color: theme.colorScheme.error,
            ),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 16),
            FilledButton.tonalIcon(
              key: const ValueKey('web_scan_pick_instead'),
              onPressed: onPick,
              icon: const Icon(Icons.photo_library_outlined),
              label: const Text('เลือกรูปจากเครื่องแทน'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Controls extends StatelessWidget {
  const _Controls({
    required this.working,
    required this.last,
    required this.canShoot,
    required this.canPick,
    required this.ready,
    required this.sending,
    required this.onShoot,
    required this.onPick,
    required this.onSend,
  });

  final String? working;
  final ({String text, bool problem})? last;
  final bool canShoot;
  final bool canPick;
  final int ready;
  final bool sending;
  final VoidCallback onShoot;
  final VoidCallback onPick;
  final VoidCallback onSend;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SafeArea(
      top: false,
      bottom: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          context.pageGutter,
          12,
          context.pageGutter,
          12,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              working ??
                  last?.text ??
                  'วางใบงานให้เห็นทั้งหน้าและ QR ชัด แล้วกด "ถ่าย" ทีละหน้า '
                      'ระบบอ่าน QR เองว่าเป็นงานของใคร',
              key: const ValueKey('web_scan_status'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: working == null && (last?.problem ?? false)
                    ? theme.colorScheme.error
                    : null,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 12,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                TextButton.icon(
                  key: const ValueKey('web_scan_pick'),
                  onPressed: canPick ? onPick : null,
                  icon: const Icon(Icons.photo_library_outlined),
                  label: const Text('เลือกรูป'),
                ),
                FilledButton.icon(
                  key: const ValueKey('web_scan_shutter'),
                  onPressed: canShoot ? onShoot : null,
                  icon: const Icon(Icons.photo_camera),
                  label: const Text('ถ่าย'),
                ),
                FilledButton.tonalIcon(
                  key: const ValueKey('web_scan_send'),
                  onPressed: ready > 0 && !sending ? onSend : null,
                  icon: sending
                      ? const SizedBox.square(
                          dimension: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.send),
                  label: Text(ready > 0 ? 'ส่งตรวจ $ready คน' : 'ส่งตรวจ'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Batch extends StatelessWidget {
  const _Batch({
    required this.groups,
    required this.unread,
    required this.errors,
    required this.sent,
    required this.sending,
    required this.onRemoveGroup,
    required this.onRemoveShot,
    required this.onUploadUnread,
  });

  final List<WebScanGroup> groups;
  final List<WebScanShot> unread;
  final Map<(int, int), String> errors;
  final List<_Sent> sent;
  final bool sending;
  final ValueChanged<WebScanGroup> onRemoveGroup;
  final ValueChanged<WebScanShot> onRemoveShot;
  final ValueChanged<List<WebScanShot>> onUploadUnread;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    if (groups.isEmpty && unread.isEmpty && sent.isEmpty) {
      return Center(
        child: Padding(
          padding: EdgeInsets.all(context.pageGutter),
          child: Text(
            'ยังไม่ได้ถ่าย งานที่ถ่ายแล้วจะแสดงที่นี่ แยกตามนักเรียน',
            style: theme.textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
        ),
      );
    }
    return ListView(
      padding: EdgeInsets.all(context.pageGutter),
      children: [
        for (final g in groups)
          Card(
            key: ValueKey(
              'web_scan_group_${g.target.assignmentId}_${g.target.studentId}',
            ),
            child: ListTile(
              leading: CircleAvatar(child: Text('${g.target.studentNumber}')),
              title: Text(g.target.studentName),
              subtitle: Text(
                [
                  g.target.assignmentTitle,
                  'หน้า ${g.pages.map((p) => p.target!.page).join(', ')}',
                  ?g.problem,
                  ?errors[g.target.key],
                ].join(' · '),
                style: g.problem != null || errors.containsKey(g.target.key)
                    ? TextStyle(color: scheme.error)
                    : null,
              ),
              trailing: IconButton(
                tooltip: 'ลบงานของคนนี้',
                onPressed: sending ? null : () => onRemoveGroup(g),
                icon: const Icon(Icons.delete_outline),
              ),
            ),
          ),
        if (unread.isNotEmpty) ...[
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                'ใช้ QR ไม่ได้ ${unread.length} รูป',
                style: theme.textTheme.titleSmall,
              ),
              TextButton.icon(
                key: const ValueKey('web_scan_upload_unread'),
                onPressed: sending ? null : () => onUploadUnread(unread),
                icon: const Icon(Icons.person_search_outlined),
                label: const Text('ส่งแบบเลือกนักเรียนเอง'),
              ),
            ],
          ),
          for (final s in unread)
            Card(
              key: ValueKey('web_scan_unread_${s.id}'),
              child: ListTile(
                leading: Image.memory(
                  s.file.bytes!,
                  width: 40,
                  height: 40,
                  fit: BoxFit.cover,
                  // A format the browser cannot draw (HEIC) still lists.
                  errorBuilder: (_, _, _) => const SizedBox.square(
                    dimension: 40,
                    child: Icon(Icons.image_outlined),
                  ),
                ),
                title: Text(s.problem ?? ''),
                trailing: IconButton(
                  tooltip: 'ลบรูปนี้',
                  onPressed: sending ? null : () => onRemoveShot(s),
                  icon: const Icon(Icons.delete_outline),
                ),
              ),
            ),
        ],
        if (sent.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text('ส่งแล้ว ${sent.length} คน', style: theme.textTheme.titleSmall),
          for (final s in sent)
            ListTile(
              dense: true,
              leading: Icon(Icons.task_alt, color: scheme.primary),
              title: Text(
                'เลขที่ ${s.target.studentNumber} ${s.target.studentName}',
              ),
              subtitle: Text(
                '${s.target.assignmentTitle} · ${s.pages} หน้า · ${s.message}',
              ),
            ),
        ],
      ],
    );
  }
}
