# งานที่ 2: ฟีดห้องเรียนและเช็คชื่อแบบสด

- **คนทำ:** Suwimon249 หน้าติดตามงานคือ #248
- **branch:** `feat/live-class-feed` แตกจาก `main` ล่าสุด
- **PR:** เปิดเข้า `main` PR เดียวตลอดงาน
- **ขนาด:** 18 commit (backend 8, แอป 9, docs 1)
- **ภาษา:** PHP (Laravel) และ Dart (Flutter)
- **เครื่อง:** แนะนำ RAM 8 GB ขึ้นไป หรือใช้ GitHub Codespaces (ดู `00-START-HERE.md`)
- **ที่มา:** ขั้น 4 ของ DESIGN §38.3 ที่ยังไม่ได้ทำ

## ภาพรวม

ตอนนี้ครูโพสต์หรือเช็คชื่อแล้ว นักเรียนต้องดึงหน้าจอลงเพื่อรีเฟรชเอง งานนี้ทำให้หน้าจอเปลี่ยนเองโดยใช้วิธีเดียวกับสถานะการตรวจการบ้าน (DESIGN §38.9): **Firestore บอกแค่ว่า "มีอะไรเปลี่ยน" แล้วแอปไปดึงข้อมูลจริงจาก REST**

| ส่วน | ใช้อะไร | ใครอ่าน/เขียน |
|---|---|---|
| โพสต์ การบ้าน และผลตรวจของนักเรียน | REST `GET /student/class-feed` (มีอยู่แล้ว) | แอปนักเรียนอ่าน |
| การเข้าเรียนของนักเรียน | REST `GET /student/attendance` (มีอยู่แล้ว) | แอปนักเรียนอ่าน |
| รายการโพสต์ของครู | REST รายการโพสต์ของห้อง (มีอยู่แล้ว) | แอปครูอ่าน |
| สัญญาณ "ห้องนี้มีความเคลื่อนไหว" | Firestore `users/{uid}/class_feed/{classroomId}` (ใหม่) | คิวของ backend เขียน แอปนักเรียนและครูฟัง |
| สัญญาณ "การเช็คชื่อเปลี่ยน" | Firestore `users/{uid}/attendance/{courseId}_{classroomId}` (ใหม่) | คิวของ backend เขียน แอปนักเรียนฟัง |

**ลำดับการทำงานที่ต้องได้:**
1. นักเรียนเปิดหน้า "วิชาของฉัน" ค้างไว้
2. ครูโพสต์ประกาศ → การ์ด "โพสต์ล่าสุด" ขึ้นรายการใหม่เองโดยไม่ต้องรีเฟรช
3. ถ้านักเรียนกำลังเลื่อนอ่านในแท็บ "โพสต์" จะเห็นปุ่ม "มีรายการใหม่ 1 รายการ" กดแล้วกลับขึ้นบนสุด
4. ครูแก้หรือลบโพสต์ → หน้าจอนักเรียนเปลี่ยนตาม
5. ครูเช็คชื่อ → การ์ด "การเข้าเรียนวันนี้" บนหน้าแรกแสดงสถานะ เช่น "มาสาย" เอง
6. ครูอีกคนของห้องที่เปิดหน้าโพสต์ค้างไว้เห็นโพสต์ใหม่เอง
7. ถ้า Firestore ปิดหรือออฟไลน์ แอปทำงานแบบเดิม (ดึงลงเพื่อรีเฟรช) ไม่มี error และไม่มีป้าย "อัปเดตสด"

**ความเร็วจริง:** backend เขียน Firestore ผ่านคิว บนเครื่องที่เปิด `php artisan queue:work` จะเห็นผลในไม่กี่วินาที บน server ทดสอบราวครึ่งนาที และบน production ไม่เกินราว 1 นาที เพราะ worker รันเป็นรอบ ให้บอกตามจริงตอนเดโม

## ทำไมไม่ใช้ `classrooms/{id}/...` ตาม §38.2

แถวขั้น 4 ใน §38.2 เขียนว่าจะเก็บ `classrooms/{id}/feed`, `members` และ `attendance` งานนี้เปลี่ยนเป็นสัญญาณใต้ `users/{uid}` เพราะของเดิมมีปัญหาจริง และต้องแก้ DESIGN ใน PR เดียวกันตาม CLAUDE.md (ทำใน C18)

| เรื่อง | ถ้าใช้ `classrooms/{id}` | เมื่อใช้สัญญาณใต้ `users/{uid}` |
|---|---|---|
| โพสต์ที่เจาะจงนักเรียน (`audience = students`) | ทุกคนในห้องอ่านหัวข้อโพสต์ได้ | เอกสารไม่มีเนื้อหา REST กรองผู้รับให้เหมือนเดิม |
| เช็คชื่อ | rules ซ่อนรายฟิลด์ไม่ได้ เพื่อนในห้องอ่านสถานะกันได้ | สถานะมาจาก REST เฉพาะของตัวเอง |
| รายชื่อสมาชิก | ต้องตาม `members` ทุกทางที่สมาชิกเปลี่ยน รวมการรวมบัญชีและลบบัญชีในส่วน admin | ไม่มี `members` อ่านรายชื่อจาก MariaDB ตอนส่ง |
| Firestore rules | ต้องแก้และกด Publish | rule เดิมครอบอยู่แล้ว ไม่ต้องแก้ |
| ลบบัญชี | ต้องตามลบในทุกห้อง | `PurgeLiveUserJob` ลบทุก collection ใต้ `users/{uid}` อยู่แล้ว |
| จำนวนเอกสาร | เพิ่มทุกโพสต์และทุกคาบ | คงที่ ต่อห้องหรือต่อวิชาเท่านั้น |

**รูปแบบเอกสาร (ห้ามมี field อื่น):**
```
users/{uid}/class_feed/{classroomId}
  classroom_id: 12
  updated_at:   Timestamp
  expires_at:   Timestamp (+90 วัน)

users/{studentUid}/attendance/{courseId}_{classroomId}
  course_id:    3
  classroom_id: 12
  updated_at:   Timestamp
  expires_at:   Timestamp (+90 วัน)
```

**สิ่งที่ห้ามทำ:**
- แตะส่วน admin ทุกอย่าง (ดูหัวข้อ 0 ของ `00-START-HERE.md`) รวมถึง `PurgeLiveUserJob` และ `backend/app/Domain/Accounts/`
- ใส่หัวข้อ เนื้อหา ชื่อคน สถานะการเข้าเรียน หรือคะแนนลงในเอกสารสองชนิดนี้
- สร้างเอกสารใต้ `classrooms/` (ขั้น 5 ใน DESIGN §38.10 ใช้ที่นี่แบบ server อ่านได้เท่านั้น งานนี้ไม่เกี่ยวและห้ามแตะ)
- แก้ `firebase/firestore.rules`
- ให้แอปเขียน Firestore
- เรียก `LiveStore` ระหว่าง HTTP request (ต้องผ่าน job เท่านั้น)
- เพิ่มตาราง คอลัมน์ endpoint หรือ package ใหม่

## ตารางความคืบหน้า (ติ๊ก [x] เมื่อ commit แล้ว)

**Backend**
- [x] C1 LiveClassroom: ผู้รับและ field ของสัญญาณ (#286)
- [x] C2 SignalClassFeedJob (#287)
- [x] C3 test ของ LiveClassroom และ job (#288)
- [x] C4 ส่งสัญญาณเมื่อโพสต์เผยแพร่ แก้ หรือลบ (#289)
- [ ] C5 ส่งสัญญาณเมื่อการบ้านพร้อมส่ง (#290)
- [ ] C6 test ของจุดส่งสัญญาณ (#291)
- [ ] C7 SignalAttendanceJob และการเช็คชื่อ (#292)
- [ ] C8 test การเช็คชื่อและ security test (backend) (#293)

**แอป**
- [ ] C9 provider ของสัญญาณ (#294)
- [ ] C10 แอปนักเรียนดึงข้อมูลใหม่เอง (#295)
- [ ] C11 หน้าโพสต์ของครูดึงข้อมูลใหม่เอง (#296)
- [ ] C12 ป้าย "อัปเดตสด" (#297)
- [ ] C13 ปุ่ม "มีรายการใหม่" (#298)
- [ ] C14 การ์ด "การเข้าเรียนวันนี้" (#299)
- [ ] C15 widget test และ 3 ขนาดจอ (#300)
- [ ] C16 integration test (#301)
- [ ] C17 security test (แอป) (#302)

**เอกสาร**
- [ ] C18 DESIGN (#303)

---

## ความเกี่ยวข้องกับเกณฑ์ประเมิน 20 คะแนน

| เกณฑ์ | คะแนน | งานนี้ช่วยอย่างไร | หลักฐานในวันนำเสนอ |
|---|---|---|---|
| 1. UI Material 3 + Usability | 3 | ป้าย ปุ่ม และการ์ดใหม่ใช้ `AppTheme` และรองรับ 3 ขนาดจอ | เปิดหน้าจอที่ 390, 820 และ 1440 px |
| 2. REST / Firebase ≥ 2 บริการ | 3 | REST ให้ข้อมูลจริง Firestore ให้สัญญาณสด สองบริการทำหน้าที่ต่างกันชัดเจน | ครูโพสต์ แล้วนักเรียนเห็นเองโดยไม่แตะหน้าจอ |
| 3. AI Feature | 3 | ไม่เกี่ยวโดยตรง ต้องไม่ทำให้ flow การตรวจเสีย | ใช้เดโม AI ของส่วนอื่น |
| 4. Local DB + Firestore | 3 | เพิ่มข้อมูลที่อ่านจาก Firestore จริง ส่วนฐานข้อมูลในเครื่องอยู่ที่ส่วนอื่น | เปิด Firebase console ให้เห็นเอกสาร `class_feed` |
| 5. Test 4 ประเภท + Coverage | 5 | Unit (C3, C9), Widget (C15), Integration (C16), Security (C8, C17) | เปิดผล coverage ใน GitHub Actions |
| 6. GitHub Actions | 3 | ทุก PR ต้องเขียว แอป coverage ≥ 70% และ backend ≥ 85% | เปิดแท็บ Checks ของ PR |

**เดโม:** เปิดเครื่องนักเรียนกับเครื่องครูคู่กัน ครูโพสต์ รอสัญญาณจากคิว แล้วให้ดูว่านักเรียนไม่ได้แตะหน้าจอ จากนั้นครูเช็คชื่อให้นักเรียนคนนี้ "มาสาย" แล้วชี้การ์ด "การเข้าเรียนวันนี้" ปิดท้ายด้วยเปิด Firebase console ให้เห็นว่าเอกสารมีแค่ id และเวลา

---

## ความรู้ที่ต้องมีก่อนเริ่ม (อ่าน 20 นาที)

**Backend**

| เรื่อง | ไฟล์ |
|---|---|
| หลักการชั้นข้อมูลสด | `docs/DESIGN.md` §38.1, §38.2 และ §38.9 |
| ตัวอย่างที่ต้องทำตาม | `backend/app/Domain/Firestore/LiveGrading.php`, `backend/app/Jobs/MirrorSubmissionStatusJob.php` |
| ที่เขียน Firestore | `backend/app/Domain/Firestore/LiveStore.php`, `FirestoreUid.php`, `FirestoreClient::MAX_WRITES` |
| จุดจับการเปลี่ยนใน model | `Submission::booted()` ใน `backend/app/Models/Submission.php` |
| โพสต์ | `backend/app/Domain/ClassPosts/ClassPosts.php`, `backend/app/Http/Controllers/Api/V1/ClassPostController.php`, `backend/app/Events/ClassPostPublished.php` |
| ตัวอย่าง listener | `backend/app/Listeners/NotifyStudentsOfClassPost.php` (Laravel ค้นเจอ listener ในโฟลเดอร์นี้เอง ไม่ต้องลงทะเบียน) |
| เช็คชื่อ | `backend/app/Http/Controllers/Api/V1/AttendanceController.php` |
| ใครเป็นครูของห้อง | `ClassroomAccess::classrooms()` ใน `backend/app/Domain/Classrooms/ClassroomAccess.php` |
| test ของ Firestore | `backend/tests/Feature/Firestore/LiveGradingTest.php`, `FirestoreFixtures.php`, `backend/tests/Support/RecordingLiveStore.php` |
| test ของ API ที่เกี่ยวข้อง | `backend/tests/Feature/Api/ClassPostTest.php`, `AttendanceTest.php` |
| test helper | `backend/tests/TestCase.php` (`makeTeacher`, `makeClassroom`, `makeCourse`, `enrollStudent`, `tokenFor`) |

**แอป**

| เรื่อง | ไฟล์ |
|---|---|
| ฟัง Firestore | `app/lib/core/live/live_scope.dart` (`liveUserScope`, `liveChangeTicks`), `app/lib/features/student/live_refresh.dart` |
| ตัวอย่างการ refetch | `app/lib/features/student/student_shell.dart` (`ref.listen(liveSubmissionTicksProvider, ...)`) |
| ฟีดนักเรียน | `app/lib/features/class_posts/student_class_feed.dart`, `class_posts_repository.dart` (`classFeedProvider`) |
| หน้าโพสต์ของครู | `app/lib/features/class_posts/class_posts_screen.dart` (`classroomPostsProvider`) |
| เช็คชื่อของนักเรียน | `app/lib/features/attendance/my_attendance_screen.dart`, `attendance_repository.dart` (`myAttendanceProvider`), `attendance_models.dart` (`MyAttendanceCourse`, `AttendanceStatus.color`) |
| หน้าแรกนักเรียน | `app/lib/features/student/my_subjects_page.dart` |
| test | `app/test/live/live_grading_test.dart`, `live_test_support.dart`, `app/test/student/student_shell_test.dart`, `student_fixtures.dart`, `app/test/class_posts/class_post_fakes.dart` (`feedKeys` นับการดึงฟีด), `app/test/helpers/pump_screen.dart` |

**กติกา:**
- PHP ห้ามใช้ syntax ที่ใหม่กว่า 8.3
- รหัสผ่านใน test ใช้ `'secret1234'` เท่านั้น
- ทุก commit จบด้วยบรรทัด `Closes #เลข` ของ commit นั้น เลขอยู่ในตารางความคืบหน้า
- ห้ามรัน `composer update`
- UI ห้ามกำหนดสี ฟอนต์ หรือมุมโค้งเองในหน้าจอ (DESIGN §27)
- เวลาเก็บเป็น UTC และแสดงเป็นเวลาไทย

---

## C1: LiveClassroom: ผู้รับและ field ของสัญญาณ 🤖

**ไฟล์ใหม่:** `backend/app/Domain/Firestore/LiveClassroom.php`

**ทำ:**
```php
<?php

namespace App\Domain\Firestore;

use App\Models\ClassroomTeacher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Live class feed and attendance (DESIGN §38.11, step 4). A document here is
 * only a signal: "this classroom changed, ask the API again". It holds ids
 * and times, never a title, a body, a name, a status or a score, because
 * MariaDB stays the truth and a post may reach only some of the students.
 */
final class LiveClassroom
{
    /** @return list<int> */
    public static function studentIds(int $classroomId): array
    {
        return DB::table('classroom_students')
            ->where('classroom_id', $classroomId)
            ->orderBy('student_id')
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The same teachers ClassroomAccess::classrooms() lets in: homeroom,
     * creators of a bound course, and co-teachers who accepted.
     *
     * @return list<int>
     */
    public static function teacherIds(int $classroomId): array
    {
        $ids = [
            ...DB::table('classrooms')->where('id', $classroomId)->pluck('teacher_id')->all(),
            ...DB::table('course_classroom')
                ->join('courses', 'courses.id', '=', 'course_classroom.course_id')
                ->where('course_classroom.classroom_id', $classroomId)
                ->pluck('courses.created_by')->all(),
            ...ClassroomTeacher::query()
                ->where('classroom_id', $classroomId)
                ->where('status', ClassroomTeacher::STATUS_ACCEPTED)
                ->whereNotNull('teacher_id')
                ->pluck('teacher_id')->all(),
        ];

        return array_values(array_unique(array_map(intval(...), array_filter($ids, fn ($id) => $id !== null))));
    }

    /** @return array<string, mixed> */
    public static function feedFields(int $classroomId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'classroom_id' => $classroomId,
            'updated_at' => $now,
            'expires_at' => $now->addDays(LiveGrading::RETENTION_DAYS),
        ];
    }

    /** @return array<string, mixed> */
    public static function attendanceFields(int $courseId, int $classroomId, ?CarbonImmutable $now = null): array
    {
        return ['course_id' => $courseId] + self::feedFields($classroomId, $now);
    }
}
```

**ตรวจ:** `php artisan test --filter=Firestore` และ `vendor/bin/pint`

**commit:** `feat(backend): add LiveClassroom for signal recipients and fields`

---

## C2: SignalClassFeedJob 🤖

**ไฟล์ใหม่:** `backend/app/Jobs/SignalClassFeedJob.php` แก้ `LiveClassroom.php`

**ทำ:**
1. สร้าง job ตามแบบ `MirrorSubmissionStatusJob`: `ShouldBeUniqueUntilProcessing`, `ShouldQueue`, `$tries = 1`, `$timeout = 30`, `$uniqueFor = 300`, `onQueue('default')` และ `uniqueId()` คืนเลขห้อง
2. `handle(LiveStore $store)`:
   ```php
   $fields = LiveClassroom::feedFields($this->classroomId);
   $userIds = [...LiveClassroom::studentIds($this->classroomId), ...LiveClassroom::teacherIds($this->classroomId)];
   $ops = array_map(fn (int $id) => [
       'path' => FirestoreUid::path($id).'/class_feed/'.$this->classroomId,
       'fields' => $fields,
   ], array_values(array_unique($userIds)));
   foreach (array_chunk($ops, FirestoreClient::MAX_WRITES) as $chunk) {
       $store->batch($chunk);
   }
   ```
3. ใน `LiveClassroom` เพิ่ม:
   ```php
   /** A post or a homework of the classroom changed; a no-op while Firestore is off. */
   public static function feedChanged(int $classroomId): void
   {
       if (! FirestoreConfig::enabled()) {
           return;
       }
       SignalClassFeedJob::dispatch($classroomId)->afterCommit();
   }
   ```

ส่งสัญญาณให้ทุกคนในห้อง แม้โพสต์จะเจาะจงนักเรียนบางคน เพราะเอกสารไม่มีเนื้อหา และ REST กรองผู้รับให้แล้ว ให้เขียนเหตุผลนี้ไว้ในคอมเมนต์ของ job

**ตรวจ:** `vendor/bin/pint`

**commit:** `feat(backend): queue a class feed signal for everyone in the classroom`

---

## C3: test ของ LiveClassroom และ job 🤖

**ไฟล์ใหม่:** `backend/tests/Feature/Firestore/LiveClassroomTest.php` (ใช้ `RefreshDatabase` และ `FirestoreFixtures` ตั้งค่าแบบ `LiveGradingTest`)

**กรณีที่ต้องมี:**
1. `studentIds` ได้เฉพาะนักเรียนของห้องนั้น
2. `teacherIds` ได้ครูประจำห้อง เจ้าของรายวิชาที่ผูกกับห้อง และครูร่วมสอนที่ตอบรับแล้ว ไม่รวมคำเชิญที่ยังไม่ตอบ และไม่มีเลขซ้ำ
3. รัน job กับ `RecordingLiveStore`: `paths()` มี `users/u{id}/class_feed/{ห้อง}` ครบทุกคน และไม่มีเลขซ้ำแม้ครูประจำห้องจะเป็นเจ้าของรายวิชาด้วย
4. ทุกเอกสารมีแค่ `classroom_id`, `updated_at`, `expires_at` และ `expires_at` ห่างจาก `updated_at` 90 วัน
5. `Queue::fake()` แล้วเรียก `feedChanged` 3 ครั้ง ได้ job เดียว (ดูวิธีตรวจจาก test แรกของ `LiveGradingTest`)
6. `enableFirestore(false)` แล้วไม่มี job ถูกส่ง

**ตรวจ:** `php artisan test --filter=LiveClassroomTest` และ `vendor/bin/pint`

**commit:** `test(backend): cover class feed signal recipients and fields`

---

## C4: ส่งสัญญาณเมื่อโพสต์เผยแพร่ แก้ หรือลบ 🤖

**ไฟล์ใหม่:** `backend/app/Listeners/SignalClassFeedOfClassPost.php`

**ไฟล์:** `backend/app/Http/Controllers/Api/V1/ClassPostController.php`, `backend/app/Events/ClassPostPublished.php`

**ทำ:**
1. listener แบบไม่ต้อง `ShouldQueue` เพราะแค่ dispatch job ต่อ:
   ```php
   public function handle(ClassPostPublished $event): void
   {
       $classroomId = ClassPost::query()->whereKey($event->postId)->value('classroom_id');
       if ($classroomId !== null) {
           LiveClassroom::feedChanged((int) $classroomId);
       }
   }
   ```
   จุดนี้ครอบทั้งโพสต์ทันทีและโพสต์ตั้งเวลา (`ClassPosts::publishDue`) เพราะทั้งคู่ผ่าน `ClassPosts::publish`
2. ใน `ClassPostController::update` ก่อนจบ closure ของ transaction:
   ```php
   if ($alreadyPublished) {
       LiveClassroom::feedChanged($post->classroom_id);
   }
   ```
3. ใน `ClassPostController::destroy` หลัง `$post->delete();`:
   ```php
   if ($post->isPublished()) {
       LiveClassroom::feedChanged($post->classroom_id);
   }
   ```
4. เพิ่มชื่อ listener ในคอมเมนต์ `Listeners:` ของ `ClassPostPublished`

**ห้าม** ส่งสัญญาณเมื่อแก้ฉบับร่าง เพราะนักเรียนยังไม่เห็นโพสต์นั้น

**ตรวจ:** `php artisan test --filter=ClassPostTest` และ `vendor/bin/pint`

**commit:** `feat(backend): signal the class feed when a post is published, edited or deleted`

---

## C5: ส่งสัญญาณเมื่อการบ้านพร้อมส่ง 🤖

**ไฟล์:** `backend/app/Models/Assignment.php`

**ทำ:** สถานะการบ้านเปลี่ยนจากหลายที่ จึงจับที่ model จุดเดียวแบบ `Submission::booted()` ไฟล์นี้มี `booted()` อยู่แล้ว ให้เพิ่มบล็อกข้างล่างต่อจาก `static::updated(...)` ของเดิม ห้ามสร้าง `booted()` ใหม่ และห้ามลบของเดิม เพราะ `LiveRecords` ใช้อยู่ (DESIGN §38.10) แล้วเพิ่ม `use App\Domain\Firestore\LiveClassroom;`
```php
// Homework that opens or closes changes the student's class feed
// (DESIGN §38.11); a no-op while Firestore is off.
static::saved(function (Assignment $assignment) {
    $opened = $assignment->wasRecentlyCreated && $assignment->isReady();
    if (($opened || $assignment->wasChanged('status'))
        && $assignment->kind === self::KIND_HOMEWORK
        && $assignment->classroom_id !== null) {
        LiveClassroom::feedChanged((int) $assignment->classroom_id);
    }
});
```
ผลตรวจในฟีดไม่ต้องส่งสัญญาณเพิ่ม เพราะ `users/{uid}/submissions` ส่งอยู่แล้ว ส่วนแอปจะฟังเพิ่มใน C10

**ตรวจ:** `php artisan test --filter=Assignment` และ `vendor/bin/pint`

**commit:** `feat(backend): signal the class feed when homework opens or closes`

---

## C6: test ของจุดส่งสัญญาณ 🤖

**ไฟล์ใหม่:** `backend/tests/Feature/Firestore/LiveClassFeedTest.php` (ใช้ `Queue::fake()` และ `enableFirestore(true)` ดูวิธีเรียก API จาก `ClassPostTest`)

**กรณีที่ต้องมี:**
1. ครูโพสต์ทันที → มี `SignalClassFeedJob` ของห้องนั้น
2. บันทึกฉบับร่าง → ไม่มี job
3. โพสต์ตั้งเวลา → ยังไม่มี job จนกว่าจะเรียก `ClassPosts::publishDue()` หลังถึงเวลา (ใช้ `$this->travelTo(...)`)
4. แก้โพสต์ที่เผยแพร่แล้ว → มี job, แก้ฉบับร่าง → ไม่มี
5. ลบโพสต์ที่เผยแพร่แล้ว → มี job, ลบฉบับร่าง → ไม่มี
6. การบ้าน: สถานะเป็น `ready` → มี job, สร้างเป็น `draft` → ไม่มี, ข้อสอบ (`kind` ไม่ใช่ homework) → ไม่มี, ปิดกลับเป็น `draft` → มี
7. `enableFirestore(false)` แล้วทำข้อ 1 → ไม่มี job และ API ยังตอบสำเร็จ

**ตรวจ:** `php artisan test --filter=LiveClassFeedTest` และ `php artisan test` ทั้งชุด

**commit:** `test(backend): posts and homework signal the class feed only when students see them`

---

## C7: SignalAttendanceJob และการเช็คชื่อ 🤖

**ไฟล์ใหม่:** `backend/app/Jobs/SignalAttendanceJob.php`

**ไฟล์:** `backend/app/Domain/Firestore/LiveClassroom.php`, `backend/app/Http/Controllers/Api/V1/AttendanceController.php`

**ทำ:**
1. job รับ `int $courseId, int $classroomId` ตั้งค่าเหมือน C2 แต่ `uniqueId()` คืน `"{$courseId}:{$classroomId}"`
2. `handle` เขียน `FirestoreUid::path($id).'/attendance/'.$courseId.'_'.$classroomId` ให้นักเรียนเท่านั้น (`LiveClassroom::studentIds`) ด้วย `LiveClassroom::attendanceFields(...)` แบ่งชุดด้วย `array_chunk` เหมือน C2
3. ใน `LiveClassroom` เพิ่ม `attendanceChanged(int $courseId, int $classroomId)` แบบเดียวกับ `feedChanged`
4. ใน `AttendanceController` เรียกหลัง `DB::transaction` ทั้งสามเมธอด:
   - `store`: `LiveClassroom::attendanceChanged($course->id, $classroom->id);`
   - `update`: `LiveClassroom::attendanceChanged((int) $session->course_id, $classroom->id);`
   - `destroy`: `LiveClassroom::attendanceChanged((int) $session->course_id, (int) $session->classroom_id);`

เมธอด `scores` ไม่ต้องส่ง เพราะเปลี่ยนแค่ค่าคะแนน ไม่ได้เปลี่ยนสถานะ

**ตรวจ:** `php artisan test --filter=AttendanceTest` และ `vendor/bin/pint`

**commit:** `feat(backend): signal students when attendance changes`

---

## C8: test การเช็คชื่อและ security test (backend) 🤖

**ไฟล์ใหม่:**
- `backend/tests/Feature/Firestore/LiveAttendanceTest.php`
- `backend/tests/Feature/Firestore/LiveClassroomPrivacyTest.php`

**`LiveAttendanceTest`:**
1. สร้าง แก้ และลบคาบ → มี `SignalAttendanceJob` ทุกครั้ง
2. แก้ค่าคะแนนของสถานะ (`attendance/scores`) → ไม่มี job
3. รัน job กับ `RecordingLiveStore`: ได้ `users/u{id}/attendance/{วิชา}_{ห้อง}` ของนักเรียนทุกคน และไม่มีเอกสารของครู
4. `enableFirestore(false)` → ไม่มี job

**`LiveClassroomPrivacyTest` (นับเป็น Security test):**
1. โพสต์ที่มีหัวข้อ `หัวข้อลับ123` และเนื้อหา `เนื้อหาลับ456` แล้วรัน job: `json_encode($store->ops)` ต้องไม่มีสองคำนี้
2. เช็คชื่อนักเรียนเป็น `absent` พร้อมหมายเหตุ `เหตุผลลับ789` แล้วรัน job: ต้องไม่มี `absent` และหมายเหตุใน `ops`
3. `array_keys($op['fields'])` ของทุกเอกสารตรงกับรายการที่อนุญาตเป๊ะ
4. โพสต์ `audience = students` ให้นักเรียน A เท่านั้น: นักเรียน B ได้สัญญาณ แต่ `GET /api/v1/student/class-feed` ด้วย token ของ B ไม่มีโพสต์นั้น
5. นักเรียนห้องอื่นไม่ได้เอกสารเลย

**ตรวจ:** `php artisan test` ทั้งชุด และ `vendor/bin/pint`

**commit:** `test(backend): live signals carry ids and times only`

---

## C9: provider ของสัญญาณ 🤖

**ไฟล์:** `app/lib/features/student/live_refresh.dart`, `app/lib/core/live/live_scope.dart`

**ไฟล์ใหม่:** `app/test/live/live_class_signals_test.dart`

**ทำ:**
1. ใน `live_refresh.dart`:
   ```dart
   /// A classroom's posts or homework changed (DESIGN §38.11).
   final liveClassFeedTicksProvider = StreamProvider.autoDispose<int>(
     (ref) => liveChangeTicks(ref, 'class_feed'),
   );

   /// The student's attendance changed in a course (DESIGN §38.11).
   final liveAttendanceTicksProvider = StreamProvider.autoDispose<int>(
     (ref) => liveChangeTicks(ref, 'attendance'),
   );
   ```
2. ใน `live_scope.dart`:
   ```dart
   /// True while the live layer can be read, for the "อัปเดตสด" badge.
   final liveAvailableProvider = Provider.autoDispose<bool>(
     (ref) => liveUserScope(ref) != null,
   );
   ```
3. test ตามแบบ `change ticks start after the first snapshot` ใน `live_grading_test.dart`:
   - เอกสารที่มีอยู่ก่อนไม่นับเป็นการเปลี่ยน
   - เขียนเอกสารของตัวเองแล้วเลขเพิ่ม
   - เอกสารของ uid อื่นไม่ทำให้เลขเพิ่ม
   - live ปิดแล้วไม่มี tick และ `liveAvailableProvider` เป็น `false`

**ตรวจ:** `flutter analyze` และ `flutter test test/live/`

**commit:** `feat(app): listen for class feed and attendance signals`

---

## C10: แอปนักเรียนดึงข้อมูลใหม่เอง 🤖

**ไฟล์:** `app/lib/features/student/student_shell.dart`, `app/test/student/student_shell_test.dart`

**ทำ:**
1. ใน `ref.listen(liveSubmissionTicksProvider, ...)` เดิม เพิ่ม `ref.invalidate(classFeedProvider);` เพราะผลตรวจก็อยู่ในฟีด
2. เพิ่ม:
   ```dart
   // A post or homework changed (DESIGN §38.11): the feed refetches itself.
   ref.listen(liveClassFeedTicksProvider, (_, next) {
     if (!next.hasValue) return;
     ref.invalidate(classFeedProvider);
     ref.invalidate(studentAssignmentsProvider);
     ref.invalidate(studentOverviewProvider);
   });
   // The teacher took attendance (DESIGN §38.11).
   ref.listen(liveAttendanceTicksProvider, (_, next) {
     if (!next.hasValue) return;
     ref.invalidate(myAttendanceProvider);
   });
   ```
3. ใน `_liveRefreshTests` เพิ่ม test ตามแบบเดิมที่มีอยู่:
   - เขียน `users/u11/class_feed/7` แล้ว `FakeClassPosts.feedKeys` ยาวขึ้น
   - เขียน `users/u11/attendance/3_7` แล้ว `FakeAttendance` ถูกเรียกอีกครั้ง

ใน test นี้ให้ override `attendanceRepositoryProvider` ด้วย class เล็ก ๆ ที่นับการเรียก `mine()` ไปก่อน แล้ว C14 จะย้าย `FakeAttendance` มาใช้ร่วมกัน

**ตรวจ:** `flutter analyze` และ `flutter test test/student/`

**commit:** `feat(app): student feed and attendance refetch on live signals`

---

## C11: หน้าโพสต์ของครูดึงข้อมูลใหม่เอง 🤖

**ไฟล์:** `app/lib/features/class_posts/class_posts_screen.dart`, `app/test/class_posts/class_posts_test.dart`

**ทำ:** ที่ต้นเมธอด `build` ของหน้าโพสต์ของห้อง:
```dart
// Another teacher of the classroom posted (DESIGN §38.11).
ref.listen(liveClassFeedTicksProvider, (_, next) {
  if (!next.hasValue) return;
  ref.invalidate(classroomPostsProvider(classroomId));
});
```

**test:** ใช้ `FakeFirebaseFirestore` กับ `FakeLiveSession` เปิดหน้าโพสต์ แล้วเพิ่มโพสต์ใน `FakeClassPosts.posts` และเขียน `users/u12/class_feed/7` ต้องเห็นโพสต์ใหม่โดยไม่ต้องรีเฟรช

**ไม่ต้องแก้ `teacher_shell.dart`** เพราะมีแค่หน้านี้ที่ใช้ข้อมูลชุดนี้ และ TASK-1 แก้ไฟล์นั้นอยู่

**ตรวจ:** `flutter analyze` และ `flutter test test/class_posts/`

**commit:** `feat(app): classroom posts refetch when another teacher posts`

---

## C12: ป้าย "อัปเดตสด" 🧑🤖

**ไฟล์ใหม่:** `app/lib/core/widgets/live_badge.dart`

**ไฟล์:** `student_class_feed.dart`, `class_posts_screen.dart`, `my_attendance_screen.dart`

**ทำ:**
- `LiveBadge extends ConsumerWidget` อ่าน `liveAvailableProvider` ถ้าเป็น `false` คืน `SizedBox.shrink()`
- ถ้าเป็น `true` แสดงไอคอน `Icons.bolt_outlined` ขนาด 16 สี `colorScheme.primary` กับข้อความ "อัปเดตสด" แบบ `textTheme.labelSmall` (ดูหน้าตาจาก `LiveGradingProgressCard`)
- key: `live_badge`
- วางในแถวเดียวกับปุ่ม "สตรีม / งานของชั้นเรียน" ใช้ `Wrap` เพื่อให้ตกบรรทัดได้บนจอแคบ
- หน้าโพสต์ของครู: วางเหนือรายการ
- หน้า "การเข้าเรียนของฉัน": วางใน `AppBar.actions`
- 🧑 ลองจัดตำแหน่งแล้วดูที่ 390 px เอง

**ตรวจ:** `flutter analyze` และเปิดดูใน Chrome

**commit:** `feat(app): live badge on the feed, classroom posts and attendance`

---

## C13: ปุ่ม "มีรายการใหม่" 🧑🤖

**ไฟล์:** `app/lib/features/class_posts/student_class_feed.dart`

**ทำไมต้องมี:** ถ้าข้อมูลใหม่ดันรายการลงขณะนักเรียนอ่านอยู่ จะเสียตำแหน่ง จึงบอกด้วยปุ่มแทน

**ทำ:** ใน `_StudentClassFeedBodyState`:
1. เพิ่ม `final _scroll = ScrollController();` ส่งให้ `ListView` และ `dispose` ใน `dispose()`
2. เพิ่ม `Set<String>? _seen` และ `int _fresh = 0`
3. key ของรายการ: `'${item.type.name}_${item.post?.id ?? item.assignmentId ?? item.submissionId}'`
4. ใน `build`:
   ```dart
   ref.listen(classFeedProvider(_key), (_, next) {
     final items = next.value;
     if (items == null) return;
     final keys = {for (final i in items) _itemKey(i)};
     final seen = _seen;
     _seen = keys;
     if (seen == null) return;
     final added = keys.difference(seen).length;
     final reading = _scroll.hasClients && _scroll.offset > 0;
     if (added > 0 && reading) setState(() => _fresh += added);
   });
   ```
5. ครอบ `RefreshIndicator` ด้วย `Stack` และถ้า `_fresh > 0` ให้วางปุ่มกึ่งกลางด้านบน:
   - `FilledButton.tonalIcon` ไอคอน `Icons.arrow_upward` ข้อความ `มีรายการใหม่ $_fresh รายการ` key `feed_new_items`
   - กดแล้วเลื่อนขึ้นบนสุดด้วย `_scroll.animateTo(0, ...)` และตั้ง `_fresh = 0`
6. `_scroll.addListener`: ถ้าผู้ใช้เลื่อนขึ้นถึงบนสุดเองให้ตั้ง `_fresh = 0` ด้วย (เช็ก `_fresh > 0` ก่อน `setState`)
7. ถ้า `MediaQuery.disableAnimationsOf(context)` เป็น `true` ใช้ `_scroll.jumpTo(0)` แทน

- 🧑 คิดระยะห่างและตำแหน่งปุ่มเองได้ แต่สีและรูปทรงต้องมาจาก theme

**ตรวจ:** `flutter analyze` และ `flutter test test/class_posts/`

**commit:** `feat(app): show a new items button instead of moving the feed`

---

## C14: การ์ด "การเข้าเรียนวันนี้" 🧑🤖

**ไฟล์ใหม่:**
- `app/lib/features/attendance/today_attendance_card.dart`
- `app/test/attendance/attendance_fakes.dart`

**ไฟล์:** `app/lib/features/student/my_subjects_page.dart`, `app/test/attendance/attendance_test.dart`, `app/test/student/student_fixtures.dart`

**ทำ:**
1. `TodayAttendanceCard extends ConsumerWidget` รับ `final DateTime? now;` ไว้ให้ test กำหนดวันได้
2. อ่าน `ref.watch(myAttendanceProvider).value` แล้วเลือก session ที่ `DateUtils.isSameDay(entry.heldOn, now ?? DateTime.now())`
3. ไม่มีรายการของวันนี้ โหลดไม่สำเร็จ หรือยังโหลดอยู่ → `SizedBox.shrink()`
4. มีรายการ → `Card` key `today_attendance` แบบเดียวกับ `LatestPostsCard`:
   - หัวข้อ "การเข้าเรียนวันนี้" และ `LiveBadge`
   - แต่ละแถวใช้ `ListTile` key `today_attendance_{courseId}_{periodNo}` แสดงชื่อวิชา, "คาบ N" ถ้ามีเลขคาบ และ `status.label` สี `status.color(scheme)`
   - แตะแล้วเปิด `AppRoutes.myAttendance`
5. วางใต้ `LatestPostsCard` ทั้งสองจุดใน `my_subjects_page.dart`
6. ย้าย `FakeAttendance` จาก `attendance_test.dart` ไป `attendance_fakes.dart` และให้กำหนดค่าที่ `mine()` คืนได้
7. ใน `studentViewOverrides` เพิ่ม `attendanceRepositoryProvider.overrideWithValue(attendance ?? FakeAttendance())` เพราะหน้าแรกจะเรียก `mine()` ทุกครั้ง ไม่อย่างนั้น test เดิมจะไปเรียกเครือข่ายจริง

**ตรวจ:** `flutter analyze` และ `flutter test test/student/ test/attendance/` ถ้า test ใดของหน้าแรกล้มเพราะไม่มี fake ให้เพิ่ม override ใน test นั้น

**commit:** `feat(app): today's attendance card on the student home`

---

## C15: widget test และ 3 ขนาดจอ 🤖

**ไฟล์ใหม่:** `app/test/live/live_class_widgets_test.dart`

**กรณีที่ต้องมี:**
1. `LiveBadge`: live เปิดแล้วเห็น `live_badge`, live ปิดแล้วไม่เห็นและไม่กินที่
2. ปุ่มรายการใหม่:
   - เลื่อนลงแล้วฟีดมีรายการเพิ่ม → เห็น `feed_new_items` พร้อมจำนวนถูก
   - กดแล้วกลับบนสุดและปุ่มหาย
   - อยู่บนสุดอยู่แล้วเมื่อรายการเพิ่ม → ไม่มีปุ่ม
3. `TodayAttendanceCard` (ส่ง `now` เข้าไป):
   - มีคาบวันนี้ → เห็นชื่อวิชา คาบ และ "มาสาย"
   - มีแต่คาบเมื่อวาน → ไม่เห็นการ์ด
   - repository โยน error → ไม่เห็นการ์ดและไม่พัง
4. ที่ 390×844, 820×1180 และ 1440×900 ฟีดกับการ์ดไม่มี overflow

**ตรวจ:** `flutter test test/live/` และเปิด Chrome ปรับเป็น 390, 820, 1440 px เอง

**commit:** `test(app): live badge, new items button and today's attendance`

---

## C16: integration test 🤖

**ไฟล์ใหม่:** `app/test/flows/live_class_flow_test.dart`

**ทำ:** ดูแบบ `app/test/flows/teacher_flow_test.dart` และ `_liveRefreshTests` แล้วทดสอบ flow เดียวต่อเนื่องบน `StudentShell` ด้วย `FakeFirebaseFirestore`, `FakeClassPosts` และ `FakeAttendance`:

1. เปิดหน้าแรก ยังไม่มีโพสต์และไม่มีการ์ดเช็คชื่อ
2. เพิ่มโพสต์ใน fake แล้วเขียน `users/u11/class_feed/7` → เห็นโพสต์ใน "โพสต์ล่าสุด"
3. เอาโพสต์ออกจาก fake แล้วเขียนเอกสารเดิมซ้ำ → โพสต์หาย
4. เพิ่มคาบวันนี้สถานะ `late` แล้วเขียน `users/u11/attendance/3_7` → เห็น "มาสาย"
5. เปลี่ยนสถานะเป็น `present` แล้วเขียนซ้ำ → เห็น "มาตรงเวลา"

**ตรวจ:** `flutter test test/flows/live_class_flow_test.dart`

**commit:** `test(app): student home follows posts and attendance from live signals`

---

## C17: security test (แอป) 🤖

**ไฟล์ใหม่:** `app/test/security/live_class_signal_test.dart`

**กรณีที่ต้องมี:**
1. เอกสารของ uid อื่น (`users/u99/class_feed/7`) ไม่ทำให้แอปดึงฟีดใหม่
2. Firebase ล็อกอินเป็นบัญชีอื่น (`FakeLiveSession` uid ไม่ตรงกับ `currentUserProvider`) แอปต้องไม่ฟัง และไม่มีป้าย "อัปเดตสด"
3. หลังผ่าน flow แล้ว จำนวนเอกสารใน `class_feed` และ `attendance` เท่าที่ test เขียนไว้ แสดงว่าแอปไม่ได้เขียน Firestore เอง
4. อ่านไฟล์ `../firebase/firestore.rules` แบบเดียวกับ `android_config_test.dart` แล้วยืนยันว่ามี `request.auth.uid == uid` และไม่มี `match /classrooms`

CI ไม่ได้รัน Firebase emulator จึงตรวจกฎจริงไม่ได้ ข้อ 4 ตรวจเฉพาะว่าข้อความในไฟล์ไม่เปลี่ยน ให้บอกข้อจำกัดนี้ในสไลด์ตามจริง

**ตรวจ:** `flutter analyze` และ `flutter test` ทั้งชุด

**commit:** `test(app): live signals are read only for the signed-in user`

---

## C18: เอกสาร 🧑

**ไฟล์:** `docs/DESIGN.md`, `firebase/README.md` (เขียนเป็นภาษาไทย)

**ทำ:**
1. เพิ่มหัวข้อ `### 38.11 ขั้น 4: ฟีดห้องเรียนและเช็คชื่อสด` ต่อจาก §38.10 โดยมี:
   - เอกสารทั้งสองชนิด และเหตุผลที่ไม่มีเนื้อหา
   - จุดส่งสัญญาณ: `ClassPostPublished`, แก้และลบโพสต์, `Assignment::booted`, `AttendanceController`
   - แอปฟังที่ไหน และ invalidate provider ใดบ้าง
   - ความหน่วงตามรอบของ worker
   - test ที่มี
2. ใน §38.2 แทนแถว `classrooms/{id}/feed/{postId}` และ `classrooms/{id}/attendance/{date}_{period}` ด้วยเอกสารใหม่สองแถว และแก้ประโยคก่อนตารางที่บอกว่าขั้น 4 ต้องมี `classrooms/{id}` กับ `members` ห้ามลบแถวของขั้น 5 (`classrooms/{id}/students`, `submissions`, `gradebook`)
3. ใน §38.3 แก้สถานะขั้น 4 เป็น `เสร็จในโค้ด <วันที่> (§38.11)` และแก้คำอธิบายของแถวไม่ให้พูดถึง `members` กับ rule ระดับห้องแล้ว
4. ใน `firebase/README.md` เพิ่ม `class_feed` และ `attendance` ในรายการ collection group ของ TTL

**commit:**
```
docs(repo): add step 4 of the Firestore live layer to DESIGN

Signals stay under users/{uid} instead of classrooms/{id}: a post may
reach only some students, rules cannot hide fields of one document, and
a members copy would have to follow account merges and deletions.

Closes #303
```

---

## เสร็จเมื่อ

- [ ] ครูโพสต์ แก้ หรือลบ แล้วหน้าจอนักเรียนเปลี่ยนเองภายในรอบของ worker
- [ ] ครูเช็คชื่อแล้วการ์ด "การเข้าเรียนวันนี้" เปลี่ยนเอง
- [ ] ครูอีกคนของห้องเห็นโพสต์ใหม่เอง
- [ ] Firestore ปิดแล้วทุกหน้าทำงานเหมือนเดิม และไม่มีป้าย "อัปเดตสด"
- [ ] เอกสาร `class_feed` และ `attendance` มีแค่ id กับเวลา (security test ผ่านทั้งสองฝั่ง)
- [ ] ไม่ได้แก้ `firebase/firestore.rules` และไม่มีเอกสารใต้ `classrooms/`
- [ ] คำสั่งตรวจส่วน admin ใน `00-START-HERE.md` ไม่มีผลลัพธ์
- [ ] ทุกหน้าดูดีที่ 390, 820 และ 1440 px
- [ ] `php artisan test`, `vendor/bin/pint`, `flutter analyze` และ `flutter test` ผ่าน และ CI ใน PR เขียว
- [ ] มีอย่างน้อย 10 commit ทุก commit มาจากบัญชีของตัวเอง และ merge แบบไม่ squash
- [ ] มีสไลด์ส่วนนี้ และซ้อมเดโมบนเครื่องที่จะใช้จริงแล้ว
