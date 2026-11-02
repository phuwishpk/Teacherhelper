import '../../core/widgets/mascot_mood.dart';

/// One slide of the first-run onboarding (DESIGN §44, §43).
class OnboardingPage {
  const OnboardingPage({
    required this.mood,
    required this.title,
    required this.body,
  });

  final MascotMood mood;
  final String title;
  final String body;
}

const teacherOnboarding = [
  OnboardingPage(
    mood: MascotMood.wave,
    title: 'สร้างการบ้านและพิมพ์ใบงาน',
    body: 'ใบงานทุกแผ่นมี QR บอกว่าเป็นงานไหนของนักเรียนคนใด',
  ),
  OnboardingPage(
    mood: MascotMood.idle,
    title: 'สแกนใบงานด้วยกล้อง',
    body: 'ใช้มือถือหรือแท็บเล็ตสแกนตรวจคำตอบ ระบบตัดภาพและตรวจให้อัตโนมัติ',
  ),
  OnboardingPage(
    mood: MascotMood.idle,
    title: 'AI ช่วยตรวจและครูทบทวนผล',
    body: 'AI อ่านคำตอบและเสนอคะแนน ครูตรวจทานและปรับแก้ได้ก่อนเผยแพร่',
  ),
  OnboardingPage(
    mood: MascotMood.happy,
    title: 'เชื่อมต่อและส่งผลคะแนน',
    body: 'ดูสถิติความเข้าใจของห้อง หรือส่งคะแนนเข้า Google Classroom ได้ทันที',
  ),
];

const studentOnboarding = [
  OnboardingPage(
    mood: MascotMood.wave,
    title: 'เข้าห้องเรียนง่ายๆ',
    body: 'เข้าห้องเรียนด้วยรหัสห้องหรือสแกน QR Code จากครู',
  ),
  OnboardingPage(
    mood: MascotMood.idle,
    title: 'ส่งงานได้ทุกที่',
    body: 'ทำใบงานหรือส่งรูปภาพงานผ่านแอปได้อย่างสะดวกรวดเร็ว',
  ),
  OnboardingPage(
    mood: MascotMood.idle,
    title: 'ดูคะแนนและคำอธิบาย',
    body: 'รู้ผลคะแนนพร้อมคำแนะนำและคำอธิบายจุดที่ควรปรับปรุง',
  ),
  OnboardingPage(
    mood: MascotMood.happy,
    title: 'ติดตามการเรียนรู้',
    body: 'ไม่พลาดการบ้านและการแจ้งเตือนสำคัญจากห้องเรียนของคุณ',
  ),
];
