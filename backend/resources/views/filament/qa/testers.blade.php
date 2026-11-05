{{-- "ผู้ทดสอบ": the team, and adding a tester (DESIGN §39.6). --}}
@use('App\Domain\Qa\QaCatalog')
<x-filament-panels::page>
    @include('filament.qa._styles')
    @php
        $board = $this->board();
        $me = $this->me();
        $totals = $this->totals();
        $all = array_sum($totals);
    @endphp

    <x-filament::section>
        <x-slot name="heading">ทีมทดสอบ ({{ $board->testers()->count() }} คน)</x-slot>
        <x-slot name="description">ผู้ทดสอบทำ integration test และ usability test ใน Test Lab ไม่ทำแบบประเมิน SUS</x-slot>
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th>ชื่อ</th><th>อีเมล</th><th class="qa-num">บันทึกแล้ว</th><th>ความคืบหน้า</th>@if ($me->is_lead)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($board->testers() as $t)
                @php $done = $board->filled($t->id, [QaCatalog::MANUAL, ...QaCatalog::USABILITY_KINDS]); @endphp
                <tr wire:key="tester-{{ $t->id }}">
                    <td>{{ $t->name }} @if ($t->is_lead)<x-filament::badge color="primary" size="sm">หัวหน้า</x-filament::badge>@endif @if ($t->id === $me->id)<span class="qa-muted qa-small">(คุณ)</span>@endif</td>
                    <td class="qa-small">{{ $t->email }}</td>
                    <td class="qa-num">{{ $done }}/{{ $all }}</td>
                    <td><div class="qa-bar" style="max-width:220px;"><i class="qa-ok" style="width: {{ $all ? round($done / $all * 100, 1) : 0 }}%"></i></div></td>
                    @if ($me->is_lead)
                        <td style="white-space:nowrap;">
                            @if ($t->id !== $me->id)
                                <x-filament::button size="xs" color="gray" wire:click="toggleLead({{ $t->id }})">{{ $t->is_lead ? 'เลิกเป็นหัวหน้า' : 'ตั้งเป็นหัวหน้า' }}</x-filament::button>
                                <x-filament::button size="xs" color="danger" wire:click="removeTester({{ $t->id }})" wire:confirm="ลบ {{ $t->name }} และผลการทดสอบทั้งหมดของเขา?">ลบ</x-filament::button>
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ผู้เข้าร่วมทดสอบ ({{ $this->participants()->count() }} คน)</x-slot>
        <x-slot name="description">คนนอกทีมลงทะเบียนเองที่ {{ url('/usability') }} ด้วยชื่อ อาชีพ และรหัสกลุ่ม แล้วให้คะแนนงานชุดย่อ {{ count(QaCatalog::ofKind(QaCatalog::QUICK)) }} งานกับทำ SUS เอง ผลอยู่ในรายงาน Usability</x-slot>
        <div class="qa-row" style="margin-bottom:12px;">
            @if ($this->signupOpen())
                <x-filament::badge color="success">เปิดรับอยู่</x-filament::badge>
            @else
                <x-filament::badge color="gray">ปิดรับ</x-filament::badge>
            @endif
            @if ($me->is_lead)
                <x-filament::button size="sm" wire:click="renewSignupCode" wire:confirm="สร้างรหัสกลุ่มใหม่? รหัสเดิมจะใช้ไม่ได้">{{ $this->signupOpen() ? 'สร้างรหัสใหม่' : 'เปิดรับและสร้างรหัส' }}</x-filament::button>
                @if ($this->signupOpen())
                    <x-filament::button size="sm" color="gray" wire:click="closeSignup">ปิดรับ</x-filament::button>
                @endif
            @else
                <span class="qa-small qa-muted">หัวหน้าทดสอบเป็นผู้เปิดหรือปิดรับ</span>
            @endif
        </div>
        @if ($newCode !== null)
            <p class="qa-small">รหัสกลุ่ม <span class="qa-code" style="font-size:1.4rem;letter-spacing:.15em;">{{ $newCode }}</span> แสดงครั้งเดียว จดหรือบอกผู้เข้าร่วมตอนนี้ ระบบเก็บไว้แบบอ่านกลับไม่ได้</p>
        @endif
        @if ($this->participants()->isEmpty())
            <p class="qa-muted qa-small">ยังไม่มีผู้เข้าร่วม</p>
        @else
            <div class="qa-table-wrap"><table class="qa-table">
                <thead><tr><th>ชื่อ</th><th>อาชีพ</th><th class="qa-num">งานที่บันทึก</th><th>SUS</th><th>ลงทะเบียน</th>@if ($me->is_lead)<th></th>@endif</tr></thead>
                <tbody>
                @foreach ($this->participants() as $p)
                    <tr wire:key="participant-{{ $p->id }}">
                        <td>{{ $p->name }}</td>
                        <td class="qa-small">{{ $p->occupation }}</td>
                        <td class="qa-num">{{ $p->results_count }}/{{ count(QaCatalog::ofKind(QaCatalog::QUICK)) }}</td>
                        <td class="qa-small">{{ $p->sus_responses_count > 0 ? 'ส่งแล้ว' : 'ยังไม่ส่ง' }}</td>
                        <td class="qa-small qa-muted">{{ $p->created_at?->timezone('Asia/Bangkok')->format('d/m H:i') }}</td>
                        @if ($me->is_lead)
                            <td><x-filament::button size="xs" color="danger" wire:click="removeTester({{ $p->id }})" wire:confirm="ลบ {{ $p->name }} และคำตอบทั้งหมดของเขา?">ลบ</x-filament::button></td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ผู้ประเมิน SUS ({{ $this->evaluators()->count() }} คน)</x-slot>
        <x-slot name="description">ผู้ประเมินตอบแบบประเมิน SUS ที่ {{ url('/sus') }} อย่างเดียว ไม่เห็น Test Lab ควรเป็นผู้ใช้ที่ไม่ได้อยู่ในทีมพัฒนาหรือทีมทดสอบ</x-slot>
        @if ($this->evaluators()->isEmpty())
            <p class="qa-muted qa-small">ยังไม่มีผู้ประเมิน</p>
        @else
            <div class="qa-table-wrap"><table class="qa-table">
                <thead><tr><th>ชื่อ</th><th>อีเมล</th><th class="qa-num">ประเมินแล้ว</th>@if ($me->is_lead)<th></th>@endif</tr></thead>
                <tbody>
                @foreach ($this->evaluators() as $e)
                    <tr wire:key="evaluator-{{ $e->id }}">
                        <td>{{ $e->name }}</td>
                        <td class="qa-small">{{ $e->email }}</td>
                        <td class="qa-num">{{ $e->sus_responses_count }} ฝั่ง</td>
                        @if ($me->is_lead)
                            <td><x-filament::button size="xs" color="danger" wire:click="removeTester({{ $e->id }})" wire:confirm="ลบ {{ $e->name }} และคำตอบ SUS ของเขา?">ลบ</x-filament::button></td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">เพิ่มผู้ทดสอบหรือผู้ประเมิน</x-slot>
        <x-slot name="description">บัญชีเหล่านี้ไม่เห็นข้อมูลนักเรียน ผู้ทดสอบเข้าที่ {{ url('/qa') }} ผู้ประเมิน SUS เข้าที่ {{ url('/sus') }} ส่งอีเมลและรหัสผ่านให้เขาเอง</x-slot>
        <form wire:submit="addTester" class="qa-grid">
            <div class="qa-seg" role="radiogroup" aria-label="บทบาท">
                @foreach (\App\Models\QaTester::ROLES as $value => $label)
                    <label><input type="radio" value="{{ $value }}" wire:model.live="role" id="new-role-{{ $value }}"><span>{{ $label }}</span></label>
                @endforeach
            </div>
            <div class="qa-fields">
                <div class="qa-field">
                    <label for="new-name">ชื่อ</label>
                    <input class="qa-input" id="new-name" type="text" wire:model="name" autocomplete="off">
                    @error('name')<span class="qa-small" style="color:rgb(220 38 38);">{{ $message }}</span>@enderror
                </div>
                <div class="qa-field">
                    <label for="new-email">อีเมล</label>
                    <input class="qa-input" id="new-email" type="email" wire:model="email" autocomplete="off">
                    @error('email')<span class="qa-small" style="color:rgb(220 38 38);">{{ $message }}</span>@enderror
                </div>
                <div class="qa-field">
                    <label for="new-password">รหัสผ่าน (อย่างน้อย 8 ตัว)</label>
                    <input class="qa-input" id="new-password" type="text" wire:model="password" autocomplete="new-password">
                    @error('password')<span class="qa-small" style="color:rgb(220 38 38);">{{ $message }}</span>@enderror
                </div>
            </div>
            @if ($me->is_lead && $role === \App\Models\QaTester::ROLE_TESTER)
                <label class="qa-row qa-small" style="cursor:pointer;"><input type="checkbox" wire:model="isLead" id="new-lead"> <span>เป็นหัวหน้าทดสอบ</span></label>
            @endif
            <div><x-filament::button type="submit">{{ $role === \App\Models\QaTester::ROLE_EVALUATOR ? 'เพิ่มผู้ประเมิน SUS' : 'เพิ่มผู้ทดสอบ' }}</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
