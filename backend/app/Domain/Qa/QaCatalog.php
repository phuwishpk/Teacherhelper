<?php

namespace App\Domain\Qa;

/**
 * What the Krucheck Test Lab tests (DESIGN §39). Kept in code so the plan is
 * reviewed with the system it tests; results live in qa_results by `code`.
 *
 *   auto      integration tests GitHub Actions runs on every push and PR;
 *             `refs` name the test files (backend/tests/Feature/…, app/…,
 *             .github/…), `workflow` the job whose result counts
 *   manual    integration tests a person runs against the real thing,
 *             because they need a paid or OAuth-only service, real devices
 *             or the production host
 *   usability task-based usability tests, observed and timed
 *
 * QaCatalogTest checks that every ref exists in the repository.
 */
final class QaCatalog
{
    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    public const USABILITY = 'usability';

    /** Statuses a tester records, per kind: value => [label, tone]. */
    public const MANUAL_STATUSES = [
        'pass' => ['ผ่าน', 'success'],
        'fail' => ['ไม่ผ่าน', 'danger'],
        'blocked' => ['ทำไม่ได้ (ติดเงื่อนไข)', 'warning'],
        'skip' => ['ไม่ได้รับมอบหมาย', 'gray'],
    ];

    public const USABILITY_STATUSES = [
        'success' => ['สำเร็จเอง', 'success'],
        'assisted' => ['สำเร็จเมื่อมีคนช่วย', 'warning'],
        'fail' => ['ไม่สำเร็จ', 'danger'],
    ];

    /** The team's Thai wording of Brooke's SUS (1996), as in KICKOFF part 5. */
    public const SUS = [
        'ฉันคิดว่าฉันอยากใช้แอปนี้บ่อย ๆ',
        'ฉันรู้สึกว่าแอปนี้ซับซ้อนเกินความจำเป็น',
        'ฉันคิดว่าแอปนี้ใช้งานง่าย',
        'ฉันคิดว่าฉันต้องมีคนคอยช่วยจึงจะใช้แอปนี้ได้',
        'ฉันรู้สึกว่าฟังก์ชันต่าง ๆ ในแอปนี้ทำงานเข้ากันได้ดี',
        'ฉันรู้สึกว่าแอปนี้มีความไม่สอดคล้องกันมากเกินไป',
        'ฉันคิดว่าคนส่วนใหญ่จะเรียนรู้การใช้แอปนี้ได้อย่างรวดเร็ว',
        'ฉันรู้สึกว่าแอปนี้ใช้งานยุ่งยาก',
        'ฉันรู้สึกมั่นใจเมื่อใช้แอปนี้',
        'ฉันต้องเรียนรู้หลายอย่างก่อนจึงจะเริ่มใช้แอปนี้ได้',
    ];

    public const SUS_BENCHMARK = 68;

    /**
     * @return list<array{code: string, kind: string, area: string, title: string, detail: string, workflow?: string, refs?: list<string>, why?: string, steps?: list<string>, expect?: string, role?: string, done?: string, measure?: string}>
     */
    public static function cases(): array
    {
        return [...self::auto(), ...self::manual(), ...self::usability()];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $code): ?array
    {
        foreach (self::cases() as $case) {
            if ($case['code'] === $code) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function ofKind(string $kind): array
    {
        return array_values(array_filter(self::cases(), fn (array $c) => $c['kind'] === $kind));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function statusesFor(string $kind): array
    {
        return $kind === self::USABILITY ? self::USABILITY_STATUSES : self::MANUAL_STATUSES;
    }

    /** SUS 0–100: odd items (answer − 1), even items (5 − answer), sum × 2.5; null unless 10 answers of 1–5. */
    public static function susScore(array $answers): ?float
    {
        if (count($answers) !== 10) {
            return null;
        }
        $sum = 0;
        foreach (array_values($answers) as $i => $a) {
            if (! is_int($a) || $a < 1 || $a > 5) {
                return null;
            }
            $sum += $i % 2 === 0 ? $a - 1 : 5 - $a;
        }

        return $sum * 2.5;
    }

    /** @return list<array<string, mixed>> */
    private static function auto(): array
    {
        $c = fn (string $code, string $area, string $title, string $detail, string $workflow, array $refs) => [
            'code' => $code, 'kind' => self::AUTO, 'area' => $area, 'title' => $title, 'detail' => $detail, 'workflow' => $workflow, 'refs' => $refs,
        ];

        return [
            $c('IT-A01', 'บัญชีและสิทธิ์', 'สมัครครู รออนุมัติ แล้วเข้าสู่ระบบ / นักเรียนเข้าสู่ระบบและเปลี่ยนรหัสผ่าน',
                'ยิง HTTP จริงเข้า /api/v1/auth/* ตรวจสถานะ pending จนกว่า admin อนุมัติใน Filament ตรวจ token ของ Sanctum และ /me รวมถึงการบังคับเปลี่ยนรหัสผ่านครั้งแรกของนักเรียน',
                'backend', ['backend/tests/Feature/Api/TeacherAuthTest.php', 'backend/tests/Feature/Api/StudentAuthTest.php', 'backend/tests/Feature/Api/StudentPasswordTest.php', 'backend/tests/Feature/AdminPanelTest.php']),
            $c('IT-A02', 'ห้องเรียน', 'เข้าห้องด้วยรหัสห้องหรือ QR, ครูร่วมสอน, ปิดห้องแล้วเขียนไม่ได้',
                'สร้างห้อง นักเรียนเข้าด้วยรหัสและเลือกที่นั่ง ครูอีกคนรับคำเชิญร่วมสอน แล้วปิดห้องและตรวจว่าทุก route ที่เขียนข้อมูลตอบ 409',
                'backend', ['backend/tests/Feature/Classrooms/ClassJoinTest.php', 'backend/tests/Feature/Classrooms/CoTeachingTest.php', 'backend/tests/Feature/Students/ClosedClassroomTest.php']),
            $c('IT-A03', 'การบ้าน', 'สร้างการบ้านและคำถาม → layout → PDF ใบงานผ่าน queue',
                'สร้างการบ้าน คำถามทุกประเภท rubric แล้วสั่งพิมพ์ ใบงานถูก render เป็นชุดใน queue และรวมเป็น PDF เดียว ตรวจ QR ที่เซ็นแล้วบนใบงาน',
                'backend', ['backend/tests/Feature/Api/AssignmentsTest.php', 'backend/tests/Feature/Api/LayoutTest.php', 'backend/tests/Feature/Api/WorksheetPrintsTest.php', 'backend/tests/Feature/Worksheets/WorksheetPdfRendererTest.php']),
            $c('IT-A04', 'ตรวจด้วย AI', 'อัปโหลดสแกน → คิว → Gemini (จำลอง) → คะแนนและคำอธิบาย',
                'ส่งหน้าที่สแกนพร้อม crop เข้า /scans ตรวจ QR ลำดับหน้า และสแกนซ้ำ job ตรวจเรียก Gemini ที่จำลองไว้ แล้วคำนวณคะแนนด้วย fuzzy rule ของระบบเอง',
                'backend', ['backend/tests/Feature/Api/ScansTest.php', 'backend/tests/Feature/Grading/GradeScanJobTest.php', 'backend/tests/Feature/Grading/AutoRulesTest.php']),
            $c('IT-A05', 'ตรวจด้วย AI', 'นักเรียนส่งรูปทั้งหน้าในแอป → ตรวจทั้งหน้า',
                'อัปโหลดรูปหรือ PDF ของงานทั้งหน้า Gemini (จำลอง) ระบุข้อและคำตอบ ระบบรวมเป็นคะแนนรายข้อ และกรณีหน้าซ้ำหรือไฟล์ที่ใช้ไม่ได้',
                'backend', ['backend/tests/Feature/Api/WholePageUploadsTest.php', 'backend/tests/Feature/Grading/WholePageGradingTest.php']),
            $c('IT-A06', 'ตรวจทานและผล', 'ครูตรวจทาน → เผยแพร่ → นักเรียนเห็นผล → ขอตรวจใหม่',
                'เส้นทางเต็มของคิวตรวจทาน แก้คะแนนพร้อมเหตุผล อนุมัติและเผยแพร่ นักเรียนเห็นเฉพาะผลที่เผยแพร่ ขอตรวจใหม่ และครูตอบคำขอ',
                'backend', ['backend/tests/Feature/Review/ReviewFlowTest.php', 'backend/tests/Feature/Review/ApproveAndPublishTest.php', 'backend/tests/Feature/Review/StudentResultsTest.php', 'backend/tests/Feature/Review/AppealsTest.php']),
            $c('IT-A07', 'Google Classroom', 'นำเข้าห้อง ซิงก์งานที่ส่ง ส่งคะแนนกลับ โพสต์ (HTTP จำลอง)',
                'จำลอง API ของ Classroom และ Drive ทั้งหมด ตรวจการจับคู่รายชื่อ รอบซิงก์ การส่งคะแนน คะแนนที่ขัดกัน และการโพสต์ประกาศกับเอกสาร',
                'backend', ['backend/tests/Feature/Google/ClassroomSyncTest.php', 'backend/tests/Feature/Google/AssignmentGoogleTest.php', 'backend/tests/Feature/Google/ClassPostGoogleTest.php', 'backend/tests/Feature/Google/GradeConflictTest.php']),
            $c('IT-A08', 'ข้อสอบกระดาษ', 'ชุดข้อสอบ → กระดาษคำตอบ → อ่านกระดาษ → วิเคราะห์',
                'สร้างข้อสอบหลายชุดที่สลับข้อ พิมพ์กระดาษคำตอบ อ่านรหัสชุดและคำตอบจากภาพ แล้ววิเคราะห์ตัวเลือก',
                'backend', ['backend/tests/Feature/Exams/ExamApiTest.php', 'backend/tests/Feature/Exams/ExamPrintsTest.php', 'backend/tests/Feature/Exams/ExamSheetScanTest.php', 'backend/tests/Feature/Exams/ExamAnalysisTest.php']),
            $c('IT-A09', 'สมุดคะแนน', 'รวมคะแนนตามหมวด ตัดเกรด ประกาศ ส่งออก CSV',
                'คะแนนจากการบ้านและข้อสอบเข้าสมุดคะแนน ถ่วงน้ำหนักตามหมวด ตัดเกรดไทย ประกาศเกรดให้นักเรียน และส่งออก CSV',
                'backend', ['backend/tests/Feature/Gradebook/GradebookGridTest.php', 'backend/tests/Feature/Gradebook/GradebookPublishTest.php', 'backend/tests/Feature/Gradebook/GradebookCsvTest.php']),
            $c('IT-A10', 'แจ้งเตือนและข้อมูลสด', 'push ผ่าน FCM และกล่องแจ้งเตือนกับสถานะการตรวจใน Firestore (HTTP จำลอง)',
                'จำลอง FCM HTTP v1 และ Firestore REST ตรวจข้อความที่ส่ง เอกสารที่เขียนใต้ users/{uid} และ custom token ของ Firebase',
                'backend', ['backend/tests/Feature/Notifications/FcmNotifierTest.php', 'backend/tests/Feature/Firestore/FirestoreInboxTest.php', 'backend/tests/Feature/Firestore/LiveGradingTest.php', 'backend/tests/Feature/Firestore/FirebaseTokenTest.php']),
            $c('IT-A11', 'ผู้ช่วย Krucheck', 'แชท สร้างข้อ rubric ตัวชี้วัด ข้อมูลนักเรียนแบบรหัส และไฟล์ (Gemini จำลอง)',
                'ตรวจการ์ดที่ผู้ช่วยเสนอและการกดยืนยัน การแทนชื่อนักเรียนด้วยรหัสก่อนส่งให้ Gemini และการอ่านไฟล์แนบ',
                'backend', ['backend/tests/Feature/Assistant/AssistantChatTest.php', 'backend/tests/Feature/Assistant/AssistantCardsTest.php', 'backend/tests/Feature/Assistant/AssistantStudentDataTest.php', 'backend/tests/Feature/Assistant/AssistantFilesTest.php']),
            $c('IT-A12', 'ความปลอดภัย', 'สิทธิ์ทุก route, prompt injection, header, ความลับใน log',
                'เรียกทุก route ของ /api/v1 ด้วยทุกบทบาทแล้วเทียบกับตารางสิทธิ์ ส่งภาพที่ฝังคำสั่งให้ AI ตรวจ header ความปลอดภัย และค้นหาคีย์ใน log',
                'backend', ['backend/tests/Feature/Security/AuthorizationMatrixTest.php', 'backend/tests/Feature/Security/PromptInjectionTest.php', 'backend/tests/Feature/Security/SecurityHeadersTest.php', 'backend/tests/Feature/Security/SecretsHygieneTest.php']),
            $c('IT-A13', 'ข้อมูลส่วนบุคคล', 'ลบบัญชี กู้คืนใน 30 วัน และลบถาวรเมื่อครบ',
                'ลบบัญชีครูที่มีข้อมูลครบทุกชนิด ตรวจว่าทั้งแถวและไฟล์หายครบ คะแนนของนักเรียนยังอยู่ และไล่ foreign key ทุกตัวในฐานข้อมูล',
                'backend', ['backend/tests/Feature/Accounts/AccountDeletionTest.php', 'backend/tests/Feature/Accounts/AccountPurgeTest.php', 'backend/tests/Feature/Accounts/AccountPurgeCoverageTest.php']),
            $c('IT-A14', 'แอป', 'flow ครู admin และสมัครด้วย Google ในแอปจริงกับ API จำลอง',
                'รันแอป Flutter ทั้งตัวกับ API ปลอมในเครื่อง กดผ่านหน้าจอจริงตั้งแต่เข้าสู่ระบบจนถึงคิวตรวจทาน',
                'app', ['app/test/flows/teacher_flow_test.dart', 'app/test/flows/admin_flow_test.dart', 'app/test/flows/google_signup_flow_test.dart']),
            $c('IT-A15', 'แอป', 'flutter analyze, test ทั้งชุด coverage ≥ 70% และ build APK',
                'งาน app ของ GitHub Actions: วิเคราะห์โค้ด รัน test ทั้งหมด บังคับ coverage อย่างน้อย 70% แล้ว build APK debug',
                'app', ['.github/workflows/app.yml']),
            $c('IT-A16', 'Deploy', 'composer install บน PHP 8.3 และ build เว็บ → branch deploy',
                'ติดตั้ง vendor ด้วย PHP ของ server สร้างเว็บแอป แล้วเผยแพร่ branch deploy ที่ Plesk ดึงไปใช้',
                'deploy', ['.github/workflows/deploy.yml']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function manual(): array
    {
        $c = fn (string $code, string $area, string $title, string $why, array $steps, string $expect) => [
            'code' => $code, 'kind' => self::MANUAL, 'area' => $area, 'title' => $title, 'detail' => $why, 'why' => $why, 'steps' => $steps, 'expect' => $expect,
        ];

        return [
            $c('IT-M01', 'AI จริง', 'Gemini จริงอ่านลายมือภาษาไทย', 'ใช้คีย์จริงที่มีค่าใช้จ่าย และผลของโมเดลไม่คงที่',
                ['ใส่คีย์ Gemini จริงในหน้าตั้งค่าของครูตัวอย่าง แล้วกด "ทดสอบอีกครั้ง"', 'สแกนใบงานที่เขียนคำตอบไว้ 3 แผ่น', 'เปิดคิวตรวจทาน เทียบคะแนนกับเฉลยที่เตรียมไว้'],
                'ทั้ง 3 แผ่นตรวจเสร็จ ไม่มีรหัส AI-… และคะแนนที่ AI ให้ตรงกับเฉลยอย่างน้อย 80% ของข้อ'),
            $c('IT-M02', 'อุปกรณ์', 'สแกนด้วยกล้องมือถือจริงในแสงต่างกัน', 'ต้องใช้กล้องและกระดาษจริง',
                ['สแกนใบงานเดียวกันในห้องสว่าง ห้องแสงน้อย และวางเอียง', 'ดูว่าแอปจับมุมทั้ง 4 ได้และบอกเมื่อภาพเบลอ'],
                'ภาพที่ใช้ได้ส่งขึ้นได้ ภาพเบลอถูกเตือนก่อนส่ง'),
            $c('IT-M03', 'อุปกรณ์', 'สแกนตอนออฟไลน์ แล้วอัปโหลดเองเมื่อมีเน็ต', 'ต้องตัดเครือข่ายจริงบนเครื่อง Android',
                ['เปิดโหมดเครื่องบิน สแกน 2 แผ่น', 'ดูคิวอัปโหลด', 'ปิดโหมดเครื่องบิน รอไม่เกิน 2 นาที'],
                'คิวแสดง 2 แผ่นรอส่ง แล้วส่งขึ้นเองครบหลังมีเน็ต'),
            $c('IT-M04', 'แจ้งเตือน', 'การแจ้งเตือน push จริงบน Android', 'ต้องใช้ FCM จริงและเครื่องจริง',
                ['ติดตั้ง APK ที่ build พร้อม google-services.json แล้วล็อกอินเป็นนักเรียน', 'ครูเผยแพร่ผลการบ้านของนักเรียนคนนั้น'],
                'เครื่องนักเรียนได้การแจ้งเตือน แตะแล้วเปิดหน้าผลของงานนั้น'),
            $c('IT-M05', 'แจ้งเตือน', 'กล่องแจ้งเตือนและการ์ด "ข้อมูลสด" บนเว็บจริง', 'ต้องใช้เบราว์เซอร์จริงกับ Firebase จริงภายใต้ CSP ของ production',
                ['เปิดเว็บแอปใน Chrome และ Safari กด Cmd+Shift+R แล้วล็อกอิน', 'ไปที่ ตั้งค่า > การ์ด "ข้อมูลสด (Firestore)"', 'อีกเครื่องให้ครูโพสต์ประกาศในห้อง'],
                'การ์ดขึ้น "เชื่อมต่อแล้ว" กระดิ่งขึ้นเลขโดยไม่ต้องรีเฟรช และ Console ไม่มี error เรื่อง Content Security Policy'),
            $c('IT-M06', 'แจ้งเตือน', 'ความคืบหน้าการตรวจแบบสดสองเครื่อง', 'ต้องใช้ queue worker จริงบน hosting และสองหน้าจอพร้อมกัน',
                ['ครูเปิดหน้ารายละเอียดการบ้านค้างไว้', 'อีกเครื่องสแกนหรือส่งงาน 3 ชิ้น'],
                'การ์ด "ความคืบหน้าการตรวจ" ขยับเองภายใน 1 นาที และหน้าผลของนักเรียนเปลี่ยนเองเมื่อเผยแพร่'),
            $c('IT-M07', 'Google จริง', 'Google Classroom จริงครบวงจร', 'ต้องผ่าน OAuth จริงและคอร์สทดลองจริง',
                ['เชื่อมบัญชี Google ในหน้าตั้งค่า แล้วนำเข้าคอร์สทดลอง', 'โพสต์การบ้านไป Classroom', 'นักเรียนทดลองส่งรูปใน Classroom แล้วครูกด "ดึงงานที่ส่ง"', 'ตรวจแล้วส่งคะแนนกลับ'],
                'งานขึ้นใน Classroom รูปถูกดึงมาตรวจ และคะแนนกลับไปที่ Classroom'),
            $c('IT-M08', 'Google จริง', 'เข้าสู่ระบบด้วย Google ทั้งครูและนักเรียน', 'ต้องใช้หน้าเลือกบัญชีของ Google จริง',
                ['ครูกด "เข้าสู่ระบบด้วย Google" ด้วยอีเมลที่สมัครไว้', 'นักเรียนเปิดลิงก์เข้าห้อง แล้วเข้าด้วย Google'],
                'ครูเข้าได้ทันที นักเรียนได้บัญชีและอยู่ในห้องที่ถูกต้อง'),
            $c('IT-M09', 'อุปกรณ์', 'ข้อสอบกระดาษพิมพ์จริงแล้วสแกนกลับ', 'ต้องใช้เครื่องพิมพ์จริง ขนาดกระดาษและหมึกจริง',
                ['พิมพ์ข้อสอบ 2 ชุดและกระดาษคำตอบ', 'ฝนคำตอบ 3 แผ่น แล้วสแกน'],
                'อ่านชุดข้อสอบและคำตอบได้ถูกทั้ง 3 แผ่น'),
            $c('IT-M10', 'Production', 'push ขึ้น main แล้ว deploy ถึง Hostatom เอง', 'ขึ้นกับ webhook ของ Plesk และ Scheduled Task จริง ไม่มี SSH',
                ['merge PR เล็ก ๆ หนึ่งอัน', 'รอ workflow deploy เสร็จ แล้วดูการ์ด "รุ่นที่ใช้งานอยู่" ในหน้าภาพรวมของ Test Lab'],
                'ภายใน 10 นาที commit ที่ใช้งานอยู่ตรงกับ main และ /api/v1/health ตอบปกติ'),
            $c('IT-M11', 'Production', 'งานตามเวลาบน Plesk', 'ต้องรอเวลาจริงของ cron บน hosting',
                ['ดู /api/v1/health ว่า queue_last_run_at ไม่เกิน 3 นาที', 'เช้าวันถัดไปเปิด See details ของ task eduvision-purge-images'],
                'queue ทำงานทุกนาที และ purge รันตอน 02:00 มีบรรทัด Deleted accounts purged'),
            $c('IT-M12', 'เบราว์เซอร์', 'เว็บแอปบนมือถือจอเล็ก', 'ต้องดูหน้าจอจริงบน Safari iOS และ Chrome Android',
                ['เปิด /app/ บนมือถือ ล็อกอินครูและนักเรียน', 'เปิดหน้าการบ้าน คิวตรวจทาน และผลการบ้าน'],
                'ไม่มีหน้าที่ต้องเลื่อนซ้ายขวา ปุ่มกดได้ทุกปุ่ม ข้อความภาษาไทยไม่ถูกตัด'),
            $c('IT-M13', 'แอป', 'integration_test บน Android emulator', 'เป็น test อัตโนมัติแต่ต้องใช้ emulator ซึ่ง CI ยังไม่มี',
                ['เปิด AVD', 'รัน flutter test integration_test/teacher_flow_test.dart -d <emulator>'],
                'ผ่านทั้ง flow ครู: เข้าสู่ระบบ → ห้อง → การบ้าน → คำถาม → layout → คิวตรวจทาน → ออก'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function usability(): array
    {
        $c = fn (string $code, string $role, string $title, string $done, string $measure) => [
            'code' => $code, 'kind' => self::USABILITY, 'area' => $role, 'role' => $role, 'title' => $title, 'detail' => "เกณฑ์สำเร็จ: {$done}", 'done' => $done, 'measure' => $measure,
        ];

        return [
            $c('UT-1', 'ครู', 'สมัครบัญชีครู แล้วเข้าสู่ระบบหลัง admin อนุมัติ', 'เห็นหน้าหลักของครู', 'เข้าใจข้อความ "รออนุมัติ" หรือไม่'),
            $c('UT-2', 'ครู', 'สร้างห้องเรียน แล้วให้นักเรียน 3 คนเข้าห้องด้วยรหัสห้องหรือ QR', 'รายชื่อในห้องมี 3 คน', 'หาปุ่ม "แสดง QR" เจอหรือไม่'),
            $c('UT-3', 'ครู', 'สร้างการบ้าน 4 ข้อ 4 ประเภท และอนุมัติ rubric ที่ AI ร่าง', 'การบ้านพร้อมพิมพ์', 'จุดที่สับสน'),
            $c('UT-4', 'ครู', 'พิมพ์ใบงาน แล้วสแกนกระดาษที่เตรียมไว้ 3 แผ่น', 'สแกนขึ้นครบ 3 แผ่น', 'สแกนซ้ำกี่ครั้ง'),
            $c('UT-5', 'ครู', 'ตรวจทานคิว แก้คะแนน 1 ข้อพร้อมเหตุผล แล้วเผยแพร่', 'ผลถูกเผยแพร่', 'เข้าใจเหตุผลของคะแนนที่ AI ให้หรือไม่'),
            $c('UT-6', 'ครู', 'โพสต์ประกาศในห้องพร้อมไฟล์แนบ', 'นักเรียนเห็นโพสต์', 'แนบไฟล์ได้ในครั้งแรกหรือไม่'),
            $c('UT-7', 'ครู', 'ให้ผู้ช่วย Krucheck สร้างข้อ 2 ข้อ แล้วเพิ่มเข้าการบ้าน', 'ข้อใหม่อยู่ในการบ้าน', 'เชื่อใจคำตอบของผู้ช่วยหรือไม่'),
            $c('UT-8', 'ครู', 'ตั้งเกณฑ์เกรดของรายวิชา แล้วดูสมุดคะแนน', 'เห็นเกรดของนักเรียน', 'หาเมนูเจอเองหรือไม่'),
            $c('US-1', 'นักเรียน', 'เปิดลิงก์เข้าห้อง แล้วเข้าสู่ระบบด้วยชื่อผู้ใช้', 'เห็นวิชาของฉัน', 'เวลา'),
            $c('US-2', 'นักเรียน', 'ส่งการบ้านด้วยรูปถ่าย', 'สถานะงานเป็นส่งแล้ว', 'ถ่ายใหม่กี่ครั้ง'),
            $c('US-3', 'นักเรียน', 'อ่านคำอธิบายข้อที่ผิด แล้วขอให้ครูตรวจใหม่ 1 ข้อ', 'คำขอถึงครู', 'เข้าใจคำอธิบายหรือไม่'),
            $c('US-4', 'นักเรียน', 'เปิดกล่องแจ้งเตือน แล้วไปที่โพสต์ใหม่', 'เปิดโพสต์ได้', 'สังเกตกระดิ่งเองหรือไม่'),
            $c('US-5', 'นักเรียน', 'ทำแบบฝึกซ่อม 3 ข้อ', 'เห็นผลทันที', 'ความรู้สึกหลังทำ'),
        ];
    }
}
