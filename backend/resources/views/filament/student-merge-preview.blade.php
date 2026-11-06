{{-- "รวมบัญชีนักเรียน" in Filament (DESIGN §24.5): both accounts side by side. --}}
@php
    $rows = [
        'student_code' => 'เลขประจำตัว',
        'classrooms' => 'ห้อง',
        'submissions' => 'งานที่ส่ง (เผยแพร่แล้ว)',
        'gradebook_entries' => 'คะแนนในสมุดคะแนน',
        'special_grades' => 'ร/มส',
        'published_grades' => 'เกรดที่ประกาศ',
        'practice_attempts' => 'แบบฝึก',
        'observations' => 'ผลประเมินทักษะ (observation)',
        'mastery_skills' => 'ทักษะใน mastery',
        'analyses' => 'การวิเคราะห์รายคน',
        'google_emails' => 'บัญชี Google',
    ];
    $cell = function (array $account, string $key): string {
        $value = $account[$key] ?? null;

        return match ($key) {
            'classrooms' => collect($value)->map(fn ($c) => "{$c['name']} ({$c['academic_year']}) เลขที่ {$c['student_number']}".($c['closed'] ? ' · ห้องเก่า' : ''))->implode("\n") ?: '—',
            'submissions' => "{$value['total']} ({$value['published']})",
            'google_emails' => implode("\n", $value ?? []) ?: '—',
            default => $value === null || $value === '' ? '—' : (string) $value,
        };
    };
    $th = 'text-align:start;padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.25);vertical-align:top;';
    $td = 'padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.25);vertical-align:top;white-space:pre-line;';
@endphp
<div style="font-size:0.875rem;line-height:1.4;">
    @if ($error)
        <p style="color:rgb(220,38,38);">{{ $error }}</p>
    @elseif ($preview === null)
        <p style="opacity:.7;">เลือกบัญชีที่จะรวมเพื่อดูข้อมูลของทั้งสองบัญชี</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="{{ $th }}"></th>
                        <th style="{{ $th }}">เก็บไว้: {{ $preview['keep']['name'] }}</th>
                        <th style="{{ $th }}">ถูกรวม: {{ $preview['merge']['name'] }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $key => $label)
                        <tr>
                            <th style="{{ $th }}font-weight:500;">{{ $label }}</th>
                            <td style="{{ $td }}">{{ $cell($preview['keep'], $key) }}</td>
                            <td style="{{ $td }}">{{ $cell($preview['merge'], $key) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($preview['can_merge'])
            <p style="margin-top:12px;">
                ข้อมูลทั้งหมดของบัญชีที่ถูกรวมจะย้ายไปบัญชีที่เก็บไว้ บัญชีที่ถูกรวมถูกปิด PIN และบัตร QR ของบัญชีนั้นใช้ไม่ได้ทันที และย้อนกลับไม่ได้
            </p>
        @else
            <p style="margin-top:12px;color:rgb(220,38,38);font-weight:600;">รวมไม่ได้ เพราะข้อมูลของสองบัญชีชนกัน</p>
            <ul style="margin-top:4px;padding-inline-start:20px;list-style:disc;color:rgb(220,38,38);">
                @foreach ($preview['conflicts'] as $conflict)
                    <li>{{ $conflict['message'] }}</li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
