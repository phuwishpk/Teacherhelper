{{-- The SUS questionnaire (DESIGN §39.7). --}}
@use('App\Domain\Qa\QaSus')
<x-filament-panels::page>
    @include('filament.qa._styles')
    @php $done = isset($sent[$role]); @endphp

    <x-filament::section>
        <x-slot name="heading">คำชี้แจง</x-slot>
        <ol class="qa-ol qa-small">
            <li>ตอบแบบประเมินนี้<strong>ทันทีหลังใช้งาน Krucheck เสร็จ</strong> ก่อนพูดคุยหรือแสดงความเห็นกับผู้ดำเนินการทดสอบ</li>
            <li>ตอบ<strong>ทุกข้อ</strong> ใช้ความรู้สึกแรก ไม่ต้องคิดนานในแต่ละข้อ</li>
            <li>ข้อที่ไม่แน่ใจหรือตอบไม่ได้ ให้เลือก <strong>3</strong> (กลาง)</li>
            <li>ข้อความสลับกันระหว่างข้อที่เป็นบวกและข้อที่เป็นลบ อ่านทุกข้อให้จบก่อนเลือก</li>
            <li>ประเมินแยกตามฝั่งของแอปที่ใช้ (ครูหรือนักเรียน) ส่งแล้วแก้ไม่ได้</li>
        </ol>
        <p class="qa-small qa-muted" style="margin-top:8px;">แบบประเมิน System Usability Scale (SUS) ของ Brooke (1996) แสดงข้อความต้นฉบับภาษาอังกฤษคู่กับคำแปลของทีม และคำถามความเป็นมิตรโดยรวมของ Bangor, Kortum และ Miller (2009) ข้อมูลใช้เพื่อประเมินระบบเท่านั้น</p>
    </x-filament::section>

    @if ($justSent)
        <x-filament::section>
            <p style="font-weight:600;">ส่งแบบประเมินแล้ว ขอบคุณที่ช่วยประเมิน</p>
            @if (count($sent) < count(QaSus::ROLES))
                <p class="qa-small qa-muted">ถ้าใช้แอปอีกฝั่งด้วย เลือกฝั่งนั้นด้านล่างแล้วประเมินแยกอีกชุด</p>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section>
        <div class="qa-fields">
            <div class="qa-field">
                <label for="sus-role">ประเมินแอปฝั่งไหน</label>
                <select class="qa-input" id="sus-role" wire:model.live="role">
                    @foreach (QaSus::ROLES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}{{ isset($sent[$value]) ? ' (ส่งแล้ว)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            @unless ($done)
                <div class="qa-field">
                    <label for="sus-platform">ใช้บนอุปกรณ์ใด</label>
                    <select class="qa-input" id="sus-platform" wire:model="platform">
                        <option value="">เลือก</option>
                        @foreach (QaSus::PLATFORMS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('platform')<span class="qa-small" style="color:rgb(220 38 38);">{{ $message }}</span>@enderror
                </div>
                <div class="qa-field">
                    <label for="sus-experience">ประสบการณ์กับ Krucheck</label>
                    <select class="qa-input" id="sus-experience" wire:model="experience">
                        <option value="">เลือก</option>
                        @foreach (QaSus::EXPERIENCE as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('experience')<span class="qa-small" style="color:rgb(220 38 38);">{{ $message }}</span>@enderror
                </div>
            @endunless
        </div>
    </x-filament::section>

    @if ($done)
        <x-filament::section>
            <p>คุณส่งแบบประเมิน{{ QaSus::ROLES[$role] }}แล้วเมื่อ {{ $sent[$role] }}</p>
        </x-filament::section>
    @else
        @foreach (QaSus::ITEMS as $i => $item)
            <div class="qa-card" wire:key="sus-{{ $i }}">
                <p><span style="font-weight:600;">{{ $i + 1 }}.</span> {{ $item['th'] }}</p>
                <p class="qa-small qa-muted" lang="en" style="font-style:italic;">{{ $item['en'] }}</p>
                <div class="qa-likert" role="radiogroup" aria-label="ข้อ {{ $i + 1 }}">
                    @foreach ([1, 2, 3, 4, 5] as $n)
                        <label><input type="radio" value="{{ $n }}" wire:model="answers.{{ $i }}" id="sus-{{ $i }}-{{ $n }}"><span>{{ $n }}</span></label>
                    @endforeach
                </div>
                <div class="qa-row qa-small qa-muted" style="justify-content:space-between;max-width:400px;"><span>{{ QaSus::SCALE[1] }}</span><span>{{ QaSus::SCALE[5] }}</span></div>
                @error("answers.{$i}")<span class="qa-small" style="color:rgb(220 38 38);">{{ $message }}</span>@enderror
            </div>
        @endforeach

        <div class="qa-card">
            <p><span style="font-weight:600;">11.</span> โดยรวมแล้ว ฉันให้ระดับความเป็นมิตรต่อผู้ใช้ของระบบนี้ว่า</p>
            <p class="qa-small qa-muted" lang="en" style="font-style:italic;">Overall, I would rate the user-friendliness of this product as:</p>
            <div class="qa-seg" role="radiogroup" aria-label="ความเป็นมิตรต่อผู้ใช้โดยรวม">
                @foreach (QaSus::ADJECTIVES as $n => $adj)
                    <label><input type="radio" value="{{ $n }}" wire:model="adjective" id="adj-{{ $n }}"><span>{{ $adj['th'] }} <span class="qa-muted" lang="en">({{ $adj['en'] }})</span></span></label>
                @endforeach
            </div>
            @error('adjective')<span class="qa-small" style="color:rgb(220 38 38);">{{ $message }}</span>@enderror
        </div>

        <x-filament::section>
            <div class="qa-field">
                <label for="sus-comment">ความเห็นเพิ่มเติม (ไม่บังคับ)</label>
                <textarea class="qa-input" rows="3" id="sus-comment" wire:model="comment" placeholder="ชอบอะไร ติดตรงไหน"></textarea>
            </div>
            <div style="margin-top:12px;">
                <x-filament::button wire:click="submit" wire:loading.attr="disabled">ส่งแบบประเมิน</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
