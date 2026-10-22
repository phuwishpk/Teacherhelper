{{-- "แบบประเมิน SUS" (DESIGN §39). --}}
@use('App\Domain\Qa\QaCatalog')
<x-filament-panels::page>
    @include('filament.qa._styles')
    @php $score = $this->score(); @endphp

    <x-filament::section>
        <p class="qa-small qa-muted">10 ข้อ ตอบหลังทำ usability task เสร็จ 1 = ไม่เห็นด้วยอย่างยิ่ง, 5 = เห็นด้วยอย่างยิ่ง คะแนน: ข้อคี่ใช้ (คำตอบ − 1) ข้อคู่ใช้ (5 − คำตอบ) รวมแล้วคูณ 2.5 ได้ 0–100 ค่าเฉลี่ยอ้างอิงคือ {{ QaCatalog::SUS_BENCHMARK }} (Brooke, 1996) คำแปลเป็นของทีม</p>
        <div class="qa-field" style="max-width:260px;margin-top:12px;">
            <label for="sus-role">บทบาทที่ทดสอบ</label>
            <select class="qa-input" id="sus-role" wire:model="role">
                <option value="teacher">ครู</option>
                <option value="student">นักเรียน</option>
            </select>
        </div>
    </x-filament::section>

    @foreach (QaCatalog::SUS as $i => $question)
        <div class="qa-card" wire:key="sus-{{ $i }}">
            <p><span style="font-weight:600;">{{ $i + 1 }}.</span> {{ $question }}</p>
            <div class="qa-likert" role="radiogroup" aria-label="ข้อ {{ $i + 1 }}">
                @foreach ([1, 2, 3, 4, 5] as $n)
                    <label><input type="radio" value="{{ $n }}" wire:model.live="answers.{{ $i }}" id="sus-{{ $i }}-{{ $n }}"><span>{{ $n }}</span></label>
                @endforeach
            </div>
            <div class="qa-row qa-small qa-muted" style="justify-content:space-between;max-width:400px;"><span>ไม่เห็นด้วยอย่างยิ่ง</span><span>เห็นด้วยอย่างยิ่ง</span></div>
        </div>
    @endforeach

    <x-filament::section>
        <div class="qa-field">
            <label for="sus-comment">ความเห็นเพิ่มเติม (ไม่บังคับ)</label>
            <textarea class="qa-input" rows="3" id="sus-comment" wire:model="comment" placeholder="ชอบอะไร ติดตรงไหน อยากให้ปรับอะไร"></textarea>
        </div>
        @error('answers.*')<p class="qa-small" style="color:rgb(220 38 38);margin-top:6px;">{{ $message }}</p>@enderror
        <div class="qa-row" style="justify-content:space-between;margin-top:12px;">
            <div><span class="qa-small qa-muted">คะแนน SUS</span><div style="font-size:2rem;font-weight:700;" id="sus-score">{{ $score ?? '–' }}</div></div>
            <div class="qa-row">
                <span class="qa-small qa-muted">{{ $savedAt ? 'บันทึกล่าสุด '.$savedAt : 'ยังไม่บันทึก' }}</span>
                <x-filament::button wire:click="save" :disabled="$score === null">บันทึกแบบประเมิน</x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
