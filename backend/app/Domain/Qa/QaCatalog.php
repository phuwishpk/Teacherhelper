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
 *   process   end-to-end usability scenarios that cross many functions
 *             (DESIGN §39.6), observed and timed
 *   function  one usability task per use case of the use case document,
 *             with its steps, success criterion and alternative paths,
 *             loaded from resources/qa/usability.json
 *             (tools/qa-usability.py builds it from the manuals)
 *
 * QaLabTest checks that every ref exists in the repository.
 */
final class QaCatalog
{
    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    public const PROCESS = 'process';

    public const FUNCTION = 'function';

    /** Both usability levels. */
    public const USABILITY_KINDS = [self::PROCESS, self::FUNCTION];

    public const KIND_LABELS = [
        self::AUTO => 'Integration อัตโนมัติ',
        self::MANUAL => 'Integration ทำมือ',
        self::PROCESS => 'Usability: กระบวนการ',
        self::FUNCTION => 'Usability: ฟังก์ชัน',
    ];

    public const USABILITY_JSON = 'resources/qa/usability.json';

    /** @var list<array<string, mixed>>|null */
    private static ?array $functions = null;

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
        'skip' => ['ข้าม (ไม่ได้รับมอบหมาย)', 'gray'],
    ];

    /** One alternative path of a function task: label => [label text, tone]. */
    public const CHECK_STATUSES = [
        'pass' => ['เป็นตามที่เขียน', 'success'],
        'fail' => ['ไม่เป็นตามที่เขียน', 'danger'],
    ];

    /**
     * @return list<array{code: string, kind: string, area: string, title: string, detail: string, workflow?: string, refs?: list<string>, why?: string, steps?: list<string>, expect?: string, role?: string, done?: string, measure?: string}>
     */
    public static function cases(): array
    {
        return [...self::auto(), ...self::manual(), ...self::processes(), ...self::functions()];
    }

    public static function isUsability(string $kind): bool
    {
        return in_array($kind, self::USABILITY_KINDS, true);
    }

    /** @return list<array<string, mixed>> */
    public static function ofKinds(array $kinds): array
    {
        return array_values(array_filter(self::cases(), fn (array $c) => in_array($c['kind'], $kinds, true)));
    }

    /**
     * The usability groups in order: the processes first, then the chapters
     * of the use case document.
     *
     * @return array<string, int> group => number of tasks
     */
    public static function usabilityGroups(): array
    {
        $groups = [];
        foreach (self::ofKinds(self::USABILITY_KINDS) as $case) {
            $groups[$case['group']] = ($groups[$case['group']] ?? 0) + 1;
        }

        return $groups;
    }

    /** @return list<array<string, mixed>> */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::ofKinds(self::USABILITY_KINDS), fn (array $c) => $c['group'] === $group));
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
        return self::isUsability($kind) ? self::USABILITY_STATUSES : self::MANUAL_STATUSES;
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

    public const PROCESS_GROUP = 'กระบวนการตั้งแต่ต้นจนจบ';

    /**
     * End-to-end scenarios (DESIGN §39.6): each crosses several use cases in
     * the order a real teacher, student or admin meets them.
     *
     * @return list<array<string, mixed>>
     */
    private static function processes(): array
    {
        $c = fn (string $code, string $actor, string $title, string $goal, array $steps, string $done, array $ucs) => [
            'code' => $code, 'kind' => self::PROCESS, 'group' => self::PROCESS_GROUP, 'area' => $actor, 'actor' => $actor,
            'title' => $title, 'detail' => $goal, 'goal' => $goal, 'steps' => $steps, 'done' => $done, 'outcome' => $done, 'ucs' => $ucs,
        ];

        return [
            $c('PR-01', 'ครู นักเรียน ผู้ดูแลระบบ', 'ครูใหม่เริ่มใช้ Krucheck จนนักเรียนเข้าห้องได้',
                'ครูที่ไม่เคยใช้ระบบสมัคร ได้รับอนุมัติ ตั้งค่า แล้วพานักเรียน 3 คนเข้าห้อง',
                ['ครูสมัครบัญชีจากหน้าเข้าสู่ระบบ', 'ผู้ดูแลระบบอนุมัติบัญชีใน /admin', 'ครูเข้าสู่ระบบ ตั้งชื่อโรงเรียน และใส่ Gemini API key', 'ครูสร้างห้อง ป.5/1 แล้วกด "แสดง QR"', 'นักเรียน 3 คนสแกน QR หรือพิมพ์รหัสห้อง เข้าห้อง และตั้งรหัสผ่านของตัวเอง'],
                'รายชื่อในห้องมีนักเรียน 3 คน และนักเรียนทุกคนเห็นวิชาของฉัน', ['UC-T01', 'UC-A02', 'UC-T02', 'UC-T04', 'UC-T05', 'UC-T10', 'UC-T14', 'UC-S04', 'UC-S01', 'UC-S02']),
            $c('PR-02', 'ครู นักเรียน', 'การบ้านแบบใบงานของแอปครบวงจร',
                'จากการบ้านที่ยังไม่มีจนถึงนักเรียนเห็นผลและขอให้ครูตรวจใหม่',
                ['สร้างการบ้าน 4 ข้อ 4 ประเภท ผูกตัวชี้วัด และอนุมัติ rubric ที่ AI ร่าง', 'สร้าง layout แล้วพิมพ์ใบงาน', 'สแกนใบงานที่เขียนคำตอบแล้ว 3 แผ่น และดูคิวอัปโหลดจนส่งครบ', 'รอ AI ตรวจ ดูการแจ้งเตือน "ตรวจเสร็จ"', 'ตรวจทานในคิว แก้คะแนน 1 ข้อพร้อมเหตุผล แก้คำอธิบาย 1 ข้อ อนุมัติกลุ่มมั่นใจ', 'เผยแพร่ผล', 'นักเรียนเปิดกล่องแจ้งเตือน ดูผล อ่านคำอธิบาย แล้วขอตรวจใหม่ 1 ข้อ', 'ครูตอบคำขอตรวจใหม่'],
                'นักเรียนเห็นคะแนนที่ครูแก้ และเห็นคำตอบของคำขอตรวจใหม่', ['UC-T27', 'UC-T28', 'UC-T29', 'UC-T31', 'UC-T30', 'UC-T33', 'UC-T34', 'UC-X01', 'UC-X07', 'UC-T40', 'UC-T41', 'UC-T42', 'UC-T43', 'UC-S21', 'UC-S11', 'UC-S12', 'UC-T44']),
            $c('PR-03', 'ครู นักเรียน', 'การบ้านที่ไม่ใช้ใบงาน ส่งเป็นรูปในแอป',
                'ครูสั่งงานจากใบงานของตัวเอง นักเรียนถ่ายรูปส่ง AI ตรวจทั้งหน้า',
                ['ครูสร้างการบ้านแบบไม่ใช้ใบงาน อัปโหลดใบโจทย์และเฉลย แล้วอนุมัติเฉลย', 'นักเรียน 2 คนส่งงานเป็นรูปถ่ายในแอป อีก 1 คนครูอัปโหลดรูปแทน', 'ครูดูการ์ด "ความคืบหน้าการตรวจ" ระหว่าง AI ตรวจ', 'ครูแก้เฉลย 1 ข้อแล้วตรวจใหม่ทั้งห้อง', 'ตรวจทานแล้วเผยแพร่', 'นักเรียนดูผล'],
                'ทั้ง 3 คนเห็นผลที่ตรวจตามเฉลยใหม่', ['UC-T36', 'UC-T37', 'UC-S10', 'UC-T39', 'UC-X01', 'UC-T38', 'UC-T40', 'UC-T43', 'UC-S11']),
            $c('PR-04', 'ครู นักเรียน', 'ข้อสอบกระดาษตั้งแต่ออกข้อสอบจนวิเคราะห์ผล',
                'สอบกลางภาคด้วยข้อสอบสองชุดและกระดาษคำตอบของระบบ',
                ['สร้างข้อสอบ อ่านข้อจากไฟล์ด้วย AI แล้วแก้ให้ถูก', 'ผูกตัวชี้วัด กรอกเฉลย และอนุมัติเฉลย', 'จัด 2 ชุด แล้วพิมพ์เล่มข้อสอบ กระดาษคำตอบ และกระดาษเฉลย', 'ฝนกระดาษคำตอบ 3 แผ่นแล้วสแกน', 'ตรวจทานแผ่นที่ระบบไม่แน่ใจ แล้วประกาศผล', 'ดูการวิเคราะห์ตัวเลือก', 'นักเรียนดูผลสอบ'],
                'นักเรียนเห็นคะแนนสอบ และครูเห็นข้อที่ควรปรับ', ['UC-T47', 'UC-T48', 'UC-T49', 'UC-T51', 'UC-T52', 'UC-T53', 'UC-T54', 'UC-T55', 'UC-X02', 'UC-T56', 'UC-T57', 'UC-S11']),
            $c('PR-05', 'ครู นักเรียน', 'ปลายภาค: รวมคะแนนและตัดเกรด',
                'รวมคะแนนทั้งภาค ใส่เกรดพิเศษ ประกาศเกรด และส่งออก',
                ['สร้างรายวิชา เลือกตัวชี้วัด แล้วเริ่มสมุดคะแนน', 'ตั้งหมวดคะแนนและเกณฑ์เกรด เพิ่มรายการคะแนนจิตพิสัย', 'กรอกคะแนน และเปิดคะแนนการเข้าเรียน', 'ใส่ ร ให้นักเรียน 1 คน', 'ประกาศเกรด', 'นักเรียนดูเกรด', 'ส่งออก CSV'],
                'เกรดที่นักเรียนเห็นตรงกับไฟล์ CSV', ['UC-T22', 'UC-T23', 'UC-T59', 'UC-T60', 'UC-T61', 'UC-T87', 'UC-T62', 'UC-T63', 'UC-S15', 'UC-T64']),
            $c('PR-06', 'ครู นักเรียน', 'ทำงานคู่กับ Google Classroom',
                'ห้องที่ใช้ Classroom อยู่แล้วย้ายมาตรวจด้วย Krucheck โดยไม่ทิ้ง Classroom',
                ['เชื่อมบัญชี Google แล้วนำเข้าห้องจากคอร์สทดลอง', 'จับคู่นักเรียนและซิงก์รายชื่อ', 'โพสต์การบ้านและประกาศไป Classroom', 'นักเรียนส่งงานใน Classroom รอการซิงก์', 'จัดการงานที่ส่ง ตรวจ แล้วส่งคะแนนและประกาศผลรายคนกลับ Classroom'],
                'คะแนนใน Classroom ตรงกับ Krucheck', ['UC-T72', 'UC-T73', 'UC-T74', 'UC-T75', 'UC-T76', 'UC-T83', 'UC-X03', 'UC-T77', 'UC-T78']),
            $c('PR-07', 'ครู 2 คน', 'สอนร่วมห้องเดียวกัน',
                'ครูประจำชั้นเชิญครูวิชาอื่นมาสอนห้องของตน',
                ['ครู ก เชิญครู ข ด้วยอีเมล', 'ครู ข ตอบรับ', 'ครู ข สร้างรายวิชาของตัวเองและผูกกับห้อง', 'ครู ข สั่งการบ้านและเผยแพร่ผล', 'ครู ก เห็นงานและคะแนนของวิชาครู ข', 'ครู ข เลิกสอนร่วม'],
                'หลังเลิกสอนร่วม ครู ข ไม่เห็นห้องนั้นอีก และงานเดิมยังอยู่กับห้อง', ['UC-T19', 'UC-T20', 'UC-T22', 'UC-T27', 'UC-T43', 'UC-T21']),
            $c('PR-08', 'ครู', 'ขึ้นปีการศึกษาใหม่',
                'ปิดห้องปีก่อน สร้างห้องใหม่ และพานักเรียนเดิมขึ้นชั้น',
                ['ปิดห้องปีก่อน ตรวจว่าเปิดอ่านได้แต่แก้ไม่ได้', 'สร้างห้องใหม่และนำนักเรียนจากห้องเดิม', 'แก้เลขที่และรีเซ็ตรหัสผ่านนักเรียน 1 คน', 'รวมบัญชีนักเรียนที่ซ้ำ 1 คู่'],
                'ห้องใหม่มีนักเรียนเดิมพร้อมประวัติ ห้องเก่าอ่านได้อย่างเดียว', ['UC-T18', 'UC-T10', 'UC-T13', 'UC-T15', 'UC-T17']),
            $c('PR-09', 'ครู นักเรียน', 'หนึ่งวันในห้องเรียน',
                'ตารางสอน เช็คชื่อ และประกาศในวันเดียว',
                ['จัดตารางสอน 3 คาบ', 'เช็คชื่อคาบแรก มีคนขาดและสาย', 'เขียนประกาศพร้อมไฟล์แนบ ตั้งเวลาเผยแพร่อีก 5 นาที', 'รอโพสต์เผยแพร่เอง', 'นักเรียนเห็นแจ้งเตือน อ่านโพสต์ ดูตารางเรียนและการเข้าเรียนของตัวเอง', 'ครูดูสรุปการเข้าเรียน'],
                'นักเรียนเห็นโพสต์ตรงเวลา และเห็นสถานะเข้าเรียนตรงกับที่ครูเช็ค', ['UC-T84', 'UC-T85', 'UC-T80', 'UC-T82', 'UC-X05', 'UC-X07', 'UC-S21', 'UC-S07', 'UC-S08', 'UC-S09', 'UC-T86']),
            $c('PR-10', 'ครู', 'ทำงานร่วมกับผู้ช่วย Krucheck',
                'ใช้ผู้ช่วยสร้างงานจากไฟล์และถามผลนักเรียน',
                ['ถามผู้ช่วยว่าจะตัดเกรดอย่างไร', 'แนบไฟล์ใบงานให้ผู้ช่วยอ่าน แล้วให้สร้างข้อ 3 ข้อเข้าการบ้าน', 'ให้ผู้ช่วยร่าง rubric และเสนอตัวชี้วัด แล้วยืนยัน', 'ให้ผู้ช่วยตั้งหมวดคะแนนและสร้างแบบฝึก', 'ถามว่านักเรียนคนไหนอ่อนตัวชี้วัดใด', 'เปลี่ยนชื่อและลบบทสนทนา'],
                'ข้อ rubric หมวดคะแนน และแบบฝึกที่ยืนยันแล้วอยู่ในระบบจริง', ['UC-T92', 'UC-T97', 'UC-T93', 'UC-T94', 'UC-T95', 'UC-T96', 'UC-T98']),
            $c('PR-11', 'นักเรียน ครู', 'เรียนซ่อมจากจุดที่ยังไม่เข้าใจ',
                'นักเรียนรู้ว่าอ่อนเรื่องใด ฝึก แล้วเห็นตัวเองดีขึ้น',
                ['ครูเพิ่มแบบฝึกและลิงก์ทบทวนให้ตัวชี้วัดหนึ่งข้อ', 'ครูให้ AI วิเคราะห์รายคนและอนุมัติข้อความ', 'นักเรียนดูทักษะ ข้อความจากครู และกราฟความเข้าใจ', 'นักเรียนทำแบบฝึก 3 ข้อและเปิดลิงก์ทบทวน', 'ครูดูทักษะของนักเรียนคนนั้นอีกครั้ง'],
                'ค่าความเข้าใจของนักเรียนเปลี่ยนตามแบบฝึกที่ทำ', ['UC-T70', 'UC-T71', 'UC-T69', 'UC-X04', 'UC-S13', 'UC-S16', 'UC-S14', 'UC-T67']),
            $c('PR-12', 'ผู้ดูแลระบบ', 'ดูแลระบบหนึ่งรอบ',
                'งานประจำของผู้ดูแลระบบตั้งแต่อนุมัติครูจนลบบัญชี',
                ['เข้าหน้าผู้ดูแลระบบ อนุมัติครู และเพิ่มผู้ใช้ 1 คน', 'ตั้งรหัสผ่านใหม่ให้ครู ระงับแล้วเปิดใช้บัญชี', 'เข้าใช้แทนครูเพื่อดูปัญหา แล้วกลับ', 'ดูปัญหาการเชื่อม Google บันทึกการใช้งาน และการเรียก AI', 'อ่านบทสนทนากับผู้ช่วยของครู', 'ลบบัญชีทดลองแล้วกู้คืน'],
                'ทุกขั้นสำเร็จโดยไม่ต้องใช้ฐานข้อมูลโดยตรง และบันทึกการใช้งานมีครบ', ['UC-A01', 'UC-A02', 'UC-A03', 'UC-A04', 'UC-A05', 'UC-A08', 'UC-A10', 'UC-A11', 'UC-A16', 'UC-A17', 'UC-A09']),
        ];
    }

    /**
     * One usability task per use case (DESIGN §39.6), from the JSON that
     * tools/qa-usability.py writes. The use case's outcome is the success
     * criterion; its alternative paths are extra checks.
     *
     * @return list<array<string, mixed>>
     */
    private static function functions(): array
    {
        if (self::$functions !== null) {
            return self::$functions;
        }
        $json = json_decode((string) @file_get_contents(base_path(self::USABILITY_JSON)), true);
        $out = [];
        foreach (is_array($json['cases'] ?? null) ? $json['cases'] : [] as $uc) {
            $out[] = [
                'code' => (string) $uc['code'],
                'kind' => self::FUNCTION,
                'group' => (string) $uc['chapter'],
                'area' => (string) $uc['actor'],
                'actor' => (string) $uc['actor'],
                'title' => (string) $uc['title'],
                'detail' => (string) ($uc['goal'] ?? ''),
                'goal' => (string) ($uc['goal'] ?? ''),
                'pre' => (string) ($uc['pre'] ?? ''),
                'outcome' => (string) ($uc['outcome'] ?? ''),
                'done' => (string) ($uc['outcome'] ?? ''),
                'platforms' => (string) ($uc['platforms'] ?? ''),
                'steps' => array_values(array_map('strval', $uc['steps'] ?? [])),
                'alts' => array_values(array_map(fn ($a) => ['label' => (string) $a['label'], 'text' => (string) $a['text']], $uc['alts'] ?? [])),
            ];
        }

        return self::$functions = $out;
    }
}
