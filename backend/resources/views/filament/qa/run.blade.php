{{-- "กรอกผล": the signed-in tester's own results (DESIGN §39.6). --}}
@use('App\Domain\Qa\QaCatalog')
@use('App\Domain\Qa\QaText')
@use('App\Filament\Qa\Pages\MyResults')
@use('App\Filament\Qa\Pages\TestCaseDetail')
<x-filament-panels::page>
    @include('filament.qa._styles')
    @php
        $cases = $this->visibleCases();
        $team = $this->teamStatuses();
    @endphp

    <p class="qa-small qa-muted">เลือกส่วนที่จะทดสอบ ทำตามขั้นตอนของแต่ละการ์ด แล้วเลือกผลและกด "บันทึก" คุณแก้ได้เฉพาะผลของตัวเอง ใต้หัวการ์ดแสดงผลที่ผู้ทดสอบคนอื่นบันทึกไว้ ถ้าเป็นงานที่ไม่ได้รับมอบหมายให้เลือก "ข้าม"</p>

    <div class="qa-row">
        <div class="qa-field" style="min-width:260px;flex:1;">
            <label for="run-section">ส่วนที่ทดสอบ</label>
            <select class="qa-input" id="run-section" wire:model.live="section">
                @foreach ($this->sections() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <label class="qa-row qa-small" style="cursor:pointer;">
            <input type="checkbox" wire:model.live="open" id="run-open">
            <span>เฉพาะที่ฉันยังไม่ได้บันทึก</span>
        </label>
    </div>

    @if ($cases === [])
        <x-filament::section>
            <p class="qa-muted">ส่วนนี้คุณบันทึกครบแล้ว</p>
        </x-filament::section>
    @endif

    @foreach ($cases as $c)
        @php
            $key = MyResults::key($c['code']);
            $usability = QaCatalog::isUsability($c['kind']);
        @endphp
        <div class="qa-card" wire:key="run-{{ $key }}" id="case-{{ $c['code'] }}">
            <div class="qa-row">
                <a class="qa-link qa-code" href="{{ TestCaseDetail::getUrl(['code' => $c['code']]) }}">{{ $c['code'] }}</a>
                <h3>{{ $c['title'] }}</h3>
                <x-filament::badge color="gray" size="sm">{{ $c['area'] }}</x-filament::badge>
                @if (! empty($c['platforms']))<span class="qa-small qa-muted">ใช้ได้บน: {{ $c['platforms'] }}</span>@endif
            </div>
            <div class="qa-row qa-small">
                <span class="qa-muted">ผู้ทดสอบคนอื่น:</span>
                @forelse ($team[$c['code']] ?? [] as $t)
                    <x-filament::badge :color="$t['tone']" size="sm">{{ $t['name'] }}: {{ $t['label'] }}</x-filament::badge>
                @empty
                    <span class="qa-muted">ยังไม่มีใครบันทึก</span>
                @endforelse
            </div>

            @if ($c['kind'] === QaCatalog::MANUAL)
                <ol class="qa-ol qa-small">@foreach ($c['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                <p class="qa-small"><span style="font-weight:600;">ผลที่คาดหวัง:</span> {{ $c['expect'] }}</p>
            @else
                @if (! empty($c['goal']))<p class="qa-small"><span style="font-weight:600;">เป้าหมาย:</span> {{ $c['goal'] }}</p>@endif
                @if (! empty($c['pre']))<p class="qa-small"><span style="font-weight:600;">เตรียมก่อนเริ่ม:</span> {{ QaText::render($c['pre']) }}</p>@endif
                <ol class="qa-ol qa-small">@foreach ($c['steps'] as $step)<li>{{ QaText::render($step) }}</li>@endforeach</ol>
                <p class="qa-small"><span style="font-weight:600;">เกณฑ์สำเร็จ:</span> {{ QaText::render($c['done']) }}</p>
                @if (! empty($c['ucs']))
                    <p class="qa-small qa-muted">use case ที่ผ่าน: {{ QaText::render(implode(' → ', $c['ucs'])) }}</p>
                @endif
            @endif

            <div class="qa-seg" role="radiogroup" aria-label="ผลของ {{ $c['code'] }}">
                @foreach (QaCatalog::statusesFor($c['kind']) as $value => [$label, $tone])
                    <label>
                        <input type="radio" value="{{ $value }}" wire:model="form.{{ $key }}.status" id="st-{{ $key }}-{{ $value }}">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>

            @if (! empty($c['alts']))
                <details>
                    <summary class="qa-small" style="cursor:pointer;font-weight:600;">กรณีอื่น {{ count($c['alts']) }} กรณี: ลองแล้วเป็นตามที่เขียนไหม</summary>
                    <div class="qa-grid" style="gap:8px;margin-top:8px;">
                        @foreach ($c['alts'] as $i => $alt)
                            <div class="qa-row qa-small" style="align-items:flex-start;flex-wrap:nowrap;">
                                <select class="qa-input" style="width:auto;min-width:150px;" wire:model="form.{{ $key }}.checks.{{ $i }}" id="chk-{{ $key }}-{{ $i }}" aria-label="กรณี {{ $alt['label'] }}">
                                    <option value="">ยังไม่ได้ลอง</option>
                                    @foreach (QaCatalog::CHECK_STATUSES as $v => [$l, $t])<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                </select>
                                <span><span class="qa-code">{{ $alt['label'] }}</span> {{ QaText::render($alt['text']) }}</span>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            <div class="qa-fields">
                @if ($usability)
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
                    <textarea class="qa-input" rows="2" id="note-{{ $key }}" wire:model="form.{{ $key }}.note" placeholder="{{ $usability ? 'ผู้ทดสอบพูดอะไร ติดตรงไหน กรณีอื่นที่ไม่เป็นตามที่เขียน' : 'สิ่งที่เห็น รุ่นแอปหรือเบราว์เซอร์ ลิงก์ issue' }}"></textarea>
                </div>
            </div>
            @error("form.{$key}.*")<p class="qa-small" style="color:rgb(220 38 38);">{{ $message }}</p>@enderror
            <div class="qa-row" style="justify-content:space-between;">
                <span class="qa-small qa-muted">{{ isset($savedAt[$key]) ? 'บันทึกล่าสุด '.$savedAt[$key] : 'ยังไม่บันทึก' }}</span>
                <x-filament::button size="sm" wire:click="save('{{ $c['code'] }}')" wire:loading.attr="disabled" wire:target="save('{{ $c['code'] }}')">บันทึก</x-filament::button>
            </div>
        </div>
    @endforeach
</x-filament-panels::page>
