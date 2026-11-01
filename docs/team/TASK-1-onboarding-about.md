# งานที่ 1: หน้าแนะนำการใช้งานครั้งแรก + หน้า "เกี่ยวกับ Krucheck"

- **branch:** `feat/app-onboarding-about` แตกจาก `main` ล่าสุด
- **PR:** เปิดเข้า `main`
- **ขนาด:** 16 commit
- **ภาษา:** Dart (Flutter) และแก้ Firestore rules หนึ่งไฟล์
- **เครื่อง RAM 4 GB:** ทำได้แบบรันบน Chrome และชี้ไปที่ server ทดสอบ (ดู `00-START-HERE.md`)

> **ถ้าทำต่อจาก `TASK-3-chat-mascot.md`:** ให้แก้ข้อ C2, C3, C5 และ C6 ตามตารางในส่วน D ของไฟล์นั้น

## ภาพรวม

ฟีเจอร์นี้ใช้ทั้ง **REST API** และ **Firestore** และเก็บข้อมูลไว้สองที่ คือ **ในเครื่อง** กับ **บน cloud**:

| ส่วน | ใช้อะไร | อ่าน/เขียน |
|---|---|---|
| สถานะเซิร์ฟเวอร์ในหน้าเกี่ยวกับ | REST `GET /api/v1/health` (มีอยู่แล้ว) | อ่าน |
| "ดูหน้าแนะนำแล้ว" ข้ามเครื่อง | Firestore `users/{uid}/settings/app` | อ่าน + เขียน |
| "ดูหน้าแนะนำแล้ว" ในเครื่อง | `flutter_secure_storage` ผ่าน `TokenStorage` | อ่าน + เขียน |

**ลำดับการทำงานที่ต้องได้:**
1. ครูหรือนักเรียนเข้าสู่ระบบครั้งแรก หน้าแนะนำ 4 สไลด์จะขึ้น
2. กด "เริ่มใช้งาน" หรือ "ข้าม" แอปจะจำไว้ทั้งในเครื่องและใน Firestore
3. เข้าสู่ระบบบนเครื่องอื่นด้วยบัญชีเดิม หน้าแนะนำไม่ขึ้นอีก เพราะอ่านค่าจาก Firestore
4. หน้า "เกี่ยวกับ Krucheck" แสดงสถานะเซิร์ฟเวอร์จาก REST และบอกว่า "ดูวิธีใช้งานครั้งแรกเมื่อ … จาก …" โดยอ่านจาก Firestore

**ไม่ทำ:** ไม่แตะส่วนผู้ดูแลระบบ (admin) ตามหัวข้อ 0 ของ `00-START-HERE.md` และไม่ต้องแก้ backend หน้าแนะนำเปิดจาก `TeacherShell` กับ `StudentShell` เท่านั้น จึงมีแค่ครูและนักเรียนที่เห็น

## ตารางความคืบหน้า (ติ๊ก [x] เมื่อ commit แล้ว)

- [ ] C1 เพิ่มที่เก็บค่า "ดูหน้าแนะนำแล้ว" ในเครื่อง
- [ ] C2 เนื้อหาสไลด์ของครูและนักเรียน
- [ ] C3 OnboardingStore: อ่านเขียนในเครื่องและ Firestore
- [ ] C4 test ของ OnboardingStore
- [ ] C5 Firestore rules ของ `settings/app`
- [ ] C6 หน้าจอสไลด์ (PageView, จุด, ปุ่ม)
- [ ] C7 route และแสดงหน้าแนะนำหลังเข้าสู่ระบบ
- [ ] C8 เมนู "ดูวิธีใช้งานอีกครั้ง"
- [ ] C9 test หน้าสไลด์ 3 ขนาดจอ
- [ ] C10 ServerHealth model และ repository (REST)
- [ ] C11 test ของ repository สถานะเซิร์ฟเวอร์
- [ ] C12 หน้าเกี่ยวกับ: ข้อมูลแอปและทีม
- [ ] C13 การ์ดสถานะเซิร์ฟเวอร์
- [ ] C14 บรรทัด "ดูวิธีใช้งานครั้งแรกเมื่อ…" จาก Firestore + เมนูเข้าหน้าเกี่ยวกับ
- [ ] C15 test หน้าเกี่ยวกับ
- [ ] C16 เอกสาร DESIGN

---

## ความรู้ที่ต้องมีก่อนเริ่ม (อ่าน 15 นาที)

| เรื่อง | ไฟล์ที่ควรเปิดดู |
|---|---|
| โครงโฟลเดอร์ feature | `app/lib/features/<ชื่อ>/` ตาม DESIGN §6.1 |
| state (Riverpod) | `Provider`, `FutureProvider.autoDispose` เช่น `app/lib/features/my_indicators/my_indicators_card.dart` |
| เรียก REST | `app/lib/features/timetable/timetable_repository.dart` (abstract class + `Api...Repository` + provider ที่ใช้ `dioProvider`) |
| ค่าที่เก็บในเครื่อง | `app/lib/core/auth/token_storage.dart` (ดู `readMyIndicatorsPromptClosed` ซึ่งเป็นค่า "ปิดแล้ว" รายผู้ใช้ เหมือนที่เราจะทำ) |
| Firestore | `app/lib/core/live/live_scope.dart` (`liveUserScope(ref)` คืน `(db, uid)` หรือ `null` ถ้า Firestore ใช้ไม่ได้) และ `app/lib/features/notices/notices_providers.dart` |
| router | `app/lib/core/router/app_router.dart` (`AppRoutes` และการ redirect ตามบทบาท) |
| ธีมและขนาดจอ | `app/lib/core/theme/breakpoints.dart` (`context.windowSize` ได้ `compact`/`medium`/`expanded`), `Theme.of(context).colorScheme` (มี `success`, `warning`, `error`) |
| เวลาไทย | `app/lib/core/util/thai_date.dart` → `formatThaiDateTime(dt)` |
| test หน้าจอ | `app/test/helpers/pump_screen.dart` → `pumpScreen(...)` |

**กติกา UI (DESIGN §27):** ห้ามใส่ `Color(0x...)`, `fontSize` หรือ `BorderRadius` เองในหน้าจอ ให้ใช้ค่าจาก `Theme.of(context)` ทั้งหมด

**ข้อควรรู้เรื่อง router:** นักเรียนเปิดได้เฉพาะ path ที่ขึ้นต้นด้วย `/student` (ดู redirect บรรทัดที่มี `inStudentArea`) หน้าใหม่จึงต้องมี path แยกให้ครูกับนักเรียน:

| หน้า | path ครู | path นักเรียน |
|---|---|---|
| หน้าแนะนำ | `/welcome` | `/student/welcome` |
| หน้าเกี่ยวกับ | `/about` | `/student/about` |

---

## C1: เพิ่มที่เก็บค่า "ดูหน้าแนะนำแล้ว" ในเครื่อง 🤖

**เป้าหมาย:** ให้ `TokenStorage` จำค่า "ดูแล้ว" แยกตามผู้ใช้แต่ละคน

**ไฟล์:** `app/lib/core/auth/token_storage.dart`

**ทำ:**
1. ใน `abstract class TokenStorage` เพิ่ม:
   ```dart
   /// Whether [userId] finished or skipped the first-run onboarding on this device.
   Future<bool> readOnboardingDone(int userId);
   Future<void> writeOnboardingDone(int userId);
   ```
2. ใน `SecureTokenStorage` เพิ่ม key `static const _onboardingKey = 'onboarding_done';` แล้วเขียนตามแบบ `readMyIndicatorsPromptClosed` (key รวม userId เข้าไป และเก็บค่า `'1'`)
3. ใน `InMemoryTokenStorage` (class ในไฟล์เดียวกันที่ใช้ใน test) เพิ่ม `Set<int>` เก็บ userId แล้ว implement สองเมธอดนี้
4. ค้นหา class อื่นที่ `implements TokenStorage` ด้วย `grep -rn "implements TokenStorage" lib test` ถ้าเจอ ให้เพิ่มเมธอดให้ครบทุกตัว

**ตรวจ:** `flutter analyze` ต้องไม่มี error

**commit:** `feat(app): remember finished onboarding per user on the device`

---

## C2: เนื้อหาสไลด์ของครูและนักเรียน 🧑

**เป้าหมาย:** เตรียมข้อความ 4 สไลด์ของครู และ 4 สไลด์ของนักเรียน งานนี้ฝึกทำเองได้ เพราะเป็นข้อความกับไอคอนล้วน

**ไฟล์ใหม่:** `app/lib/features/onboarding/onboarding_pages.dart`

**ทำ:**
```dart
import 'package:flutter/material.dart';

/// One slide of the first-run onboarding (DESIGN §41).
class OnboardingPage {
  const OnboardingPage({required this.icon, required this.title, required this.body});
  final IconData icon;
  final String title;
  final String body;
}

const teacherOnboarding = [
  OnboardingPage(icon: Icons.assignment_outlined, title: 'สร้างการบ้านและพิมพ์ใบงาน',
      body: 'ใบงานทุกแผ่นมี QR บอกว่าเป็นงานไหนของนักเรียนคนใด'),
  // อีก 3 หน้า: สแกนใบงาน / AI ตรวจแล้วครูทวนผล / ส่งคะแนนเข้า Google Classroom
];

const studentOnboarding = [
  // 4 หน้า: เข้าห้องด้วยรหัสหรือ QR / ส่งงาน / ดูคะแนนและคำอธิบาย / ดูแจ้งเตือน
];
```
- เลือกไอคอนจาก https://fonts.google.com/icons (ใช้ชื่อแบบ `Icons.xxx_outlined`)
- ข้อความเขียนเป็นภาษาไทย ไม่เกิน 2 บรรทัด และเขียนจากมุมของผู้ใช้ ไม่ใช่มุมของระบบ

**ตรวจ:** `flutter analyze`

**commit:** `feat(app): add onboarding slides for teachers and students`

---

## C3: OnboardingStore อ่านเขียนในเครื่องและ Firestore 🤖

**เป้าหมาย:** สร้างคลาสเดียวที่ตอบได้ว่า "ต้องแสดงหน้าแนะนำไหม" และบันทึกว่า "ดูแล้ว" ทั้งสองที่ แยกออกจาก provider เพื่อให้ test ได้ง่าย (แบบเดียวกับ `teacher_notes_store.dart`)

**ไฟล์ใหม่:** `app/lib/features/onboarding/onboarding_store.dart`

**ทำ:**
```dart
/// Where "onboarding finished" lives: the device first, then Firestore
/// `users/{uid}/settings/app` so another device of the same account skips it
/// too (DESIGN §41). Firestore is optional: null [db] = device only.
class OnboardingStore {
  OnboardingStore({required this.userId, required this.storage, this.db, this.uid,
      required this.device, DateTime Function()? now}) : _now = now ?? DateTime.now;

  final int userId;
  final TokenStorage storage;
  final FirebaseFirestore? db;
  final String? uid;
  final String device; // 'android' | 'ios' | 'web'
  final DateTime Function() _now;

  DocumentReference<Map<String, dynamic>>? get _doc =>
      db == null || uid == null ? null : db!.doc('users/$uid/settings/app');

  /// True when neither this device nor Firestore says it was done.
  Future<bool> needed() async { ... }

  /// Saves on the device, then in Firestore; a Firestore failure is ignored.
  Future<void> markDone() async { ... }

  /// When and where it was first finished, read from Firestore (null = unknown).
  Future<({DateTime at, String device})?> doneInfo() async { ... }
}
```
**ลำดับใน `needed()`:**
1. `storage.readOnboardingDone(userId)` ได้ `true` → คืน `false` (ไม่ต้องแสดง)
2. ถ้า `_doc` ไม่ใช่ `null` ให้ `get()` ถ้าเอกสารมี `onboarding_done_at` → เรียก `storage.writeOnboardingDone(userId)` แล้วคืน `false`
3. นอกนั้นคืน `true`
4. ครอบการอ่าน Firestore ด้วย `try/catch` ถ้าอ่านพลาด (เช่นออฟไลน์) ให้ทำต่อเหมือนไม่มีเอกสาร

**`markDone()`:**
1. เขียนลงเครื่องก่อน
2. แล้ว `_doc?.set({'onboarding_done_at': Timestamp.fromDate(_now()), 'device': device}, SetOptions(merge: true))`
3. ถ้าเอกสารมี `onboarding_done_at` อยู่แล้ว อย่าเขียนทับ เพราะต้องการเก็บ "ครั้งแรก"
4. ครอบด้วย `try/catch` ถ้าพลาดให้ `debugPrint` แล้วจบ ห้ามโยน exception ออกไป

**provider (ไฟล์เดียวกัน):**
```dart
final onboardingStoreProvider = Provider.autoDispose<OnboardingStore?>((ref) {
  final user = ref.watch(currentUserProvider);
  if (user == null) return null;
  final scope = liveUserScope(ref);
  return OnboardingStore(userId: user.id, storage: ref.watch(tokenStorageProvider),
      db: scope?.db, uid: scope?.uid, device: currentDeviceName());
});

final onboardingNeededProvider = FutureProvider.autoDispose<bool>((ref) async =>
    await ref.watch(onboardingStoreProvider)?.needed() ?? false);
```
- `currentDeviceName()` ใช้ `kIsWeb` → `'web'` ถ้าไม่ใช่เว็บใช้ `defaultTargetPlatform` → `'ios'` หรือ `'android'` ดูตัวอย่างค่า `device` ใน `teacher_notes_store.dart`

**ตรวจ:** `flutter analyze`

**commit:** `feat(app): sync finished onboarding between the device and Firestore`

---

## C4: test ของ OnboardingStore 🤖

**ไฟล์ใหม่:** `app/test/onboarding/onboarding_store_test.dart`

**ใช้:** `FakeFirebaseFirestore()` จาก `fake_cloud_firestore` และ `InMemoryTokenStorage()` (ดูตัวอย่างใน `app/test/notes/teacher_notes_store_test.dart`)

**กรณีที่ต้องมี (อย่างน้อย 6):**
1. ไม่มีค่าในเครื่องและไม่มีเอกสาร → `needed()` เป็น `true`
2. มีค่าในเครื่อง → `false` และไม่อ่าน Firestore
3. ไม่มีในเครื่องแต่มีใน Firestore → `false` และหลังจากนั้นในเครื่องต้องมีค่าด้วย
4. `markDone()` เขียนทั้งสองที่ และ `device` ถูกต้อง
5. `markDone()` ครั้งที่สองไม่เขียนทับเวลาครั้งแรก
6. `db: null` (ไม่มี Firestore) → ใช้งานได้ด้วยค่าในเครื่องอย่างเดียว
7. (ถ้าทำได้) ค่าของผู้ใช้คนหนึ่งไม่มีผลกับผู้ใช้อีกคน

**ตรวจ:** `flutter test test/onboarding/`

**commit:** `test(app): onboarding store reads and writes the device and Firestore`

---

## C5: Firestore rules ของ `settings/app` 🧑🤖

**เป้าหมาย:** ตอนนี้ rules ห้ามแอปเขียนทุกอย่างใต้ `users/{uid}` ต้องเปิดให้เขียนเฉพาะเอกสารนี้ และตรวจรูปแบบข้อมูลให้เข้ม

**ไฟล์:** `firebase/firestore.rules`

**ทำ:** เพิ่มก่อนบรรทัด `match /{document=**}` อันสุดท้าย:
```
// First-run onboarding, written by the app itself (DESIGN §41).
match /users/{uid}/settings/app {
  allow create, update: if request.auth != null && request.auth.uid == uid
    && request.resource.data.keys().hasOnly(['onboarding_done_at', 'device'])
    && request.resource.data.onboarding_done_at is timestamp
    && request.resource.data.device in ['android', 'ios', 'web'];
}
```
- ส่วนการอ่าน กฎ `match /users/{uid}/{document=**}` ที่มีอยู่แล้วอนุญาตไว้ ไม่ต้องเพิ่ม
- ถ้าบน main มี rule ของ `notes` แล้ว ให้ดูเป็นตัวอย่างการเขียน
- เขียนอธิบายด้วยตัวเองว่าแต่ละบรรทัดป้องกันอะไร เพราะอาจารย์อาจถามเรื่อง security

**ตรวจ:** อ่านทวนเอง (CI ไม่ได้ตรวจ rules) หลัง merge ให้แจ้ง phuwishpk ไปกด Publish ใน Firebase console

**commit:** `feat(repo): let users write their own onboarding settings in Firestore`

---

## C6: หน้าจอสไลด์ 🧑🤖

**ไฟล์ใหม่:** `app/lib/features/onboarding/onboarding_screen.dart`

**ทำ:**
- `OnboardingScreen extends ConsumerStatefulWidget` มี `PageController`
- ส่วนบน: ปุ่ม `TextButton` "ข้าม" มุมขวา (key `onboarding_skip`)
- ส่วนกลาง: `PageView.builder` จาก `teacherOnboarding` หรือ `studentOnboarding` ตาม `currentUserProvider` (`user.isStudent`)
  - แต่ละหน้ามีไอคอนขนาด 96 สี `colorScheme.primary`, หัวข้อใช้ `textTheme.headlineSmall`, คำอธิบายใช้ `textTheme.bodyLarge`
  - จอ `compact` วางไอคอนบน ข้อความล่าง (`Column`)
  - จอ `medium` และ `expanded` วางไอคอนซ้าย ข้อความขวา (`Row`) และจำกัดความกว้างเนื้อหาไม่เกิน 720
- ส่วนล่าง: จุดบอกหน้า (`AnimatedContainer` วงกลมเล็ก ๆ หน้าปัจจุบันยาวกว่าและเป็นสี primary) และปุ่ม `FilledButton` "ถัดไป" ซึ่งหน้าสุดท้ายเปลี่ยนเป็น "เริ่มใช้งาน" (key `onboarding_next`)
- กด "ข้าม" หรือ "เริ่มใช้งาน" → `await ref.read(onboardingStoreProvider)?.markDone()` → `ref.invalidate(onboardingNeededProvider)` → `context.go(user.isStudent ? AppRoutes.student : AppRoutes.home)`

**ตรวจ:** รันบน Chrome แล้วเปิด `/welcome` ตรง ๆ ในแถบที่อยู่ (หลัง C7 จะเปิดได้) และ `flutter analyze`

**commit:** `feat(app): add swipeable onboarding screen with dots, skip and next`

---

## C7: route และแสดงหน้าแนะนำหลังเข้าสู่ระบบ 🤖

**ไฟล์:** `app/lib/core/router/app_router.dart`, `app/lib/features/home/teacher_shell.dart`, `app/lib/features/student/student_shell.dart`

**ทำ:**
1. ใน `AppRoutes` เพิ่ม `static const welcome = '/welcome';` และ `static const studentWelcome = '/student/welcome';`
2. เพิ่ม `GoRoute` สองตัวที่ใช้ `OnboardingScreen()` ตัวเดียวกัน
3. ใน `TeacherShell` และ `StudentShell` (ใน `initState`) ใช้ `ref.listenManual(onboardingNeededProvider, ...)` เมื่อค่าเป็น `true` ให้ `context.push(...)` ไปหน้าแนะนำของบทบาทนั้น ทำแค่ครั้งเดียวต่อการเปิด shell (ใช้ตัวแปร `bool _onboardingShown`)
4. **ห้าม** ทำเป็น redirect ใน router เพราะ redirect ต้องทำงานแบบ synchronous แต่การอ่านค่าเป็น async

**ตรวจ:**
- ล็อกอินด้วยบัญชีครูตัวอย่างที่ยังไม่เคยดูหน้าแนะนำ (ไม่ต้องสมัครบัญชีใหม่ เพราะบัญชีใหม่ต้องรอผู้ดูแลอนุมัติ) หน้าแนะนำต้องขึ้น กดข้าม แล้ว refresh เบราว์เซอร์ ต้องไม่ขึ้นอีก ถ้าจะลองซ้ำให้รัน `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder` หรือใช้บัญชีครูคนที่สอง
- ทำซ้ำกับบัญชีนักเรียน
- รัน `flutter test test/router/` เพื่อให้แน่ใจว่า test ของ router เดิมยังผ่าน

**commit:** `feat(app): show onboarding once after sign-in for teachers and students`

---

## C8: เมนู "ดูวิธีใช้งานอีกครั้ง" 🧑

**ไฟล์:** `app/lib/features/settings/settings_screen.dart` (ครู) และ `app/lib/features/student/student_account_screen.dart` (นักเรียน)

**ทำ:** เพิ่ม `ListTile` ที่มี `leading: Icon(Icons.slideshow_outlined)`, `title: Text('ดูวิธีใช้งานอีกครั้ง')` และ key `settings_replay_onboarding` กดแล้ว `context.push(AppRoutes.welcome)` (ฝั่งนักเรียนใช้ `studentWelcome`) ให้วางรูปแบบเดียวกับ `ListTile` อื่นในหน้านั้น

**ตรวจ:** กดจากหน้าตั้งค่าแล้วสไลด์ต้องขึ้น

**commit:** `feat(app): replay onboarding from settings and the student account`

---

## C9: test หน้าสไลด์ 🤖

**ไฟล์ใหม่:** `app/test/onboarding/onboarding_screen_test.dart`

**กรณีที่ต้องมี:**
1. ครูเห็นสไลด์แรกของครู นักเรียนเห็นสไลด์แรกของนักเรียน
2. กด "ถัดไป" 3 ครั้งแล้วปุ่มเปลี่ยนเป็น "เริ่มใช้งาน"
3. กด "ข้าม" แล้ว `markDone` ถูกเรียก (override `onboardingStoreProvider` ด้วย store ที่ใช้ `InMemoryTokenStorage` แล้วเช็กค่า)
4. ที่ขนาด 390×844, 820×1180 และ 1440×900 ไม่มี overflow (`tester.takeException()` ต้องเป็น `null`)

ดูวิธีตั้งขนาดจอและ override provider จาก `app/test/notes/teacher_notes_screen_test.dart`

**ตรวจ:** `flutter test test/onboarding/`

**commit:** `test(app): onboarding screen flow and layouts`

---

## C10: ServerHealth model และ repository (REST) 🤖

**ไฟล์ใหม่:** `app/lib/features/about/server_health.dart`

**ทำ:**
```dart
enum ServerState { ok, degraded, unreachable }

class ServerHealth {
  const ServerHealth({required this.state, this.queueLastRunAt});
  final ServerState state;
  final DateTime? queueLastRunAt; // UTC
  factory ServerHealth.fromJson(Map<String, dynamic> json) { ... }
}

abstract class HealthRepository { Future<ServerHealth> fetch(); }

class ApiHealthRepository implements HealthRepository {
  ApiHealthRepository(this._dio);
  final Dio _dio;
  @override
  Future<ServerHealth> fetch() async { ... }
}

final healthRepositoryProvider = Provider<HealthRepository>(
    (ref) => ApiHealthRepository(ref.watch(dioProvider)));
final serverHealthProvider = FutureProvider.autoDispose<ServerHealth>(
    (ref) => ref.watch(healthRepositoryProvider).fetch());
```
**เรียก API:** `_dio.get<Object?>('/health', options: Options(validateStatus: (s) => s != null && s < 600))` เพราะถ้าฐานข้อมูลล่ม server ตอบ 503 แต่ยังมี JSON กลับมา

**แปลงผล:**
- `status == 'ok'` → `ServerState.ok`
- `'degraded'` → `ServerState.degraded`
- `DioException` (ไม่มีเน็ต หรือ server ไม่ตอบ) หรือ `db == 'error'` → `ServerState.unreachable`
- `queue_last_run_at` → `DateTime.tryParse(...)?.toUtc()`

**ตรวจ:** `flutter analyze`

**commit:** `feat(app): read server health from GET /health`

---

## C11: test ของ repository สถานะเซิร์ฟเวอร์ 🤖

**ไฟล์ใหม่:** `app/test/about/server_health_test.dart`

**ใช้:** `app/test/helpers/fake_http_adapter.dart` เพื่อจำลองคำตอบของ Dio

**กรณีที่ต้องมี:**
1. 200 + `ok` → `ok` และอ่านเวลาได้
2. 200 + `degraded` + เวลาเป็น `null`
3. 503 + `db: error` → `unreachable`
4. การเชื่อมต่อล้ม → `unreachable`

**commit:** `test(app): server health parsing and failures`

---

## C12: หน้าเกี่ยวกับ: ข้อมูลแอปและทีม 🧑🤖

**ไฟล์ใหม่:** `app/lib/features/about/about_screen.dart`

**ทำ:** `AboutScreen` เป็น `Scaffold` + `AppBar(title: Text('เกี่ยวกับ Krucheck'))` และ `ListView` ที่จำกัดความกว้างไม่เกิน 720 และใช้ `context.pageGutter` เป็นระยะขอบ

**เนื้อหาเรียงจากบนลงล่าง:**
1. ชื่อ "Krucheck" (`headlineMedium`) และคำอธิบาย 1–2 บรรทัด
2. หัวข้อ "ทีมผู้พัฒนา" แสดงรายชื่อและบทบาทเป็น `ListTile` (🧑 ใส่ชื่อจริงของทีมเอง)
3. หัวข้อ "รายวิชา" บอกชื่อวิชาและสถาบัน
4. หัวข้อ "เทคโนโลยี" แสดง `Wrap` ของ `Chip`: Flutter, Laravel, MariaDB, Firestore, Gemini, Google Classroom API

**ตรวจ:** ดูที่ 390, 820 และ 1440 px (ใช้ DevTools ของ Chrome ปรับขนาดได้)

**commit:** `feat(app): add about screen with team, course and tech stack`

---

## C13: การ์ดสถานะเซิร์ฟเวอร์ 🤖

**ไฟล์ใหม่:** `app/lib/features/about/server_status_card.dart` แล้ววางการ์ดนี้ไว้บนสุดของหน้า `AboutScreen`

**ทำ:** `ServerStatusCard extends ConsumerWidget` ใช้ `ref.watch(serverHealthProvider)`

| สถานะ | ไอคอน | สี | ข้อความ |
|---|---|---|---|
| กำลังโหลด | `SmallSpinner` (จาก `core/widgets/small_spinner.dart`) | | "กำลังตรวจสอบ…" |
| `ok` | `Icons.check_circle_outline` | `colorScheme.success` | "เซิร์ฟเวอร์ทำงานปกติ" |
| `degraded` | `Icons.warning_amber_outlined` | `colorScheme.warning` | "เซิร์ฟเวอร์มีปัญหาบางส่วน (คิวงานไม่ได้รันตามปกติ)" |
| `unreachable` หรือ error | `Icons.cloud_off_outlined` | `colorScheme.error` | "เชื่อมต่อเซิร์ฟเวอร์ไม่ได้" |

- บรรทัดล่าง: "คิวงานรันล่าสุด " + `formatThaiDateTime(queueLastRunAt!.toLocal())` หรือ "—" ถ้าไม่มีค่า
- ปุ่ม `TextButton.icon` "ตรวจอีกครั้ง" (key `about_health_retry`) กดแล้ว `ref.invalidate(serverHealthProvider)`

**ตรวจ:** ลองปิด backend แล้วกด "ตรวจอีกครั้ง" สถานะต้องเปลี่ยนเป็น "เชื่อมต่อไม่ได้"

**commit:** `feat(app): server status card with retry on the about screen`

---

## C14: บรรทัด "ดูวิธีใช้งานครั้งแรกเมื่อ…" จาก Firestore และเมนูเข้าหน้าเกี่ยวกับ 🤖

**ไฟล์:** `about_screen.dart`, `onboarding_store.dart`, `app_router.dart`, `settings_screen.dart`, `student_account_screen.dart`

**ทำ:**
1. ใน `onboarding_store.dart` เพิ่ม `onboardingInfoProvider = FutureProvider.autoDispose((ref) => ref.watch(onboardingStoreProvider)?.doneInfo())`
2. ใน `AboutScreen` เพิ่มบรรทัดใต้ข้อมูลแอป แสดงตามค่าที่ได้:
   - มีค่า → "คุณดูวิธีใช้งานครั้งแรกเมื่อ {formatThaiDateTime} จาก{อุปกรณ์}" โดยแปลง `android` → "มือถือ Android", `ios` → "iPhone/iPad", `web` → "เว็บเบราว์เซอร์"
   - ไม่มีค่าหรือเป็น `null` (ไม่มี Firestore) → ไม่ต้องแสดงบรรทัดนี้
3. เพิ่ม route `/about` และ `/student/about` และ `ListTile` "เกี่ยวกับ Krucheck" (`Icons.info_outline`, key `settings_about`) ในหน้าตั้งค่าทั้งสองหน้า

**ตรวจ:** ถ้ามีบัญชีบน server ทดสอบที่เปิด Firestore แล้ว ดูหน้าแนะนำบนเว็บ แล้วเปิดแอปบนมือถือ ต้องเห็นบรรทัดนี้ว่าดูจาก "เว็บเบราว์เซอร์"

**commit:** `feat(app): show where onboarding was finished, read from Firestore`

---

## C15: test หน้าเกี่ยวกับ 🤖

**ไฟล์ใหม่:** `app/test/about/about_screen_test.dart`

**กรณีที่ต้องมี:**
1. override `healthRepositoryProvider` ด้วย repository ปลอม แล้วตรวจข้อความของ 3 สถานะ
2. กด "ตรวจอีกครั้ง" แล้ว repository ถูกเรียกซ้ำ
3. override `onboardingStoreProvider` ด้วย store ที่ใช้ `FakeFirebaseFirestore` และมีเอกสารอยู่แล้ว ต้องเห็นบรรทัด "ดูวิธีใช้งานครั้งแรกเมื่อ"
4. ที่ 3 ขนาดจอไม่มี overflow
5. เมนู `settings_about` เปิดหน้าเกี่ยวกับได้

**ตรวจ:** `flutter test` ทั้งชุด และ `flutter analyze` ต้องผ่าน

**commit:** `test(app): about screen states, Firestore line and layouts`

---

## C16: เอกสาร 🧑

**ไฟล์:** `docs/DESIGN.md` (เขียนเป็นภาษาไทย)

**ทำ:** เพิ่มหัวข้อใหม่ท้ายไฟล์ `## 41. หน้าแนะนำการใช้งานครั้งแรกและหน้าเกี่ยวกับ` โดยมี:
- 41.1 คืออะไรและทำไมต้องมี
- 41.2 เก็บที่ไหน: ตารางเครื่อง / Firestore แบบเดียวกับ §40.2
- 41.3 rules ที่เพิ่ม
- 41.4 หน้าเกี่ยวกับและ REST `/health`
- 41.5 test ที่มี

ถ้าเลข 41 ถูกใช้ไปแล้วบน main ให้ใช้เลขถัดไป และแก้คำว่า "DESIGN §41" ในคอมเมนต์โค้ดให้ตรงกัน

**commit:** `docs: describe onboarding, settings/app and the about screen`

---

## เสร็จเมื่อ

- [ ] ครูและนักเรียนเห็นหน้าแนะนำครั้งเดียวต่อบัญชี ข้ามเครื่องได้เมื่อ Firestore เปิด
- [ ] ออฟไลน์หรือ build ที่ไม่มี Firebase ยังใช้งานได้ และไม่ crash
- [ ] หน้าเกี่ยวกับแสดงสถานะเซิร์ฟเวอร์ครบ 3 แบบจาก REST และบรรทัดที่มาจาก Firestore
- [ ] ทุกหน้าดูดีที่ 390, 820 และ 1440 px
- [ ] `flutter analyze` และ `flutter test` ผ่าน และ CI ใน PR เขียว
- [ ] มีอย่างน้อย 10 commit ทุก commit มาจากบัญชีของตัวเอง
- [ ] แจ้ง phuwishpk ให้ Publish rules หลัง merge
