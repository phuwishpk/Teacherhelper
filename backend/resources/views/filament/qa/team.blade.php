{{-- "สถานะทีม": everyone's status side by side (DESIGN §39.6). --}}
@use('App\Domain\Qa\QaCatalog')
@use('App\Filament\Qa\Pages\TestCaseDetail')
<x-filament-panels::page>
    @include('filament.qa._styles')
    @php
        $board = $this->board();
        $testers = $board->testers();
        $cases = $this->cases($board);
        $kinds = [QaCatalog::MANUAL => 'Integration ทำมือ', QaCatalog::PROCESS => 'กระบวนการ', QaCatalog::FUNCTION => 'ฟังก์ชัน'];
    @endphp

    <x-filament::section>
        <x-slot name="heading">ความคืบหน้าของแต่ละคน</x-slot>
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th>ผู้ทดสอบ</th>@foreach ($kinds as $k => $label)<th class="qa-num">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($testers as $t)
                <tr>
                    <td>{{ $t->name }} @if ($t->is_lead)<x-filament::badge color="primary" size="sm">หัวหน้า</x-filament::badge>@endif</td>
                    @foreach ($kinds as $k => $label)
                        <td class="qa-num">{{ $board->filled($t->id, $k) }}/{{ count(QaCatalog::ofKind($k)) }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>

    <div class="qa-row">
        <div class="qa-field" style="min-width:260px;flex:1;">
            <label for="team-section">ส่วน</label>
            <select class="qa-input" id="team-section" wire:model.live="section">
                @foreach ($this->sections() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </div>
        <label class="qa-row qa-small" style="cursor:pointer;">
            <input type="checkbox" wire:model.live="untried" id="team-untried">
            <span>เฉพาะที่ยังไม่มีใครบันทึก</span>
        </label>
    </div>

    <x-filament::section>
        <x-slot name="heading">ผลของทุกคนในส่วนนี้ ({{ count($cases) }} รายการ)</x-slot>
        <x-slot name="description">ชี้ที่ผลเพื่อดูบันทึกของคนนั้น กดรหัสเพื่อดูรายละเอียดและบันทึกทั้งหมด</x-slot>
        @if ($cases === [])
            <p class="qa-muted qa-small">ทุกรายการในส่วนนี้มีคนบันทึกแล้ว</p>
        @else
            <div class="qa-table-wrap"><table class="qa-table">
                <thead><tr><th>รหัส</th><th>รายการ</th>@foreach ($testers as $t)<th>{{ $t->name }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($cases as $c)
                    @php $statuses = QaCatalog::statusesFor($c['kind']); @endphp
                    <tr wire:key="team-{{ $c['code'] }}">
                        <td class="qa-code"><a class="qa-link" href="{{ TestCaseDetail::getUrl(['code' => $c['code']]) }}">{{ $c['code'] }}</a></td>
                        <td>{{ $c['title'] }} <span class="qa-muted qa-small">({{ $c['area'] }})</span></td>
                        @foreach ($testers as $t)
                            @php $r = $board->result($t->id, $c['code']); $st = $r?->status ? $statuses[$r->status] ?? null : null; @endphp
                            <td title="{{ $r?->note }}">
                                @if ($st)
                                    <x-filament::badge :color="$st[1]" size="sm">{{ $st[0] }}</x-filament::badge>
                                    @if ($r->checks)<div class="qa-small qa-muted">กรณีอื่น ผ่าน {{ count(array_filter($r->checks, fn ($v) => $v === 'pass')) }} · ไม่ผ่าน {{ count(array_filter($r->checks, fn ($v) => $v === 'fail')) }}</div>@endif
                                @else
                                    <span class="qa-muted">–</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
