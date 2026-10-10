<?php

namespace App\Jobs;

use App\Domain\Students\CredentialIssuer;
use App\Domain\Students\LoginCardPrintService;
use App\Domain\Students\LoginCardRenderer;
use App\Models\Classroom;
use App\Models\LoginCardPrint;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders one login_card_prints row to a PDF on the private disk.
 *
 * Order matters: the new QR tokens are generated in memory, the PDF is rendered
 * and stored first, and only then are the credential rows rotated (and the
 * students' sessions revoked) in one transaction. A failed render therefore
 * changes nothing for the students, and the print is marked `failed`.
 */
class RenderLoginCardsJob implements ShouldQueue
{
    use Queueable;

    /** One attempt: a retry would rotate the tokens a second time. */
    public int $tries = 1;

    /**
     * Seconds before the worker kills a render (eduvision:queue-work runs with
     * --max-time=50 every minute). A killed job ends in failed() below.
     */
    public int $timeout = 45;

    /**
     * @param  list<int>|null  $studentIds  a classroom print of only these students (DESIGN §24.4); null = the whole classroom
     */
    public function __construct(public readonly int $printId, public readonly ?array $studentIds = null)
    {
        $this->onQueue('pdf');
    }

    public function handle(LoginCardRenderer $renderer, CredentialIssuer $issuer): void
    {
        $print = LoginCardPrint::query()->with(['school', 'classroom', 'student'])->find($this->printId);
        if ($print === null || $print->status !== LoginCardPrint::STATUS_QUEUED) {
            return;
        }

        $print->update(['status' => LoginCardPrint::STATUS_RENDERING, 'error' => null]);

        try {
            $rows = $this->rows($print);
            if ($rows === []) {
                throw new \RuntimeException('ห้องนี้ยังไม่มีนักเรียน');
            }

            /** @var array<int, array{student: User, qr_token: string}> $issued */
            $issued = [];
            $cards = [];
            foreach ($rows as $row) {
                $qrToken = CredentialIssuer::randomQrToken();
                $issued[] = ['student' => $row['student'], 'qr_token' => $qrToken];
                $cards[] = [
                    'student_number' => $row['student_number'],
                    'name' => $row['student']->name,
                    'classroom_name' => $row['classroom_name'],
                    'class_code' => $row['class_code'],
                    'qr_payload' => CredentialIssuer::qrPayload($qrToken),
                ];
            }

            // The requesting teacher's own school name when they set one (DESIGN §29.1).
            $schoolName = User::query()->whereKey($print->requested_by)->value('school_name') ?? $print->school->name;
            $pdf = $renderer->render($schoolName, $cards);
            $path = LoginCardPrintService::filePath($print);
            Storage::disk('local')->put($path, $pdf);

            DB::transaction(function () use ($issued, $issuer, $print, $path) {
                foreach ($issued as $item) {
                    $issuer->rotateQrToken($item['student'], $item['qr_token']);
                }

                $print->update(['status' => LoginCardPrint::STATUS_READY, 'file_path' => $path]);
            });
        } catch (Throwable $e) {
            $print->update([
                'status' => LoginCardPrint::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 255),
            ]);

            report($e);
        }
    }

    /**
     * Called by the worker when the job dies outside handle()'s own catch, for
     * example killed by $timeout in the middle of mPDF. The print must still
     * reach a terminal state or the app would poll it forever; nothing was
     * rotated because the token rotation is the last step of handle().
     */
    public function failed(?Throwable $e): void
    {
        $print = LoginCardPrint::query()->find($this->printId);
        if ($print === null || ! in_array($print->status, [LoginCardPrint::STATUS_QUEUED, LoginCardPrint::STATUS_RENDERING], true)) {
            return;
        }

        $print->update([
            'status' => LoginCardPrint::STATUS_FAILED,
            'error' => mb_substr($e?->getMessage() ?: 'การสร้าง PDF ถูกยกเลิก', 0, 255),
        ]);
    }

    /**
     * @return array<int, array{student: User, student_number: int, classroom_name: string, class_code: string}>
     */
    private function rows(LoginCardPrint $print): array
    {
        if ($print->classroom_id !== null) {
            $classroom = $print->classroom;

            return $classroom->students()
                ->when($this->studentIds !== null, fn ($q) => $q->whereIn('users.id', $this->studentIds))
                ->get()
                ->map(fn (User $student) => [
                    'student' => $student,
                    'student_number' => (int) $student->pivot->student_number,
                    'classroom_name' => $classroom->name,
                    'class_code' => $classroom->class_code,
                ])
                ->all();
        }

        $student = $print->student;
        if ($student === null) {
            return [];
        }

        // The card names one classroom: the newest open one (DESIGN §24.4: the
        // same card logs in through any of them), else the newest closed one.
        /** @var Classroom|null $classroom */
        $classroom = $student->classrooms()
            ->orderByRaw('CASE WHEN classrooms.closed_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('classrooms.academic_year')
            ->orderByDesc('classrooms.id')
            ->first();
        if ($classroom === null) {
            return [];
        }

        return [[
            'student' => $student,
            'student_number' => (int) $classroom->pivot->student_number,
            'classroom_name' => $classroom->name,
            'class_code' => $classroom->class_code,
        ]];
    }
}
