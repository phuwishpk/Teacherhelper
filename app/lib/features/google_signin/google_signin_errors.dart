import '../../core/api/api_client.dart';
import '../google_classroom/google_auth.dart' show GoogleAuthException;

/// Thai text for an error `code` of Google sign-in (DESIGN §24.12 C), also
/// the `error=` / `status=` of the web flow's redirects (§24.22). Null for
/// a code the app does not know.
String? googleSignInCodeMessage(String? code) => switch (code) {
  'google_not_linked' => 'บัญชี Google นี้ยังไม่ได้เชื่อมกับบัญชี Krucheck',
  'google_domain_not_allowed' =>
    'โรงเรียนไม่อนุญาตให้ใช้บัญชี Google ของโดเมนนี้ กรุณาใช้บัญชี Google ของโรงเรียน',
  'student_google_disabled' =>
    'โรงเรียนยังไม่เปิดให้นักเรียนเข้าสู่ระบบด้วย Google กรุณาใช้บัตร QR หรือ PIN',
  'account_not_active' =>
    'บัญชีนี้ยังใช้งานไม่ได้ (รอผู้ดูแลระบบอนุมัติหรือถูกระงับ)',
  'google_token_invalid' =>
    'ยืนยันบัญชี Google ไม่สำเร็จ กรุณาลองเข้าสู่ระบบด้วย Google อีกครั้ง',
  'google_email_unverified' =>
    'อีเมลของบัญชี Google นี้ยังไม่ได้รับการยืนยัน กรุณาใช้บัญชีอื่น',
  'google_unavailable' =>
    'ติดต่อ Google ไม่ได้ในขณะนี้ ลองใหม่อีกครั้งในอีกสักครู่',
  'google_already_linked' =>
    'บัญชี Google นี้เชื่อมกับผู้ใช้อื่นอยู่แล้ว กรุณาใช้บัญชี Google อื่น',
  'google_identity_exists' =>
    'บัญชีนี้เชื่อมกับบัญชี Google อื่นอยู่แล้ว ยกเลิกการเชื่อมเดิมก่อนแล้วจึงเชื่อมใหม่',
  'notice_required' =>
    'กรุณาอ่านและกดยอมรับข้อความแจ้งเรื่องข้อมูลส่วนบุคคลก่อนเชื่อมบัญชี Google',
  'link_ticket_invalid' =>
    'การยืนยันบัญชี Google หมดอายุหรือถูกใช้ไปแล้ว กรุณากดเข้าสู่ระบบด้วย Google อีกครั้ง',
  'google_ticket_invalid' =>
    'ลิงก์เข้าสู่ระบบด้วย Google หมดอายุหรือถูกใช้ไปแล้ว กรุณาลองใหม่',
  'google_signin_not_configured' =>
    'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่าการเข้าสู่ระบบด้วย Google กรุณาใช้รหัสผ่าน PIN หรือบัตร QR',
  'google_signin_web_not_configured' =>
    'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่าการเข้าสู่ระบบด้วย Google ทางเว็บ กรุณาใช้แอปบน Android หรือรหัสผ่าน',
  'cancelled' => 'ยกเลิกการเข้าสู่ระบบด้วย Google แล้ว',
  'google_error' => 'Google ไม่อนุญาตการเข้าสู่ระบบครั้งนี้ ลองอีกครั้ง',
  _ => null,
};

/// The codes of [googleSignInCodeMessage]: a failed PIN/QR confirmation
/// with one of these is about Google, not about the PIN.
bool isGoogleSignInCode(String? code) => googleSignInCodeMessage(code) != null;

/// Thai message for anything a Google sign-in, link or unlink threw.
///
/// The server's own Thai message wins where it says more than the code
/// (`google_not_linked` explains what to do next, `account_not_active`
/// tells pending from disabled).
String googleSignInErrorMessage(Object error) {
  if (error is GoogleAuthException) return error.message;
  final code = apiErrorCode(error);
  if (code == 'google_not_linked' || code == 'account_not_active') {
    return apiErrorMessage(error);
  }
  final known = googleSignInCodeMessage(code);
  if (known != null) return known;
  if (apiStatusCode(error) == 429) {
    return 'มีการเข้าสู่ระบบด้วย Google ถี่เกินไป รอสักครู่แล้วลองใหม่';
  }
  return apiErrorMessage(error);
}

/// Thai text of the web flow's `error=` / `status=` code (DESIGN §24.22).
String googleReturnCodeMessage(String code) =>
    googleSignInCodeMessage(code) ??
    'เข้าสู่ระบบด้วย Google ไม่สำเร็จ ($code) ลองอีกครั้ง';
