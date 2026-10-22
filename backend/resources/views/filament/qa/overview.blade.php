{{-- Test Lab overview (DESIGN §39). --}}
@use('App\Domain\Qa\QaBoard')
@use('App\Domain\Qa\QaCatalog')
@use('App\Filament\Qa\Pages\TeamBoard')
@use('App\Filament\Qa\Pages\TestCaseDetail')
<x-filament-panels::page>
    @include('filament.qa._styles')
    @php
        $board = $this->board();
        $ci = $this->ci();
        $deployed = $this->deployedCommit();
        $testers = $board->testers();
        $auto = $this->cases(QaCatalog::AUTO);
        $manual = $this->cases(QaCatalog::MANUAL);
        $ux = QaCatalog::ofKinds(QaCatalog::USABILITY_KINDS);
        $processes = $this->cases(QaCatalog::PROCESS);
        $groups = $board->groupSummary();
        $autoPass = collect($auto)->filter(fn ($c) => QaBoard::autoVerdict($ci, $c['workflow'])[0] === 'pass')->count();
        $manualVerdicts = collect($manual)->mapWithKeys(fn ($c) => [$c['code'] => $board->manualVerdict($c['code'])]);
        $manualPass = $manualVerdicts->filter(fn ($v) => $v[0] === 'pass')->count();
        $manualFail = $manualVerdicts->filter(fn ($v) => $v[0] === 'fail')->count();
        $uxTotals = $board->usabilityTotals();
        $sus = $board->susSummary();
        $caseUrl = fn (string $code) => TestCaseDetail::getUrl(['code' => $code]);
    @endphp

    <div class="qa-stats">
        <div class="qa-stat">
            <span class="k">Integration อัตโนมัติ</span>
            <span class="v">{{ $ci ? $autoPass.'/'.count($auto) : '–' }}</span>
            <span class="s">{{ $ci ? 'CI ล่าสุดของ main · '.$ci['sha'] : 'อ่านผล CI จาก GitHub ไม่ได้ตอนนี้' }}</span>
        </div>
        <div class="qa-stat">
            <span class="k">Integration ทำมือ</span>
            <span class="v">{{ $manualPass }}/{{ count($manual) }}</span>
            <span class="s">{{ $manualFail ? "ไม่ผ่าน {$manualFail} รายการ" : 'ผ่านแล้ว' }}</span>
        </div>
        <div class="qa-stat">
            <span class="k">Usability สำเร็จเอง</span>
            <span class="v">{{ $uxTotals['attempts'] ? round($uxTotals['success'] / $uxTotals['attempts'] * 100).'%' : '–' }}</span>
            <span class="s">{{ $uxTotals['attempts'] ? "{$uxTotals['success']} จาก {$uxTotals['attempts']} ครั้งที่ทำ" : 'ยังไม่มีผล' }}</span>
        </div>
        <div class="qa-stat">
            <span class="k">SUS เฉลี่ย</span>
            <span class="v">{{ $sus['mean'] ?? '–' }}</span>
            <span class="s">{{ $sus['n'] ? "{$sus['n']} คน · ค่าอ้างอิง ".QaCatalog::SUS_BENCHMARK : 'ค่าอ้างอิง '.QaCatalog::SUS_BENCHMARK }}</span>
        </div>
    </div>

    <x-filament::section>
        <x-slot name="heading">รุ่นที่ใช้งานอยู่</x-slot>
        <div class="qa-row qa-small">
            @if ($deployed)
                <span>production ใช้ commit <span class="qa-code">{{ substr($deployed, 0, 7) }}</span></span>
                @if ($ci && str_starts_with($deployed, $ci['sha']))
                    <x-filament::badge color="success">ตรงกับ main</x-filament::badge>
                @elseif ($ci)
                    <x-filament::badge color="warning">main ล่าสุดคือ {{ $ci['sha'] }}</x-filament::badge>
                @endif
            @else
                <span class="qa-muted">เครื่องนี้ไม่ใช่ production (ไม่มีไฟล์ DEPLOY-COMMIT.txt)</span>
            @endif
            @if ($ci)
                <span class="qa-muted">· main: {{ $ci['title'] }}</span>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ผู้ทดสอบ</x-slot>
        <x-slot name="description">แต่ละคนเห็นผลของทุกคน แต่แก้ได้เฉพาะของตัวเองในหน้า "กรอกผล"</x-slot>
        @if ($testers->isEmpty())
            <p class="qa-muted qa-small">ยังไม่มีบัญชีผู้ทดสอบ admin ของ Krucheck สร้างได้ที่ /admin เมนู "ผู้ทดสอบระบบ"</p>
        @else
            <div class="qa-table-wrap"><table class="qa-table">
                <thead><tr><th>ผู้ทดสอบ</th><th class="qa-num">Integration ทำมือ</th><th class="qa-num">Usability</th><th class="qa-num">SUS</th><th>บทบาทที่ประเมิน</th></tr></thead>
                <tbody>
                @foreach ($testers as $t)
                    @php $s = $board->sus($t->id); @endphp
                    <tr>
                        <td>{{ $t->name }} @if ($t->is_lead)<x-filament::badge color="primary" size="sm">หัวหน้าทดสอบ</x-filament::badge>@endif</td>
                        <td class="qa-num">{{ $board->filled($t->id, QaCatalog::MANUAL) }}/{{ count($manual) }}</td>
                        <td class="qa-num">{{ $board->filled($t->id, QaCatalog::USABILITY_KINDS) }}/{{ count($ux) }}</td>
                        <td class="qa-num">{{ $s?->score ?? '–' }}</td>
                        <td>{{ $s ? ($s->role === 'teacher' ? 'ครู' : 'นักเรียน') : '–' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Integration test อัตโนมัติ</x-slot>
        <x-slot name="description">ผลจาก GitHub Actions รอบล่าสุดของ main แต่ละรายการกดดูไฟล์ test และ test ทุกตัวในนั้นได้</x-slot>
        @if ($this->isLead())
            <x-slot name="headerEnd">
                <x-filament::button size="sm" color="gray" wire:click="refreshCi">อ่านผล CI ใหม่</x-filament::button>
            </x-slot>
        @endif
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th>รหัส</th><th>สิ่งที่ตรวจ</th><th>workflow</th><th>ผล</th></tr></thead>
            <tbody>
            @foreach ($auto as $c)
                @php [$v, $label, $tone] = QaBoard::autoVerdict($ci, $c['workflow']); $run = $ci['workflows'][$c['workflow']] ?? null; @endphp
                <tr>
                    <td class="qa-code"><a class="qa-link" href="{{ $caseUrl($c['code']) }}">{{ $c['code'] }}</a></td>
                    <td>{{ $c['title'] }}</td>
                    <td class="qa-code">@if ($run)<a class="qa-link" href="{{ $run['url'] }}" target="_blank" rel="noopener">{{ $c['workflow'] }}</a>@else{{ $c['workflow'] }}@endif</td>
                    <td><x-filament::badge :color="$tone">{{ $label }}</x-filament::badge></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Integration test ทำมือ</x-slot>
        <x-slot name="description">สถานะรวม: มีคนรายงานไม่ผ่าน = ไม่ผ่าน, มีคนผ่านและไม่มีใครรายงานไม่ผ่าน = ผ่าน</x-slot>
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th>รหัส</th><th>รายการ</th><th>สถานะรวม</th>@foreach ($testers as $t)<th>{{ $t->name }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($manual as $c)
                @php [$v, $label, $tone] = $manualVerdicts[$c['code']]; @endphp
                <tr>
                    <td class="qa-code"><a class="qa-link" href="{{ $caseUrl($c['code']) }}">{{ $c['code'] }}</a></td>
                    <td>{{ $c['title'] }}</td>
                    <td><x-filament::badge :color="$tone">{{ $label }}</x-filament::badge></td>
                    @foreach ($testers as $t)
                        @php $r = $board->result($t->id, $c['code']); $st = $r?->status ? QaCatalog::MANUAL_STATUSES[$r->status] ?? null : null; @endphp
                        <td title="{{ $r?->note }}">@if ($st)<x-filament::badge :color="$st[1]" size="sm">{{ $st[0] }}</x-filament::badge>@else<span class="qa-muted">–</span>@endif</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Usability: กระบวนการตั้งแต่ต้นจนจบ</x-slot>
        <x-slot name="description">แถบ: เขียว = สำเร็จเอง, เหลือง = สำเร็จเมื่อมีคนช่วย, แดง = ไม่สำเร็จ ("ข้าม" ไม่นับ) เวลาเป็นค่าเฉลี่ยของคนที่สำเร็จ</x-slot>
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th>รหัส</th><th>สถานการณ์</th><th>ผล</th><th class="qa-num">เวลาเฉลี่ย</th><th class="qa-num">ทำผิดเฉลี่ย</th></tr></thead>
            <tbody>
            @foreach ($processes as $c)
                @php $u = $board->usability($c['code']); $pct = fn ($n) => $u['n'] ? round($n / $u['n'] * 100, 1) : 0; @endphp
                <tr>
                    <td class="qa-code"><a class="qa-link" href="{{ $caseUrl($c['code']) }}">{{ $c['code'] }}</a></td>
                    <td>{{ $c['title'] }} <span class="qa-muted qa-small">({{ $c['actor'] }})</span></td>
                    <td>
                        @if ($u['n'])
                            <div class="qa-bar" role="img" aria-label="สำเร็จเอง {{ $u['success'] }} สำเร็จเมื่อช่วย {{ $u['assisted'] }} ไม่สำเร็จ {{ $u['fail'] }}">
                                <i class="qa-ok" style="width: {{ $pct($u['success']) }}%"></i><i class="qa-warn" style="width: {{ $pct($u['assisted']) }}%"></i><i class="qa-bad" style="width: {{ $pct($u['fail']) }}%"></i>
                            </div>
                            <span class="qa-small qa-muted">{{ $u['success'] }} / {{ $u['assisted'] }} / {{ $u['fail'] }} จาก {{ $u['n'] }}</span>
                        @else
                            <span class="qa-muted">ยังไม่มีผล</span>
                        @endif
                    </td>
                    <td class="qa-num">{{ $u['avg_time'] === null ? '–' : round($u['avg_time']).' วิ' }}</td>
                    <td class="qa-num">{{ $u['avg_errors'] === null ? '–' : number_format($u['avg_errors'], 1) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Usability: ทุกฟังก์ชันแยกตามหมวด</x-slot>
        <x-slot name="description">ลองแล้ว = มีอย่างน้อยหนึ่งคนบันทึกผลที่ไม่ใช่ "ข้าม" กดชื่อหมวดเพื่อดูผลของทุกคนรายข้อในหน้าสถานะทีม</x-slot>
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th>หมวด</th><th class="qa-num">ลองแล้ว</th><th>ผลทั้งหมด</th></tr></thead>
            <tbody>
            @foreach ($groups as $group => $g)
                @continue($group === QaCatalog::PROCESS_GROUP)
                @php $n = $g['success'] + $g['assisted'] + $g['fail']; $pct = fn ($x) => $n ? round($x / $n * 100, 1) : 0; @endphp
                <tr>
                    <td><a class="qa-link" href="{{ TeamBoard::getUrl(['section' => $group]) }}">{{ $group }}</a></td>
                    <td class="qa-num">{{ $g['tried'] }}/{{ $g['tasks'] }}</td>
                    <td>
                        @if ($n)
                            <div class="qa-bar"><i class="qa-ok" style="width: {{ $pct($g['success']) }}%"></i><i class="qa-warn" style="width: {{ $pct($g['assisted']) }}%"></i><i class="qa-bad" style="width: {{ $pct($g['fail']) }}%"></i></div>
                            <span class="qa-small qa-muted">{{ $g['success'] }} / {{ $g['assisted'] }} / {{ $g['fail'] }} จาก {{ $n }} ครั้ง</span>
                        @else
                            <span class="qa-muted">ยังไม่มีผล</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
