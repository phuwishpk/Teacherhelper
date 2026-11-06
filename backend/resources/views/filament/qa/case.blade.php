{{-- One test case in full (DESIGN §39). --}}
@use('App\Domain\Qa\QaBoard')
@use('App\Domain\Qa\QaCatalog')
@use('App\Domain\Qa\QaText')
@use('App\Filament\Qa\Pages\TestPlan')
<x-filament-panels::page>
    @include('filament.qa._styles')
    @php
        $c = $case;
        $board = $this->board();
        $kindLabel = QaCatalog::KIND_LABELS;
        $usability = QaCatalog::isUsability($c['kind']);
    @endphp

    <div class="qa-row">
        <x-filament::badge :color="$c['kind'] === QaCatalog::AUTO ? 'info' : ($usability ? 'warning' : 'gray')">{{ $kindLabel[$c['kind']] }}</x-filament::badge>
        @if (isset($c['group']))<x-filament::badge color="gray">{{ $c['group'] }}</x-filament::badge>@endif
        <x-filament::badge color="gray">{{ $c['area'] }}</x-filament::badge>
        @if (! empty($c['platforms']))<span class="qa-small qa-muted">ใช้ได้บน: {{ $c['platforms'] }}</span>@endif
        <a class="qa-link qa-small" href="{{ TestPlan::getUrl() }}">กลับไปแผนทดสอบ</a>
    </div>

    @if ($c['kind'] === QaCatalog::AUTO)
        @php $ci = $this->ci(); [$v, $label, $tone] = QaBoard::autoVerdict($ci, $c['workflow']); $run = $ci['workflows'][$c['workflow']] ?? null; $tests = $this->tests(); @endphp
        <x-filament::section>
            <x-slot name="heading">สิ่งที่ตรวจ</x-slot>
            <p>{{ $c['detail'] }}</p>
            <div class="qa-row qa-small" style="margin-top:10px;">
                <span>ผล CI ล่าสุดของ workflow <span class="qa-code">{{ $c['workflow'] }}</span></span>
                <x-filament::badge :color="$tone">{{ $label }}</x-filament::badge>
                @if ($run)
                    <span class="qa-muted">commit <span class="qa-code">{{ $run['sha'] }}</span> · {{ \Illuminate\Support\Carbon::parse($run['at'])->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</span>
                    <a class="qa-link" href="{{ $run['url'] }}" target="_blank" rel="noopener">เปิดใน GitHub</a>
                @endif
            </div>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">ไฟล์ test และ test ทุกตัว ({{ collect($tests)->sum(fn ($t) => count($t ?? [])) }} ตัว)</x-slot>
            <div class="qa-grid">
                @foreach ($tests as $ref => $names)
                    <div>
                        <a class="qa-link qa-code" href="{{ $this->githubUrl($ref) }}" target="_blank" rel="noopener">{{ $ref }}</a>
                        @if ($names === null)
                            <p class="qa-small qa-muted">เซิร์ฟเวอร์นี้ไม่มีรายชื่อ test ของไฟล์นี้ (สร้างตอน deploy ด้วย eduvision:qa-index)</p>
                        @elseif ($names === [])
                            <p class="qa-small qa-muted">ไม่พบ test ในไฟล์นี้</p>
                        @else
                            <ol class="qa-ol qa-small" style="margin-top:6px;">
                                @foreach ($names as $name)<li>{{ $name }}</li>@endforeach
                            </ol>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @elseif ($c['kind'] === QaCatalog::MANUAL)
        <x-filament::section>
            <x-slot name="heading">ทำไมต้องทำมือ</x-slot>
            <p>{{ $c['why'] }}</p>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">ขั้นตอน</x-slot>
            <ol class="qa-ol">@foreach ($c['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
            <p style="margin-top:10px;"><span style="font-weight:600;">ผลที่คาดหวัง:</span> {{ $c['expect'] }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">งานของผู้ทดสอบ ({{ $c['actor'] }})</x-slot>
            <div class="qa-grid" style="gap:8px;">
                <p><span style="font-weight:600;">เป้าหมาย:</span> {{ $c['goal'] }}</p>
                @if (! empty($c['pre']))<p><span style="font-weight:600;">เตรียมก่อนเริ่ม:</span> {{ QaText::render($c['pre']) }}</p>@endif
                <div>
                    <p style="font-weight:600;">ขั้นตอน</p>
                    <ol class="qa-ol">@foreach ($c['steps'] as $step)<li>{{ QaText::render($step) }}</li>@endforeach</ol>
                </div>
                <p><span style="font-weight:600;">เกณฑ์สำเร็จ:</span> {{ QaText::render($c['done']) }}</p>
                @if (! empty($c['ucs']))<p class="qa-small"><span style="font-weight:600;">use case ที่ผ่าน:</span> {{ QaText::render(implode(' → ', $c['ucs'])) }}</p>@endif
                <p class="qa-small qa-muted">บันทึก: สำเร็จเอง / สำเร็จเมื่อมีคนช่วย / ไม่สำเร็จ / ข้าม, เวลาเป็นวินาทีตั้งแต่อ่านโจทย์จบ, จำนวนครั้งที่ทำผิด, สิ่งที่ผู้ทดสอบพูดขณะทำ</p>
            </div>
        </x-filament::section>
        @if (! empty($c['alts']))
            <x-filament::section>
                <x-slot name="heading">กรณีอื่น ({{ count($c['alts']) }})</x-slot>
                <div class="qa-table-wrap"><table class="qa-table">
                    <thead><tr><th>กรณี</th><th>สิ่งที่ควรเกิด</th>@foreach ($board->testers() as $t)<th>{{ $t->name }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($c['alts'] as $alt)
                        <tr>
                            <td class="qa-code">{{ $alt['label'] }}</td>
                            <td class="qa-small">{{ QaText::render($alt['text']) }}</td>
                            @foreach ($board->testers() as $t)
                                @php $v = $board->result($t->id, $c['code'])?->checks[$alt['label']] ?? null; $cs = $v ? QaCatalog::CHECK_STATUSES[$v] ?? null : null; @endphp
                                <td>@if ($cs)<x-filament::badge :color="$cs[1]" size="sm">{{ $cs[0] }}</x-filament::badge>@else<span class="qa-muted">–</span>@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </x-filament::section>
        @endif
    @endif

    @if ($c['kind'] !== QaCatalog::AUTO)
        @php $statuses = QaCatalog::statusesFor($c['kind']); @endphp
        <x-filament::section>
            <x-slot name="heading">ผลของผู้ทดสอบ</x-slot>
            @if ($board->testers()->isEmpty())
                <p class="qa-muted qa-small">ยังไม่มีผู้ทดสอบ</p>
            @else
                <div class="qa-table-wrap"><table class="qa-table">
                    <thead><tr><th>ผู้ทดสอบ</th><th>ผล</th>@if ($usability)<th class="qa-num">เวลา</th><th class="qa-num">ทำผิด</th>@endif<th>บันทึก</th><th>เมื่อ</th></tr></thead>
                    <tbody>
                    @foreach ($board->testers() as $t)
                        @php $r = $board->result($t->id, $c['code']); $st = $r?->status ? $statuses[$r->status] ?? null : null; @endphp
                        <tr>
                            <td>{{ $t->name }}</td>
                            <td>@if ($st)<x-filament::badge :color="$st[1]" size="sm">{{ $st[0] }}</x-filament::badge>@else<span class="qa-muted">ยังไม่ทำ</span>@endif</td>
                            @if ($usability)
                                <td class="qa-num">{{ $r?->time_sec !== null ? $r->time_sec.' วิ' : '–' }}</td>
                                <td class="qa-num">{{ $r?->error_count ?? '–' }}</td>
                            @endif
                            <td class="qa-small" style="white-space:pre-line;">{{ $r?->note ?? '' }}</td>
                            <td class="qa-small qa-muted" style="white-space:nowrap;">{{ $r?->updated_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
