import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/content_column.dart';
import 'classroom.dart';
import 'classrooms_providers.dart';

/// Close, reopen and delete a classroom (DESIGN §24.6): the homeroom
/// teacher's menu on the classroom page.
class ClassroomMenu extends ConsumerWidget {
  const ClassroomMenu({super.key, required this.classroom});

  final Classroom classroom;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return PopupMenuButton<String>(
      key: const ValueKey('classroom_menu'),
      tooltip: 'ตัวเลือกห้อง',
      onSelected: (v) => switch (v) {
        'close' => closeClassroom(context, ref, classroom),
        'reopen' => reopenClassroom(context, ref, classroom),
        'delete' => deleteClassroom(context, ref, classroom),
        'copy' => context.push(AppRoutes.studentsFromClassroom(classroom.id)),
        _ => null,
      },
      itemBuilder: (context) => [
        if (!classroom.isClosed)
          const PopupMenuItem(
            value: 'copy',
            child: ListTile(
              leading: Icon(Icons.move_up_outlined),
              title: Text('นำนักเรียนจากห้องเดิม'),
            ),
          ),
        if (classroom.isClosed)
          const PopupMenuItem(
            value: 'reopen',
            child: ListTile(
              leading: Icon(Icons.lock_open_outlined),
              title: Text('เปิดห้องอีกครั้ง'),
            ),
          )
        else
          const PopupMenuItem(
            value: 'close',
            child: ListTile(
              leading: Icon(Icons.inventory_2_outlined),
              title: Text('ปิดห้อง (ย้ายไปห้องเก่า)'),
            ),
          ),
        const PopupMenuItem(
          value: 'delete',
          child: ListTile(
            leading: Icon(Icons.delete_outline),
            title: Text('ลบห้อง'),
          ),
        ),
      ],
    );
  }
}

/// `POST /classrooms/{id}/close` after a confirmation.
Future<void> closeClassroom(
  BuildContext context,
  WidgetRef ref,
  Classroom classroom,
) async {
  final ok = await confirm(
    context,
    title: 'ปิดห้อง ${classroom.name}?',
    message:
        'ห้องจะย้ายไป "ห้องเก่า" ยังดูผล สมุดคะแนน และส่งออกได้ แต่สร้างการบ้าน สแกน '
        'กรอกคะแนน หรือแก้รายชื่อไม่ได้ นักเรียนยังเข้าสู่ระบบด้วยรหัสห้องนี้ได้ '
        'และเปิดห้องอีกครั้งได้ภายหลัง',
    confirmLabel: 'ปิดห้อง',
  );
  if (!ok || !context.mounted) return;
  try {
    await ref.read(classroomsProvider.notifier).closeRoom(classroom.id);
    ref.invalidate(classroomProvider(classroom.id));
    if (context.mounted) {
      showMessage(context, 'ย้าย ${classroom.name} ไปห้องเก่าแล้ว');
    }
  } catch (e) {
    if (context.mounted) showMessage(context, apiErrorMessage(e));
  }
}

/// `POST /classrooms/{id}/reopen`: for a room closed by mistake.
Future<void> reopenClassroom(
  BuildContext context,
  WidgetRef ref,
  Classroom classroom,
) async {
  final ok = await confirm(
    context,
    title: 'เปิดห้อง ${classroom.name} อีกครั้ง?',
    message: 'ห้องจะกลับไปอยู่ใน "ห้องเรียนของฉัน" และแก้ไขได้ตามปกติ',
    confirmLabel: 'เปิดห้อง',
  );
  if (!ok || !context.mounted) return;
  try {
    await ref.read(classroomsProvider.notifier).reopenRoom(classroom.id);
    ref.invalidate(classroomProvider(classroom.id));
    if (context.mounted) {
      showMessage(context, 'เปิดห้อง ${classroom.name} อีกครั้งแล้ว');
    }
  } catch (e) {
    if (context.mounted) showMessage(context, apiErrorMessage(e));
  }
}

/// `DELETE /classrooms/{id}`. The server allows it only while the room has
/// no submission, gradebook entry or publication; otherwise the answer
/// (409 `classroom_has_data` with `counts`) says what is in the way and
/// the teacher can close the room instead (DESIGN §24.6, §24.19).
Future<void> deleteClassroom(
  BuildContext context,
  WidgetRef ref,
  Classroom classroom,
) async {
  final ok = await confirm(
    context,
    title: 'ลบห้อง ${classroom.name}?',
    message:
        'ลบได้เฉพาะห้องที่ยังไม่มีงานที่ส่งหรือคะแนน การบ้านที่ยังไม่มีงานส่ง รายชื่อในห้อง '
        'และการผูกรายวิชาจะหายไปด้วย บัญชีนักเรียนยังอยู่ ลบแล้วย้อนกลับไม่ได้',
    confirmLabel: 'ลบห้อง',
    destructive: true,
  );
  if (!ok || !context.mounted) return;
  try {
    await ref.read(classroomsProvider.notifier).deleteRoom(classroom.id);
    if (!context.mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    if (context.canPop()) context.pop();
    messenger
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text('ลบห้อง ${classroom.name} แล้ว')));
  } catch (e) {
    if (!context.mounted) return;
    if (apiErrorCode(e) != 'classroom_has_data') {
      showMessage(context, apiErrorMessage(e));
      return;
    }
    final closeInstead = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        key: const ValueKey('classroom_has_data'),
        title: const Text('ลบห้องนี้ไม่ได้'),
        content: Text(
          [
            apiErrorMessage(e),
            ...classroomDataLines(apiErrorBody(e)?['counts']),
          ].join('\n'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('ตกลง'),
          ),
          if (!classroom.isClosed)
            FilledButton(
              onPressed: () => Navigator.of(context).pop(true),
              child: const Text('ปิดห้องแทน'),
            ),
        ],
      ),
    );
    if (closeInstead == true && context.mounted) {
      await closeClassroom(context, ref, classroom);
    }
  }
}

/// The `counts` of 409 `classroom_has_data` as Thai lines.
List<String> classroomDataLines(Object? counts) {
  if (counts is! Map) return const [];
  int n(String key) => counts[key] is num ? (counts[key] as num).toInt() : 0;
  return [
    if (n('submissions') > 0) '• งานที่ส่งแล้ว ${n('submissions')} ชิ้น',
    if (n('gradebook_entries') > 0)
      '• คะแนนในสมุดคะแนน ${n('gradebook_entries')} ช่อง',
    if (n('gradebook_special_grades') > 0)
      '• ร/มส ${n('gradebook_special_grades')} รายการ',
    if (n('gradebook_publications') > 0)
      '• ประกาศเกรดแล้ว ${n('gradebook_publications')} ครั้ง',
  ];
}

/// "ห้องเก่า อ่านอย่างเดียว" on a closed room's page (DESIGN §24.13).
class ClosedClassroomBanner extends ConsumerWidget {
  const ClosedClassroomBanner({super.key, required this.classroom});

  final Classroom classroom;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scheme = Theme.of(context).colorScheme;
    final style = TextStyle(color: scheme.onSecondaryContainer);
    final closedAt = classroom.closedAt;
    return Card(
      key: const ValueKey('closed_banner'),
      color: scheme.secondaryContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Icon(
              Icons.inventory_2_outlined,
              color: scheme.onSecondaryContainer,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'ห้องเก่า อ่านอย่างเดียว',
                    style: style.copyWith(fontWeight: FontWeight.bold),
                  ),
                  Text(
                    '${closedAt == null ? '' : 'ปิดเมื่อ ${formatThaiDate(closedAt.toLocal())} '}'
                    'ดูผลและส่งออกได้ แต่สร้างการบ้าน สแกน กรอกคะแนน หรือแก้รายชื่อไม่ได้',
                    style: style,
                  ),
                ],
              ),
            ),
            if (classroom.isHomeroom)
              TextButton(
                key: const ValueKey('closed_banner_reopen'),
                onPressed: () => reopenClassroom(context, ref, classroom),
                child: const Text('เปิดห้องอีกครั้ง'),
              ),
          ],
        ),
      ),
    );
  }
}
