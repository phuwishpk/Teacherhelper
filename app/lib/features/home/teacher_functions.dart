import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/function_menu.dart';

/// What the menu does when a function is chosen (the teacher shell's
/// context and state stay with the shell).
class TeacherMenuActions {
  const TeacherMenuActions({
    required this.openTab,
    required this.push,
    required this.signOut,
    required this.openAssistant,
    required this.tabs,
  });

  /// Switches the shell to tab [index] (0 หน้าหลัก … 4 ตัดเกรด).
  final void Function(int index) openTab;
  final void Function(String route) push;
  final VoidCallback signOut;

  /// Opens "ผู้ช่วย Krucheck" (DESIGN §37): the panel or its page.
  final VoidCallback openAssistant;

  /// The shell's five tabs: label, icons and badge.
  final List<({String label, IconData icon, IconData selectedIcon, int badge})>
  tabs;
}

/// Every function a teacher can reach, by tab (DESIGN §35). A function with
/// a page of its own opens it; one that lives inside a classroom, an
/// assignment, a course or a review opens that tab and says where to go,
/// with a link to the guide's topic.
List<MenuGroup> teacherMenu(
  TeacherMenuActions a, {
  required void Function(int tab, String where, String? helpTopic) guide,
}) {
  MenuFunction page(
    String id,
    String title,
    IconData icon,
    String route, {
    String? where,
    List<String> keywords = const [],
  }) => MenuFunction(
    id: id,
    title: title,
    icon: icon,
    where: where,
    keywords: keywords,
    run: () => a.push(route),
  );

  MenuFunction inside(
    String id,
    String title,
    IconData icon,
    int tab,
    String where, {
    String? help,
    List<String> keywords = const [],
  }) => MenuFunction(
    id: id,
    title: title,
    icon: icon,
    where: where,
    keywords: keywords,
    run: () => guide(tab, where, help),
  );

  MenuGroup tab(int i, List<MenuFunction> functions) {
    final t = a.tabs[i];
    return MenuGroup(
      label: t.label,
      icon: t.icon,
      selectedIcon: t.selectedIcon,
      badge: t.badge,
      onOpen: () => a.openTab(i),
      functions: functions,
    );
  }

  return [
    tab(0, [
      page(
        'scan',
        'สแกนใบงาน',
        Icons.document_scanner_outlined,
        AppRoutes.scan,
        where: 'ใช้กล้องของแอป Android',
        keywords: ['scan', 'กล้อง', 'ถ่ายรูป', 'ใบงาน'],
      ),
      page(
        'upload',
        'อัปโหลดรูปเพื่อตรวจ',
        Icons.upload_file_outlined,
        AppRoutes.teacherUpload,
        where: 'เลือกการบ้านและนักเรียน แล้วแนบรูปหรือ PDF',
        keywords: ['upload', 'รูป', 'ไฟล์', 'สมุด', 'ส่งงานแทน'],
      ),
      if (!kIsWeb)
        page(
          'upload_queue',
          'คิวอัปโหลด',
          Icons.cloud_upload_outlined,
          AppRoutes.uploadQueue,
          where: 'ภาพสแกนที่ยังไม่ได้ส่ง',
          keywords: ['queue', 'ออฟไลน์', 'ยืนยันแทนที่'],
        ),
      page(
        'appeals',
        'คำขอตรวจใหม่',
        Icons.feedback_outlined,
        AppRoutes.appeals,
        where: 'นักเรียนขอให้ตรวจข้อใหม่',
        keywords: ['appeal', 'อุทธรณ์', 'ขอคะแนน'],
      ),
      page(
        'timetable',
        'ตารางสอน',
        Icons.calendar_month_outlined,
        AppRoutes.timetable,
        where: 'เพิ่มคาบ ยกเลิกคาบ และเช็คชื่อจากคาบ',
        keywords: ['timetable', 'คาบ', 'ตาราง', 'ยกเลิกคาบ'],
      ),
      inside(
        'attendance',
        'เช็คชื่อ',
        Icons.how_to_reg_outlined,
        0,
        'เปิด «ตารางสอน» แตะคาบ แล้วเลือก «เช็คชื่อรายวิชานี้»',
        help: 'posts',
        keywords: ['attendance', 'มา', 'ขาด', 'ลา', 'สาย'],
      ),
    ]),
    tab(1, [
      page(
        'classroom_new',
        'สร้างห้องเรียน',
        Icons.add_home_outlined,
        AppRoutes.classroomNew,
        keywords: ['ห้องใหม่', 'classroom'],
      ),
      page(
        'import_google',
        'นำเข้าจาก Google Classroom',
        Icons.school_outlined,
        AppRoutes.classroomImportGoogle,
        where: 'สร้างห้องจากคอร์สและรายชื่อใน Classroom',
        keywords: ['google', 'classroom', 'นำเข้า', 'import'],
      ),
      page(
        'courses',
        'รายวิชาทั้งหมด',
        Icons.menu_book_outlined,
        AppRoutes.courses,
        keywords: ['course', 'วิชา', 'หลักสูตร'],
      ),
      page(
        'course_new',
        'เพิ่มรายวิชา',
        Icons.library_add_outlined,
        AppRoutes.courseNew,
        where: 'รหัสวิชา ชื่อ กลุ่มสาระ ชั้น และห้องที่สอน',
        keywords: ['course', 'สร้างวิชา'],
      ),
      page(
        'course_requests',
        'คำขอผูกรายวิชา',
        Icons.inbox_outlined,
        AppRoutes.courseRequests,
        where: 'คำขอให้รายวิชาของครูท่านอื่นเข้าห้องของคุณ',
        keywords: ['request', 'ผูกวิชา'],
      ),
      inside(
        'students_add',
        'เพิ่มนักเรียน',
        Icons.person_add_alt_outlined,
        1,
        'เปิดห้องเรียน แล้วกด «เพิ่มนักเรียน» (วางรายชื่อจาก Excel ได้)',
        help: 'classrooms',
        keywords: ['student', 'รายชื่อ', 'excel'],
      ),
      inside(
        'students_from_classroom',
        'นำนักเรียนจากห้องเดิม',
        Icons.move_up_outlined,
        1,
        'เปิดห้องของปีใหม่ แล้วกด «นำนักเรียนจากห้องเดิม»',
        help: 'classrooms',
        keywords: ['ขึ้นปีใหม่', 'เลื่อนชั้น', 'ย้ายห้อง'],
      ),
      inside(
        'join_qr',
        'QR และรหัสเข้าห้อง',
        Icons.qr_code_2_outlined,
        1,
        'เปิดห้องเรียน แล้วกด «แสดง QR เข้าห้อง»',
        help: 'join',
        keywords: ['qr', 'รหัสห้อง', 'ลิงก์', 'join'],
      ),
      inside(
        'student_password',
        'รีเซ็ตรหัสผ่านนักเรียน',
        Icons.lock_reset_outlined,
        1,
        'เปิดห้องเรียน เมนู «⋯» ท้ายแถวนักเรียน > «รีเซ็ตรหัสผ่าน»',
        help: 'classrooms',
        keywords: ['password', 'ลืมรหัส', 'pin'],
      ),
      inside(
        'student_merge',
        'รวมบัญชีนักเรียน',
        Icons.merge_outlined,
        1,
        'เปิดห้องเรียน เมนู «⋯» ของนักเรียน > «รวมบัญชี» หรือการ์ด «บัญชีที่อาจซ้ำ»',
        help: 'classrooms',
        keywords: ['ซ้ำ', 'merge', 'duplicate'],
      ),
      inside(
        'coteach',
        'เชิญครูร่วมสอน',
        Icons.co_present_outlined,
        1,
        'เปิดห้องเรียน ส่วน «ครูร่วมสอน» > «เชิญครู»',
        help: 'coteach',
        keywords: ['สอนร่วม', 'ครูประจำวิชา', 'invite'],
      ),
      inside(
        'posts',
        'โพสต์ของห้อง',
        Icons.campaign_outlined,
        1,
        'เปิดห้องเรียน แล้วกด «โพสต์» > «เขียนโพสต์»',
        help: 'posts',
        keywords: ['ประกาศ', 'เอกสาร', 'ไฟล์', 'post'],
      ),
      inside(
        'mastery',
        'ทักษะของห้อง',
        Icons.grid_view_outlined,
        1,
        'เปิดห้องเรียน แล้วกด «ทักษะของห้อง»',
        help: 'insights',
        keywords: ['mastery', 'ตัวชี้วัด', 'จุดอ่อน', 'ตาราง'],
      ),
      inside(
        'analysis',
        'วิเคราะห์รายคนด้วย AI',
        Icons.psychology_outlined,
        1,
        'เปิดห้องเรียน แล้วกด «วิเคราะห์รายคน»',
        help: 'insights',
        keywords: ['analysis', 'ai', 'สรุป', 'จุดเด่น'],
      ),
      inside(
        'offline',
        'เตรียมสแกนออฟไลน์',
        Icons.download_for_offline_outlined,
        1,
        'เปิดห้องเรียนในแอป Android แล้วกด «เตรียมสแกนออฟไลน์»',
        help: 'scan',
        keywords: ['offline', 'ไม่มีเน็ต'],
      ),
      inside(
        'classroom_close',
        'ปิดห้อง (ย้ายไปห้องเก่า)',
        Icons.archive_outlined,
        1,
        'เปิดห้องเรียน เมนูของห้อง > «ปิดห้อง»',
        help: 'classrooms',
        keywords: ['ห้องเก่า', 'จบปี', 'archive'],
      ),
      inside(
        'plans',
        'หน่วยและแผนการสอน',
        Icons.event_note_outlined,
        1,
        'เปิดรายวิชา แล้วเพิ่มหน่วยและแผน หรือ «นำเข้าแผนจากเอกสาร»',
        help: 'courses',
        keywords: ['แผน', 'หน่วย', 'unit', 'plan', 'เอกสาร'],
      ),
      inside(
        'course_charts',
        'กราฟและความคืบหน้าของรายวิชา',
        Icons.insights_outlined,
        1,
        'เปิดรายวิชา แล้วกด «กราฟและความคืบหน้า»',
        help: 'insights',
        keywords: ['chart', 'กราฟ', 'เรดาร์', 'ความคืบหน้า'],
      ),
    ]),
    tab(2, [
      page(
        'assignment_new',
        'สร้างการบ้าน',
        Icons.post_add_outlined,
        AppRoutes.assignmentNew,
        keywords: ['homework', 'ใบงาน', 'assignment'],
      ),
      page(
        'exam_new',
        'สร้างข้อสอบ',
        Icons.fact_check_outlined,
        AppRoutes.examNew,
        where: 'ข้อสอบแบบฝนและกระดาษคำตอบ',
        keywords: ['exam', 'สอบ', 'ฝน', 'กระดาษคำตอบ'],
      ),
      page(
        'practice_bank',
        'คลังแบบฝึก',
        Icons.fitness_center_outlined,
        AppRoutes.practiceBank,
        where: 'ข้อฝึกและลิงก์ทบทวนรายตัวชี้วัด',
        keywords: ['practice', 'แบบฝึกหัด', 'ทบทวน'],
      ),
      inside(
        'questions',
        'เพิ่มคำถามและเฉลย',
        Icons.quiz_outlined,
        2,
        'เปิดการบ้าน แล้วกด «เพิ่มคำถาม»',
        help: 'homework',
        keywords: ['คำถาม', 'โจทย์', 'เฉลย', 'question'],
      ),
      inside(
        'rubric',
        'ตั้ง rubric ข้ออัตนัย',
        Icons.rule_outlined,
        2,
        'เปิดการบ้าน เปิดข้ออัตนัย แล้วตั้งเกณฑ์ (ให้ AI ร่างได้)',
        help: 'homework',
        keywords: ['rubric', 'เกณฑ์', 'อัตนัย'],
      ),
      inside(
        'print',
        'พิมพ์ใบงาน',
        Icons.print_outlined,
        2,
        'เปิดการบ้าน กด «สร้าง layout» แล้ว «พิมพ์ใบงาน»',
        help: 'homework',
        keywords: ['print', 'pdf', 'layout'],
      ),
      inside(
        'answer_key',
        'เฉลยจากไฟล์ (AI อ่าน)',
        Icons.key_outlined,
        2,
        'เปิดการบ้านแบบไม่ใช้ใบงาน แนบไฟล์เฉลย แล้ว «อนุมัติเฉลย»',
        help: 'freeform',
        keywords: ['เฉลย', 'answer key', 'ไฟล์'],
      ),
      inside(
        'indicator_mapping',
        'จับคู่ข้อกับตัวชี้วัด',
        Icons.link_outlined,
        2,
        'เปิดการบ้าน แล้วกด «จับคู่ข้อกับตัวชี้วัด»',
        help: 'homework',
        keywords: ['ตัวชี้วัด', 'indicator', 'mapping'],
      ),
      inside(
        'regrade',
        'ตรวจใหม่ทั้งห้อง',
        Icons.replay_outlined,
        2,
        'เปิดการบ้าน หน้าเฉลย > «ตรวจใหม่ทั้งห้อง»',
        help: 'review',
        keywords: ['regrade', 'เฉลยผิด'],
      ),
      inside(
        'exam_scan',
        'สแกนกระดาษคำตอบข้อสอบ',
        Icons.document_scanner_outlined,
        2,
        'เปิดข้อสอบในแอป Android แล้วกด «สแกนกระดาษคำตอบ»',
        help: 'exams',
        keywords: ['ข้อสอบ', 'ฝน', 'scan'],
      ),
      inside(
        'exam_analysis',
        'วิเคราะห์ผลข้อสอบ',
        Icons.analytics_outlined,
        2,
        'เปิดข้อสอบที่ประกาศผลแล้ว แล้วกด «วิเคราะห์ผล»',
        help: 'exams',
        keywords: ['ความยาก', 'อำนาจจำแนก', 'item analysis'],
      ),
      inside(
        'exam_import',
        'อ่านข้อสอบจากไฟล์ (AI)',
        Icons.picture_as_pdf_outlined,
        2,
        'เปิดข้อสอบ แล้วกด «อ่านข้อจากไฟล์ข้อสอบ»',
        help: 'exams',
        keywords: ['pdf', 'นำเข้า', 'ai'],
      ),
    ]),
    tab(3, [
      inside(
        'review_queue',
        'ตรวจทานคะแนนที่ AI เสนอ',
        Icons.rate_review_outlined,
        3,
        'เลือกการบ้าน แล้วไล่ «ต้องตรวจ» «ควรดู» «มั่นใจ»',
        help: 'review',
        keywords: ['review', 'ตรวจ', 'คะแนน'],
      ),
      inside(
        'approve_confident',
        'อนุมัติทั้งกลุ่มมั่นใจ',
        Icons.done_all,
        3,
        'เปิดการบ้านในแท็บตรวจทาน แท็บ «มั่นใจ» > «อนุมัติทั้งกลุ่มมั่นใจ»',
        help: 'review',
        keywords: ['approve', 'อนุมัติ'],
      ),
      inside(
        'publish',
        'เผยแพร่ผลให้นักเรียน',
        Icons.publish_outlined,
        3,
        'เปิดการบ้านในแท็บตรวจทาน แล้วกด «เผยแพร่ทั้งการบ้าน»',
        help: 'review',
        keywords: ['publish', 'ประกาศผล', 'ส่งผล'],
      ),
    ]),
    tab(4, [
      inside(
        'gradebook',
        'สมุดคะแนน',
        Icons.table_chart_outlined,
        4,
        'เลือกรายวิชาในแท็บตัดเกรด',
        help: 'gradebook',
        keywords: ['gradebook', 'คะแนน'],
      ),
      inside(
        'grade_categories',
        'ตั้งค่าหมวดคะแนนและเกณฑ์เกรด',
        Icons.tune_outlined,
        4,
        'เลือกรายวิชา > «ตั้งค่าหมวดคะแนน»',
        help: 'gradebook',
        keywords: ['หมวด', 'น้ำหนัก', 'เกณฑ์', 'เก็บ', 'ปลายภาค'],
      ),
      inside(
        'grade_publish',
        'ประกาศเกรด',
        Icons.campaign_outlined,
        4,
        'เลือกรายวิชา > «ประกาศเกรด»',
        help: 'gradebook',
        keywords: ['เกรด', 'ประกาศ'],
      ),
      inside(
        'grade_export',
        'ส่งออกคะแนนเป็น CSV',
        Icons.file_download_outlined,
        4,
        'เลือกรายวิชา > «ส่งออก CSV»',
        help: 'gradebook',
        keywords: ['csv', 'excel', 'export'],
      ),
      inside(
        'special_grade',
        'เกรด ร / มส',
        Icons.warning_amber_outlined,
        4,
        'เปิดสมุดคะแนน แตะชื่อนักเรียน > «เกรดพิเศษ»',
        help: 'gradebook',
        keywords: ['ร', 'มส', 'ขาดสอบ', 'แก้ตัว'],
      ),
    ]),
    MenuGroup(
      label: 'ตั้งค่าและบัญชี',
      icon: Icons.settings_outlined,
      functions: [
        page(
          'settings',
          'ตั้งค่า',
          Icons.settings_outlined,
          AppRoutes.settings,
          keywords: ['settings', 'โปรไฟล์', 'ชื่อ', 'รหัสผ่าน', 'ธีม'],
        ),
        page(
          'ai_key',
          'Gemini API key',
          Icons.key_outlined,
          AppRoutes.settings,
          where: 'ตั้งค่า > ใส่และทดสอบ key ของ AI',
          keywords: ['gemini', 'ai', 'key', 'คีย์'],
        ),
        page(
          'my_indicators',
          'ตัวชี้วัดของฉัน',
          Icons.checklist,
          AppRoutes.myIndicators,
          where: 'วิชาและชั้นที่สอน และตัวชี้วัดที่เพิ่มเอง',
          keywords: ['ตัวชี้วัด', 'indicator', 'ทักษะ'],
        ),
        page(
          'skill_trash',
          'ถังขยะตัวชี้วัด',
          Icons.delete_outline,
          AppRoutes.skillTrash,
          keywords: ['ลบ', 'กู้คืน', 'trash'],
        ),
        page(
          'google',
          'เชื่อม Google',
          Icons.link,
          AppRoutes.settings,
          where:
              'ตั้งค่า > บัญชี Google สำหรับเข้าสู่ระบบ และ Google Classroom',
          keywords: ['google', 'classroom', 'เชื่อมใหม่'],
        ),
        page(
          'help',
          'คู่มือการใช้งาน',
          Icons.help_outline,
          AppRoutes.help,
          keywords: ['help', 'วิธีใช้', 'คู่มือ'],
        ),
        MenuFunction(
          id: 'assistant',
          title: 'ผู้ช่วย Krucheck',
          icon: Icons.smart_toy_outlined,
          where: 'ถามวิธีใช้ระบบ การให้คะแนน และการตัดเกรด',
          keywords: const ['ai', 'chat', 'แชท', 'ถาม', 'ช่วย', 'หุ่นยนต์'],
          run: a.openAssistant,
        ),
        MenuFunction(
          id: 'sign_out',
          title: 'ออกจากระบบ',
          icon: Icons.logout,
          keywords: const ['logout', 'sign out'],
          run: a.signOut,
        ),
      ],
    ),
  ];
}
