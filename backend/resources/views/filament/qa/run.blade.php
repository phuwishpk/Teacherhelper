{{-- "กรอกผล": the signed-in tester's own results (DESIGN §39). --}}
@use('App\Domain\Qa\QaCatalog')
@use('App\Filament\Qa\Pages\MyResults')
@use('App\Filament\Qa\Pages\TestCaseDetail')
<x-filament-panels::page>
    @include('filament.qa._styles')
    
    <p class="qa-small qa-muted">เลือกผลแล้วกด "บันทึก" ของแต่ละรายการ คุณเห็นและแก้ได้เฉพาะผลของตัวเอง ผลของทุกคนรวมอยู่ในหน้าภาพรวม</p>

    @foreach ([QaCatalog::MANUAL => 'Integration test ทำมือ', QaCatalog::USABILITY => 'Usability task'] as $kind => $heading)
        <div class="qa-row" style="justify-content:space-between;">
            <h2 style="font-size:1.1rem;font-weight:600;">{{ $heading }}</h2>
            <span class="qa-small qa-muted">บันทึกแล้ว {{ $this->filled($kind) }} จาก {{ count($this->cases($kind)) }}</span>
        </div>
        @foreach ($this->cases($kind) as $c)
            @php $key = MyResults::key($c['code']); @endphp
            <div class="qa-card" wire:key="run-{{ $key }}" id="case-{{ $c['code'] }}">
                <div class="qa-row">
                    <a class="qa-link qa-code" href="{{ TestCaseDetail::getUrl(['code' => $c['code']]) }}">{{ $c['code'] }}</a>
                    <h3>{{ $c['title'] }}</h3>
                    <x-filament::badge color="gray" size="sm">{{ $c['area'] }}</x-filament::badge>
                </div>
                @if ($kind === QaCatalog::MANUAL)
                    <ol class="qa-ol qa-small">@foreach ($c['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                    <p class="qa-small"><span style="font-weight:600;">ผลที่คาดหวัง:</span> {{ $c['expect'] }}</p>
                @else
                    <p class="qa-small"><span style="font-weight:600;">เกณฑ์สำเร็จ:</span> {{ $c['done'] }} <span class="qa-muted">· สังเกตเพิ่ม: {{ $c['measure'] }}</span></p>
                @endif
                <div class="qa-seg" role="radiogroup" aria-label="ผลของ {{ $c['code'] }}">
                    @foreach (QaCatalog::statusesFor($kind) as $value => [$label, $tone])
                        <label>
                            <input type="radio" value="{{ $value }}" wire:model="form.{{ $key }}.status" id="st-{{ $key }}-{{ $value }}">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="qa-fields">
                    @if ($kind === QaCatalog::USABILITY)
                        <div class="qa-field">
                            <label for="time-{{ $key }}">เวลา (วินาที)</label>
                            <input class="qa-input" type="number" min="0" step="1" inputmode="numeric" id="time-{{ $key }}" wire:model="form.{{ $key }}.time_sec">
                        </div>
                        <div class="qa-field">
                            <label for="err-{{ $key }}">ทำผิดกี่ครั้ง</label>
                            <input class="qa-input" type="number" min="0" step="1" inputmode="numeric" id="err-{{ $key }}" wire:model="form.{{ $key }}.error_count">
                        </div>
                    @endif
                    <div class="qa-field" style="grid-column: 1 / -1;">
                        <label for="note-{{ $key }}">บันทึก</label>
                        <textarea class="qa-input" rows="2" id="note-{{ $key }}" wire:model="form.{{ $key }}.note" placeholder="{{ $kind === QaCatalog::USABILITY ? 'ผู้ทดสอบพูดอะไร ติดตรงไหน' : 'สิ่งที่เห็น รุ่นแอปหรือเบราว์เซอร์ ลิงก์ issue' }}"></textarea>
                    </div>
                </div>
                @error("form.{$key}.*")<p class="qa-small" style="color:rgb(220 38 38);">{{ $message }}</p>@enderror
                <div class="qa-row" style="justify-content:space-between;">
                    <span class="qa-small qa-muted">{{ isset($savedAt[$key]) ? 'บันทึกล่าสุด '.$savedAt[$key] : 'ยังไม่บันทึก' }}</span>
                    <x-filament::button size="sm" wire:click="save('{{ $c['code'] }}')" wire:loading.attr="disabled" wire:target="save('{{ $c['code'] }}')">บันทึก</x-filament::button>
                </div>
            </div>
        @endforeach
    @endforeach
</x-filament-panels::page>
