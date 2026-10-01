import 'package:flutter/foundation.dart';

/// Web client id of the Google Cloud project for sign-in (DESIGN §24.9.1),
/// separate from the Classroom project, injected at build time:
///   flutter run --dart-define=GOOGLE_SIGNIN_CLIENT_ID=xxxx.apps.googleusercontent.com
///
/// Not a secret. On Android it is the `serverClientId`, so the ID token's
/// `aud` is this client and the server accepts it (GOOGLE_SIGNIN_CLIENT_IDS).
/// The web preview never needs it: it signs in through the server's
/// redirect flow (§24.9.4).
const String googleSigninClientId = String.fromEnvironment(
  'GOOGLE_SIGNIN_CLIENT_ID',
);

/// This build signs in with Google on the device (Android with a client id).
/// `google_sign_in` initializes once per app run, so such a build also sends
/// the Google Classroom connection through the browser (DESIGN §24.9.4).
const bool googleSignInNativeBuild = !kIsWeb && googleSigninClientId.length > 0;

/// Version of [googleSignInNotice] the server records with every link
/// (DESIGN §24.14). Changing the text means a new version on both sides.
const googleSignInNoticeVersion = 'gsi-1';

/// The PDPA notice shown before every self-made link and under the Google
/// button of the login page (DESIGN §24.14, version [googleSignInNoticeVersion]).
const googleSignInNotice =
    'การเชื่อมบัญชี Google ใช้เพื่อเข้าสู่ระบบ EduVision เท่านั้น '
    'ระบบเก็บเฉพาะรหัสบัญชี ชื่อ อีเมล และรูปโปรไฟล์จาก Google '
    'ไม่เข้าถึงอีเมล ไฟล์ หรือข้อมูลอื่นในบัญชี '
    'ยกเลิกการเชื่อมได้ทุกเมื่อในหน้าบัญชีของฉัน หรือขอให้ครูประจำชั้นยกเลิกให้';
