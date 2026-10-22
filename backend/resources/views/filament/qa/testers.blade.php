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
        <x-slot name="heading">เพิ่มผู้ทดสอบ</x-slot>
        <x-slot name="description">บัญชีนี้เข้าได้เฉพาะ Test Lab ไม่เห็นข้อมูลนักเรียน ส่งอีเมลและรหัสผ่านให้เขาเอง แล้วให้เข้าที่ {{ url('/qa') }}</x-slot>
        <form wire:submit="addTester" class="qa-grid">
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
            @if ($me->is_lead)
                <label class="qa-row qa-small" style="cursor:pointer;"><input type="checkbox" wire:model="isLead" id="new-lead"> <span>เป็นหัวหน้าทดสอบ</span></label>
            @endif
            <div><x-filament::button type="submit">เพิ่มผู้ทดสอบ</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
