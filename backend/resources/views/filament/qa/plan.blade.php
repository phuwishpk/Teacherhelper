{{-- Test plan (DESIGN §39). --}}
@use('App\Domain\Qa\QaCatalog')
@use('App\Filament\Qa\Pages\TestCaseDetail')
<x-filament-panels::page>
    @include('filament.qa._styles')
    @php
        $counts = $this->counts();
        $kindLabel = [QaCatalog::AUTO => 'อัตโนมัติ', QaCatalog::MANUAL => 'ทำมือ', QaCatalog::USABILITY => 'usability'];
        $kindTone = [QaCatalog::AUTO => 'info', QaCatalog::MANUAL => 'gray', QaCatalog::USABILITY => 'warning'];
    @endphp

    <x-filament::section>
        <x-slot name="heading">เลือกอย่างไรว่าอันไหนอัตโนมัติ อันไหนทำมือ</x-slot>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;" class="qa-small">
            <div>
                <p style="font-weight:600;margin-bottom:6px;">อัตโนมัติ: รันใน GitHub Actions ทุก push และ PR</p>
                <ul class="qa-ul">
                    <li>ผลคาดเดาได้แน่นอน รันซ้ำได้ผลเดิม</li>
                    <li>บริการภายนอกจำลองได้: Gemini, Google Classroom, Drive, FCM และ Firestore ใช้ HTTP จำลอง</li>
                    <li>ไม่ต้องใช้อุปกรณ์จริง ไม่ต้องใช้บัญชีจริง ไม่มีค่าใช้จ่าย</li>
                    <li>ตรวจทั้งเส้นทาง HTTP → controller → database → queue job → ไฟล์ บน SQLite และ MariaDB 11</li>
                </ul>
            </div>
            <div>
                <p style="font-weight:600;margin-bottom:6px;">ทำมือ: ผู้ทดสอบทำแล้วกรอกในหน้า "กรอกผล"</p>
                <ul class="qa-ul">
                    <li>ใช้บริการจริงที่มีค่าใช้จ่ายหรือผลไม่คงที่ เช่น Gemini อ่านลายมือจริง</li>
                    <li>ต้องผ่านหน้า OAuth จริงของ Google หรือคอร์ส Classroom จริง</li>
                    <li>ต้องใช้อุปกรณ์จริง: กล้องมือถือ เครื่องพิมพ์ การแจ้งเตือนของ Android</li>
                    <li>ขึ้นกับสภาพแวดล้อมจริง: hosting, Scheduled Task, เบราว์เซอร์แต่ละตัว</li>
                    <li>วัดความเข้าใจและความรู้สึกของคน: usability test และ SUS ทำมือเสมอ</li>
                </ul>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section collapsible>
        <x-slot name="heading">ขั้นตอนการทดสอบ</x-slot>
        <ol class="qa-ol qa-small">
            <li>admin ของ Krucheck สร้างบัญชีผู้ทดสอบ 3 คนกับหัวหน้าทดสอบ 1 คนที่ /admin เมนู "ผู้ทดสอบระบบ" บัญชีเหล่านี้เข้าได้เฉพาะ /qa</li>
            <li>เตรียมระบบ: บัญชีครูตัวอย่างที่อนุมัติแล้ว ห้อง "ป.5/1 (ตัวอย่าง)" นักเรียน demo01–demo10 (task <span class="qa-code">eduvision:reset-demo</span>) และใบงานพิมพ์แล้ว 3 แผ่นที่เขียนคำตอบไว้ ใช้ข้อมูลจำลองทั้งหมด</li>
            <li>ผู้ทดสอบทำ integration test ทำมือที่ได้รับมอบหมาย ส่วนที่ไม่ได้รับมอบหมายให้เลือก "ไม่ได้รับมอบหมาย"</li>
            <li>usability task: จับเวลาตั้งแต่อ่านโจทย์จบจนถึงเกณฑ์สำเร็จ ผู้สังเกตห้ามช่วยจนกว่าผู้ทดสอบจะขอ หรือติดเกิน 2 นาที</li>
            <li>จบแล้วทำแบบประเมิน SUS ทันที เลือกบทบาทที่ทดสอบ</li>
            <li>หัวหน้าทดสอบดูหน้าภาพรวม แล้วเปิด issue ใน GitHub สำหรับทุกรายการที่ไม่ผ่าน</li>
        </ol>
    </x-filament::section>

    <div class="qa-row">
        <x-filament::button size="sm" :color="$kind === 'all' ? 'primary' : 'gray'" wire:click="$set('kind', 'all')">ทั้งหมด ({{ array_sum($counts) }})</x-filament::button>
        @foreach ($counts as $k => $n)
            <x-filament::button size="sm" :color="$kind === $k ? 'primary' : 'gray'" wire:click="$set('kind', '{{ $k }}')">{{ $kindLabel[$k] }} ({{ $n }})</x-filament::button>
        @endforeach
    </div>

    <x-filament::section>
        <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><th>รหัส</th><th>ชนิด</th><th>ส่วน</th><th>รายการ</th><th>รายละเอียด</th></tr></thead>
            <tbody>
            @foreach ($this->cases() as $c)
                <tr wire:key="case-{{ $c['code'] }}">
                    <td class="qa-code"><a class="qa-link" href="{{ TestCaseDetail::getUrl(['code' => $c['code']]) }}">{{ $c['code'] }}</a></td>
                    <td><x-filament::badge :color="$kindTone[$c['kind']]" size="sm">{{ $kindLabel[$c['kind']] }}</x-filament::badge></td>
                    <td class="qa-small">{{ $c['area'] }}</td>
                    <td>{{ $c['title'] }}</td>
                    <td class="qa-small qa-muted">{{ $c['detail'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
