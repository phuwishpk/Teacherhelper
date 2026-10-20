import 'dart:io';

import 'package:eduvision/features/help/help_content.dart';
import 'package:eduvision/features/home/teacher_functions.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §37: the assistant answers from the app's own guide and menu.
/// Their text lives here in Dart; the backend reads a Markdown copy in
/// backend/resources/assistant/core/. This test fails when the copy is
/// stale; `UPDATE_ASSISTANT_KNOWLEDGE=1 flutter test
/// test/help/assistant_knowledge_test.dart` rewrites it.
void main() {
  const dir = '../backend/resources/assistant/core';

  String guide() {
    final b = StringBuffer(
      '# คู่มือในแอปสำหรับครู\n\n'
      'เนื้อหาเดียวกับปุ่ม «คู่มือการใช้งาน» (ไอคอน ? มุมขวาบน) ของแอป\n',
    );
    for (final t in teacherHelpTopics) {
      b.writeln('\n## ${t.title}\n');
      b.writeln(t.intro);
      if (t.steps.isNotEmpty) {
        b.writeln('\nขั้นตอน:');
        for (final (i, s) in t.steps.indexed) {
          b.writeln('${i + 1}. $s');
        }
      }
      for (final u in t.useCases) {
        b.writeln('\nกรณี «${u.title}»: ${u.story}');
      }
      if (t.notes.isNotEmpty) {
        b.writeln('\nหมายเหตุ:');
        for (final n in t.notes) {
          b.writeln('- $n');
        }
      }
    }
    return b.toString();
  }

  String menu() {
    final groups = teacherMenu(
      TeacherMenuActions(
        openTab: (_) {},
        push: (_) {},
        signOut: () {},
        openAssistant: () {},
        tabs: [
          for (final label in [
            'หน้าหลัก',
            'ห้องเรียน',
            'การบ้าน',
            'ตรวจทาน',
            'ตัดเกรด',
          ])
            (
              label: label,
              icon: Icons.circle,
              selectedIcon: Icons.circle,
              badge: 0,
            ),
        ],
      ),
      guide: (_, _, _) {},
    );
    final b = StringBuffer(
      '# เมนูและเครื่องมือของครู\n\n'
      'แอปของครูมี 5 แท็บ: หน้าหลัก ห้องเรียน การบ้าน ตรวจทาน ตัดเกรด '
      'บนจอกว้างอยู่ใน sidebar ซ้าย พร้อมช่อง «ค้นหาเมนูและเครื่องมือ» '
      'บนมือถืออยู่ที่แถบล่าง และเมนูทั้งหมดอยู่ในปุ่มสามขีดมุมซ้ายบน '
      'ไอคอนตั้งค่า คู่มือ และออกจากระบบอยู่มุมขวาบน (จอกว้างอยู่ใต้ชื่อครูใน sidebar)\n',
    );
    for (final g in groups) {
      b.writeln('\n## ${g.label}\n');
      for (final f in g.functions) {
        b.writeln(
          f.where == null ? '- ${f.title}' : '- ${f.title}: ${f.where}',
        );
      }
    }
    return b.toString();
  }

  for (final (name, build) in [('01-guide.md', guide), ('02-menu.md', menu)]) {
    test('$name matches the app', () {
      final file = File('$dir/$name');
      final want = build();
      if (Platform.environment['UPDATE_ASSISTANT_KNOWLEDGE'] == '1') {
        file
          ..createSync(recursive: true)
          ..writeAsStringSync(want);
      }
      expect(
        file.existsSync() ? file.readAsStringSync() : '',
        want,
        reason:
            'backend/resources/assistant/core/$name is stale: run '
            'UPDATE_ASSISTANT_KNOWLEDGE=1 flutter test test/help/assistant_knowledge_test.dart',
      );
    });
  }
}
