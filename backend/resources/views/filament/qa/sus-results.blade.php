{{-- "ผล SUS": the evaluators' SUS, reported by the standard (DESIGN §39.7). --}}
@use('App\Domain\Qa\QaSus')
<x-filament-panels::page>
    @include('filament.qa._styles')
    @php
        $board = $this->board();
        $filter = $this->roleFilter();
        $responses = $board->susResponses($filter);
        $groups = ['all' => 'ทั้งสองฝั่ง', ...QaSus::ROLES];
        $evaluators = $this->evaluators();
        $sentBy = $board->susResponses()->groupBy('qa_tester_id');
    @endphp

    <div class="qa-row">
        @foreach ($groups as $value => $label)
            <x-filament::button size="sm" :color="$role === $value ? 'primary' : 'gray'" wire:click="$set('role', '{{ $value }}')">{{ $label }} ({{ $board->susStats($value === 'all' ? null : $value)['n'] }})</x-filament::button>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">สรุปตามมาตรฐาน SUS</x-slot>
        <x-slot name="description">คะแนน SUS อยู่ระหว่าง 0–100 และไม่ใช่เปอร์เซ็นต์ ค่าเฉลี่ยอ้างอิงจากงานวิจัยราว 500 ชิ้นคือ {{ QaSus::BENCHMARK }} (Sauro, 2011)</x-slot>
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th>กลุ่ม</th><th class="qa-num">n</th><th class="qa-num">ค่าเฉลี่ย ± SD</th><th class="qa-num">มัธยฐาน</th><th class="qa-num">ต่ำสุด–สูงสุด</th><th class="qa-num">ช่วงเชื่อมั่น 95%</th><th>เกรด</th><th>การยอมรับ</th><th>คำคุณศัพท์</th><th>เทียบ {{ QaSus::BENCHMARK }}</th></tr></thead>
            <tbody>
            @foreach ($groups as $value => $label)
                @php $st = $board->susStats($value === 'all' ? null : $value); @endphp
                <tr>
                    <td>{{ $label }}</td>
                    <td class="qa-num">{{ $st['n'] }}</td>
                    @if ($st['n'] === 0)
                        <td colspan="8" class="qa-muted">ยังไม่มีผู้ประเมิน</td>
                    @else
                        <td class="qa-num">{{ $st['mean'] }}{{ $st['sd'] !== null ? ' ± '.$st['sd'] : '' }}</td>
                        <td class="qa-num">{{ $st['median'] }}</td>
                        <td class="qa-num">{{ $st['min'] }}–{{ $st['max'] }}</td>
                        <td class="qa-num">{{ $st['ci_low'] !== null ? $st['ci_low'].'–'.$st['ci_high'] : 'ต้องมีอย่างน้อย 2 คน' }}</td>
                        <td><x-filament::badge :color="$st['mean'] >= 80.8 ? 'success' : ($st['mean'] >= 65 ? 'warning' : 'danger')">{{ QaSus::grade($st['mean']) }}</x-filament::badge></td>
                        <td class="qa-small">{{ QaSus::acceptability($st['mean']) }}</td>
                        <td class="qa-small">{{ QaSus::adjectiveFor($st['mean']) }}</td>
                        <td class="qa-small">
                            @if ($st['ci_low'] !== null && $st['ci_low'] > QaSus::BENCHMARK) สูงกว่าอย่างมีนัยสำคัญ
                            @elseif ($st['ci_high'] !== null && $st['ci_high'] < QaSus::BENCHMARK) ต่ำกว่าอย่างมีนัยสำคัญ
                            @else {{ $st['mean'] >= QaSus::BENCHMARK ? 'สูงกว่า' : 'ต่ำกว่า' }} (ช่วงเชื่อมั่นคร่อม {{ QaSus::BENCHMARK }})
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <p class="qa-small qa-muted" style="margin-top:8px;">เกรดตาม curved grading scale ของ Sauro &amp; Lewis (2016): A+ ≥ 84.1, A ≥ 80.8, A- ≥ 78.9, B+ ≥ 77.2, B ≥ 74.1, B- ≥ 72.6, C+ ≥ 71.1, C ≥ 65.0, C- ≥ 62.7, D ≥ 51.7, F ต่ำกว่านั้น · การยอมรับตาม Bangor และคณะ (2008): ≥ 70 ยอมรับได้, 50–69.9 ก้ำกึ่ง, ต่ำกว่า 50 ยอมรับไม่ได้ · คำคุณศัพท์คือคำที่ค่าเฉลี่ย SUS ของคำนั้น (Bangor และคณะ, 2009) ใกล้กับค่าเฉลี่ยของกลุ่มที่สุด · ช่วงเชื่อมั่นใช้การแจกแจง t</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">รายข้อ ({{ $groups[$role] ?? $groups['all'] }})</x-slot>
        <x-slot name="description">ค่าเฉลี่ยคำตอบ 1–5 และคะแนนที่แต่ละข้อให้ 0–4 (ข้อบวก: คำตอบ − 1, ข้อลบ: 5 − คำตอบ) ข้อที่ให้คะแนนต่ำกว่า 2 คือจุดที่ควรปรับ</x-slot>
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th class="qa-num">ข้อ</th><th>ข้อความ</th><th>ทิศ</th><th class="qa-num">เฉลี่ยคำตอบ</th><th class="qa-num">คะแนนข้อ (0–4)</th></tr></thead>
            <tbody>
            @foreach ($this->items($responses) as $i => $item)
                <tr>
                    <td class="qa-num">{{ $i + 1 }}</td>
                    <td>{{ $item['th'] }}<br><span class="qa-small qa-muted" lang="en">{{ $item['en'] }}</span></td>
                    <td class="qa-small">{{ $item['positive'] ? 'บวก' : 'ลบ' }}</td>
                    <td class="qa-num">{{ $item['mean'] ?? '–' }}</td>
                    <td class="qa-num">
                        @if ($item['contribution'] === null) –
                        @else <x-filament::badge :color="$item['contribution'] >= 3 ? 'success' : ($item['contribution'] >= 2 ? 'warning' : 'danger')" size="sm">{{ $item['contribution'] }}</x-filament::badge>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ความเป็นมิตรต่อผู้ใช้โดยรวม (ข้อ 11)</x-slot>
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th>คำตอบ</th><th class="qa-num">จำนวน</th><th class="qa-num">SUS เฉลี่ยของคำนี้ในงานวิจัย</th></tr></thead>
            <tbody>
            @foreach (QaSus::ADJECTIVES as $n => $adj)
                <tr><td>{{ $adj['th'] }} <span class="qa-muted" lang="en">({{ $adj['en'] }})</span></td><td class="qa-num">{{ $responses->where('adjective', $n)->count() }}</td><td class="qa-num">{{ $adj['mean'] }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">คำตอบรายคน</x-slot>
        @if ($responses->isEmpty())
            <p class="qa-muted qa-small">ยังไม่มีคำตอบ เพิ่มผู้ประเมินในหน้า "ผู้ทดสอบ" แล้วให้เขาเข้าที่ {{ url('/sus') }}</p>
        @else
            <div class="qa-table-wrap"><table class="qa-table">
                <thead><tr><th>ผู้ประเมิน</th><th>ฝั่ง</th><th>อุปกรณ์</th><th>ประสบการณ์</th>@foreach (range(1, 10) as $n)<th class="qa-num">{{ $n }}</th>@endforeach<th class="qa-num">SUS</th><th>เกรด</th><th>ข้อ 11</th><th>ความเห็น</th><th>เมื่อ</th></tr></thead>
                <tbody>
                @foreach ($responses->sortByDesc('score') as $r)
                    <tr>
                        <td>{{ $evaluators[$r->qa_tester_id] ?? 'ผู้ประเมิน' }}</td>
                        <td class="qa-small">{{ QaSus::ROLES[$r->role] ?? $r->role }}</td>
                        <td class="qa-small">{{ QaSus::PLATFORMS[$r->platform] ?? '–' }}</td>
                        <td class="qa-small">{{ QaSus::EXPERIENCE[$r->experience] ?? '–' }}</td>
                        @foreach (range(0, 9) as $i)<td class="qa-num">{{ $r->answers[$i] ?? '' }}</td>@endforeach
                        <td class="qa-num" style="font-weight:600;">{{ $r->score }}</td>
                        <td>{{ QaSus::grade($r->score) }}</td>
                        <td class="qa-small">{{ $r->adjective ? QaSus::ADJECTIVES[$r->adjective]['th'] : '–' }}</td>
                        <td class="qa-small" style="white-space:pre-line;">{{ $r->comment }}</td>
                        <td class="qa-small qa-muted" style="white-space:nowrap;">{{ $r->created_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ผู้ประเมิน ({{ count($evaluators) }} คน)</x-slot>
        @if ($evaluators === [])
            <p class="qa-muted qa-small">ยังไม่มีผู้ประเมิน เพิ่มได้ในหน้า "ผู้ทดสอบ" โดยเลือกบทบาท "ผู้ประเมิน SUS"</p>
        @else
            <div class="qa-row">
                @foreach ($evaluators as $id => $name)
                    @php $roles = $sentBy->get($id)?->pluck('role')->all() ?? []; @endphp
                    <x-filament::badge :color="$roles ? 'success' : 'gray'">{{ $name }}: {{ $roles ? collect($roles)->map(fn ($r) => QaSus::ROLES[$r] ?? $r)->implode(', ') : 'ยังไม่ประเมิน' }}</x-filament::badge>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
