{{-- "คู่ที่น่าจะซ้ำ" (DESIGN §24.4): pairs of accounts that look like one child. --}}
<x-filament-panels::page>
    @php
        $schools = $this->getSchoolOptions();
        $pairs = $this->getPairs();
        $box = 'border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:12px 16px;';
    @endphp

    <p style="font-size:0.875rem;opacity:.8;">
        ระบบไม่รวมบัญชีเอง คู่ด้านล่างมีบัญชี Google Classroom อีเมล หรือชื่อเดียวกัน ตรวจว่าเป็นนักเรียนคนเดียวกันจริงก่อน แล้วกด "เก็บบัญชีนี้" ที่ฝั่งที่จะเก็บไว้ ระบบแสดงข้อมูลของทั้งสองบัญชีให้เทียบก่อนรวม
    </p>

    @if ($schools !== [])
        <label style="display:flex;gap:8px;align-items:center;font-size:0.875rem;">
            <span>โรงเรียน</span>
            <select wire:model.live="schoolId" style="border-radius:8px;padding:4px 32px 4px 8px;color:inherit;background:transparent;">
                @foreach ($schools as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
    @endif

    @forelse ($pairs as $pair)
        <div style="{{ $box }}" wire:key="pair-{{ $pair['a']['id'] }}-{{ $pair['b']['id'] }}">
            <p style="font-size:0.8rem;opacity:.7;margin-bottom:8px;">
                {{ collect($pair['reasons'])->map(fn ($r) => $this->reasonLabel($r))->implode(' · ') }}
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
                @foreach ([['a', 'b'], ['b', 'a']] as [$side, $other])
                    @php $s = $pair[$side]; @endphp
                    <div>
                        <p style="font-weight:600;">{{ $s['name'] }}</p>
                        <p style="font-size:0.8rem;opacity:.8;">เลขประจำตัว {{ $s['student_code'] ?? '—' }}</p>
                        <p style="font-size:0.8rem;opacity:.8;margin-bottom:8px;">
                            {{ collect($s['classrooms'])->map(fn ($c) => "{$c['name']} ({$c['academic_year']}) เลขที่ {$c['student_number']}".($c['closed'] ? ' ห้องเก่า' : ''))->implode(', ') ?: 'ไม่ได้อยู่ในห้องใด' }}
                        </p>
                        {{ ($this->mergeAction)(['keep' => $s['id'], 'merge' => $pair[$other]['id']]) }}
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p style="font-size:0.875rem;opacity:.7;">ไม่พบบัญชีที่อาจซ้ำ</p>
    @endforelse
</x-filament-panels::page>
