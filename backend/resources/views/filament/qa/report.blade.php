{{-- "รายงาน Usability" (DESIGN §39.8): the short set for five testers, printable. --}}
@use('App\Domain\Qa\QaCatalog')
@use('App\Filament\Qa\Pages\MyResults')
@use('App\Filament\Qa\Pages\SusSurvey')
<x-filament-panels::page>
    @include('filament.qa._styles')
    <style>
        @media print {
            .fi-sidebar, .fi-topbar, .qa-noprint { display: none !important; }
            .fi-main { padding: 0 !important; }
        }
    </style>
    @php
        $board = $this->board();
        $tasks = $this->tasks();
        $people = $this->participants($board);
        $sus = $this->susMean($board, $people);
        $statusLabels = QaCatalog::USABILITY_STATUSES;
        $min = QaCatalog::QUICK_MIN_TESTERS;
        $time = fn (?float $s) => $s === null ? '—' : (floor($s / 60) > 0 ? floor($s / 60).' นาที ' : '').round(fmod($s, 60)).' วิ';
    @endphp

    <div class="qa-row qa-noprint">
        <a class="qa-link qa-small" href="{{ MyResults::getUrl(['section' => QaCatalog::QUICK_GROUP]) }}">บันทึกผลชุดย่อ</a>
        <a class="qa-link qa-small" href="{{ SusSurvey::getUrl() }}">แบบประเมิน SUS</a>
        <x-filament::button size="sm" color="gray" icon="heroicon-o-printer" x-data x-on:click="window.print()">พิมพ์หรือบันทึกเป็น PDF</x-filament::button>
    </div>

    <x-filament::section>
        <x-slot name="heading">วิธีทดสอบ</x-slot>
        <p class="qa-small">
            ผู้ทดสอบภายนอกแต่ละคนทำ {{ count($tasks) }} งานบนระบบสาธิตโดยไม่มีคนบอกวิธี (think-aloud) ผู้ดูแลจับเวลาตั้งแต่ผู้ทดสอบอ่านโจทย์จบ
            นับครั้งที่ทำผิด และบันทึกว่าสำเร็จเอง สำเร็จเมื่อมีคนช่วย หรือไม่สำเร็จ จากนั้นผู้ทดสอบตอบแบบประเมิน SUS 10 ข้อ (เกณฑ์ผ่าน {{ QaCatalog::SUS_BENCHMARK }})
        </p>
        <div class="qa-stats" style="margin-top:12px;">
            <div class="qa-stat"><span class="k">ผู้ทดสอบ</span><span class="v">{{ count($people) }} / {{ $min }}</span>
                <span class="s">{{ count($people) >= $min ? 'ครบตามเกณฑ์โครงงาน' : 'ยังขาดอีก '.($min - count($people)).' คน' }}</span></div>
            <div class="qa-stat"><span class="k">SUS เฉลี่ย</span><span class="v">{{ $sus === null ? '—' : number_format($sus, 1) }}</span>
                <span class="s">{{ $sus === null ? 'ยังไม่มีคำตอบ' : ($sus >= QaCatalog::SUS_BENCHMARK ? 'สูงกว่าเกณฑ์ '.QaCatalog::SUS_BENCHMARK : 'ต่ำกว่าเกณฑ์ '.QaCatalog::SUS_BENCHMARK) }}</span></div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ผลต่องาน</x-slot>
        <div class="qa-table-wrap">
            <table class="qa-table">
                <thead><tr><th>งาน</th><th>ผู้ทำ</th><th>สำเร็จเอง</th><th>มีคนช่วย</th><th>ไม่สำเร็จ</th><th>อัตราสำเร็จเอง</th><th>เวลาเฉลี่ย</th><th>ทำผิดเฉลี่ย</th></tr></thead>
                <tbody>
                    @foreach ($tasks as $t)
                        @php $u = $board->usability($t['code']); @endphp
                        <tr wire:key="task-{{ $t['code'] }}">
                            <td><span class="qa-code">{{ $t['code'] }}</span> {{ $t['title'] }} <span class="qa-muted">({{ $t['actor'] }})</span></td>
                            <td class="qa-num">{{ $u['n'] }}</td>
                            <td class="qa-num">{{ $u['success'] }}</td>
                            <td class="qa-num">{{ $u['assisted'] }}</td>
                            <td class="qa-num">{{ $u['fail'] }}</td>
                            <td class="qa-num">{{ $u['n'] === 0 ? '—' : round($u['success'] / $u['n'] * 100).'%' }}</td>
                            <td class="qa-num">{{ $time($u['avg_time']) }}</td>
                            <td class="qa-num">{{ $u['avg_errors'] === null ? '—' : number_format($u['avg_errors'], 1) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ผลรายคน</x-slot>
        @if ($people === [])
            <p class="qa-small qa-muted">ยังไม่มีผู้ทดสอบบันทึกผลชุดย่อหรือตอบ SUS</p>
        @else
            <div class="qa-table-wrap">
                <table class="qa-table">
                    <thead><tr><th>ผู้ทดสอบ</th>@foreach ($tasks as $t)<th>{{ $t['code'] }}</th>@endforeach<th>SUS</th></tr></thead>
                    <tbody>
                        @foreach ($people as $p)
                            <tr wire:key="person-{{ $p->id }}">
                                <td>{{ $p->name }}</td>
                                @foreach ($tasks as $t)
                                    @php $r = $board->result($p->id, $t['code']); @endphp
                                    <td class="qa-small">
                                        @if ($r === null)
                                            <span class="qa-muted">—</span>
                                        @else
                                            {{ $statusLabels[$r->status][0] ?? $r->status }}@if ($r->time_sec !== null)<br><span class="qa-muted">{{ $time((float) $r->time_sec) }}</span>@endif
                                        @endif
                                    </td>
                                @endforeach
                                <td class="qa-num">{{ $board->sus($p->id)?->score === null ? '—' : number_format($board->sus($p->id)->score, 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">สิ่งที่ผู้ทดสอบพูดและข้อเสนอแนะ</x-slot>
        @php
            $notes = [];
            foreach ($people as $p) {
                foreach ($tasks as $t) {
                    $note = trim((string) $board->result($p->id, $t['code'])?->note);
                    if ($note !== '') $notes[] = [$p->name, $t['code'], $note];
                }
                $comment = trim((string) $board->sus($p->id)?->comment);
                if ($comment !== '') $notes[] = [$p->name, 'SUS', $comment];
            }
        @endphp
        @forelse ($notes as [$who, $where, $text])
            <p class="qa-small"><span style="font-weight:600;">{{ $who }}</span> <span class="qa-code">{{ $where }}</span> {{ $text }}</p>
        @empty
            <p class="qa-small qa-muted">ยังไม่มีบันทึก</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
