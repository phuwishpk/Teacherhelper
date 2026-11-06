{{-- "ทดสอบการเชื่อม Google ของนักเรียน" (DESIGN §32): the two automatic paths and a dry run. --}}
@use('App\Domain\Auth\Google\StudentRosterLink')
@use('App\Filament\Resources\GoogleEvents\GoogleEventResource')
<x-filament-panels::page>
    @include('filament.qa._styles')
    <style>
        .glf-flow { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
        .glf-steps { display: grid; gap: 6px; }
        .glf-step { border: 1px solid rgba(127,127,127,.3); border-radius: 10px; padding: 10px 12px; font-size: .875rem; display: grid; gap: 2px; }
        .glf-step b { font-weight: 600; }
        .glf-step .n { display: inline-block; min-width: 1.6em; font-weight: 700; opacity: .6; }
        .glf-arrow { text-align: center; opacity: .5; font-size: .8rem; line-height: 1; }
        .glf-ends { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 6px; }
        .glf-end { border-radius: 10px; padding: 8px 10px; font-size: .8rem; border: 1px dashed rgba(127,127,127,.45); }
        .glf-good { background: rgba(22,163,74,.10); border-color: rgba(22,163,74,.55); }
        .glf-stop { background: rgba(217,119,6,.10); border-color: rgba(217,119,6,.55); }
        .glf-hit { outline: 3px solid rgb(var(--primary-500)); outline-offset: 2px; border-style: solid; font-weight: 600; }
        .glf-trace { display: grid; gap: 6px; }
        .glf-trace li { display: grid; grid-template-columns: 1.6em 1fr; gap: 6px; font-size: .875rem; }
        .glf-mark-ok { color: rgb(22 163 74); font-weight: 700; }
        .glf-mark-no { color: rgb(220 38 38); font-weight: 700; }
        .glf-mark-skip { opacity: .5; }
    </style>
    @php
        $trace = $this->getTrace();
        $identity = $trace['identity'] ?? null;
        $roster = $trace['roster'] ?? null;
        // Where path A ends for the e-mail tried.
        $endA = null;
        if ($trace !== null) {
            $endA = $identity !== null
                ? ($identity['active'] ? 'signed_in' : 'not_active')
                : ($roster['result'] === StudentRosterLink::OK ? 'roster_link' : 'ticket');
        }
        $hit = fn (string $key) => $endA === $key ? ' glf-hit' : '';
    @endphp

    <p class="qa-small qa-muted">
        หน้านี้อธิบายว่าบัญชี Google ของนักเรียนเชื่อมกับบัญชี Krucheck เองได้ทางไหน และให้ลองกับอีเมลจริงในระบบ
        การทดสอบใช้โค้ดเดียวกับตอนเข้าสู่ระบบและซิงก์รายชื่อ แต่ไม่เชื่อมบัญชี ไม่สร้างบัญชี และไม่บันทึกเหตุการณ์ใด ๆ
    </p>

    <div class="glf-flow">
        <div class="qa-card">
            <h3>ทาง ก: นักเรียนกด "เข้าสู่ระบบด้วย Google" ครั้งแรก</h3>
            <div class="glf-steps">
                <div class="glf-step"><span><span class="n">1</span><b>Google ยืนยันตัวตน</b></span><span class="qa-small qa-muted">ได้ Google user ID (sub) และอีเมลที่ยืนยันแล้ว</span></div>
                <div class="glf-arrow">↓</div>
                <div class="glf-step"><span><span class="n">2</span><b>บัญชี Google นี้เชื่อมกับใครแล้วหรือยัง</b></span><span class="qa-small qa-muted">ตาราง user_google_identities ค้นด้วย sub</span></div>
                <div class="glf-ends">
                    <div class="glf-end glf-good{{ $hit('signed_in') }}">เชื่อมแล้ว → เข้าสู่ระบบทันที</div>
                    <div class="glf-end glf-stop{{ $hit('not_active') }}">เชื่อมแล้วแต่บัญชีปิดอยู่ → เข้าไม่ได้</div>
                </div>
                <div class="glf-arrow">↓ ยังไม่เชื่อม</div>
                <div class="glf-step">
                    <span><span class="n">3</span><b>ตรวจรายชื่อ Google Classroom ที่ซิงก์ไว้</b></span>
                    <span class="qa-small qa-muted">มีห้องที่มี Google user ID นี้ · ทุกแถวที่มี ID หรืออีเมลนี้เป็นนักเรียนคนเดียวกัน · แถวนั้นมีอีเมลเดียวกับที่เข้าสู่ระบบ · บัญชีนักเรียนใช้งานอยู่ · ยังไม่เชื่อม Google อื่น</span>
                </div>
                <div class="glf-ends">
                    <div class="glf-end glf-good{{ $hit('roster_link') }}">ผ่านทุกข้อ → เชื่อมให้เองและเข้าสู่ระบบ (classroom_roster)</div>
                    <div class="glf-end glf-stop{{ $hit('ticket') }}">ไม่ผ่าน → ได้ link ticket: ยืนยันด้วย PIN/QR หรือกรอกรหัสห้อง</div>
                </div>
            </div>
        </div>

        <div class="qa-card">
            <h3>ทาง ข: ครูนำเข้าหรือซิงก์รายชื่อจาก Google Classroom</h3>
            <div class="glf-steps">
                <div class="glf-step"><span><span class="n">1</span><b>อ่านรายชื่อคอร์สจาก Google</b></span><span class="qa-small qa-muted">แต่ละคนมี Google user ID และอีเมล (ถ้า Google ให้มา)</span></div>
                <div class="glf-arrow">↓</div>
                <div class="glf-step">
                    <span><span class="n">2</span><b>หานักเรียนเดิมในพื้นที่ของครู</b> (SchoolStudentMatcher)</span>
                    <span class="qa-small qa-muted">ก. บัญชี Google ที่นักเรียนใช้เข้าสู่ระบบ → ข. บัญชี Classroom เดียวกันในห้องอื่น → ค. อีเมลเดียวกันในห้องอื่น (ต้องเจอคนเดียว) · ชื่อเหมือนกันเป็นแค่ข้อเสนอตอนนำเข้า</span>
                </div>
                <div class="glf-ends">
                    <div class="glf-end glf-good">เจอ → ใช้บัญชีเดิม (PIN และ QR เดิม) และจับคู่กับ Classroom</div>
                    <div class="glf-end glf-stop">ไม่เจอ → สร้างบัญชีนักเรียนใหม่ (ห้องประจำชั้น) หรือแจ้ง "ไม่อยู่ในห้อง" (ครูประจำวิชา)</div>
                </div>
                <p class="qa-small qa-muted">ค้นเฉพาะพื้นที่ของครูที่นำเข้า นักเรียนที่มีบัญชีอยู่ในพื้นที่ของครูคนอื่นจะไม่ถูกจับคู่</p>
            </div>
        </div>
    </div>

    <x-filament::section>
        <x-slot name="heading">ลองกับอีเมลจริง</x-slot>
        <form wire:submit="check" class="qa-grid">
            <div class="qa-fields">
                <div class="qa-field">
                    <label for="glf-email">อีเมล Google ของนักเรียน</label>
                    <input id="glf-email" type="email" class="qa-input" wire:model="email" placeholder="student@school.ac.th" autocomplete="off">
                    @error('email')<span class="qa-small glf-mark-no">{{ $message }}</span>@enderror
                </div>
                <div class="qa-field">
                    <label for="glf-sub">Google user ID (ไม่บังคับ)</label>
                    <input id="glf-sub" type="text" class="qa-input" wire:model="sub" placeholder="ว่าง = หาจากข้อมูลในระบบ" autocomplete="off">
                    @error('sub')<span class="qa-small glf-mark-no">{{ $message }}</span>@enderror
                </div>
                <div class="qa-field">
                    <label for="glf-school">ลองนำเข้าในพื้นที่ของครู (ไม่บังคับ)</label>
                    <select id="glf-school" class="qa-input" wire:model="schoolId">
                        <option value="">เฉพาะพื้นที่ที่พบบัญชีนี้</option>
                        @foreach ($this->getSchoolOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="qa-row">
                <x-filament::button type="submit" icon="heroicon-o-play">ทดสอบ</x-filament::button>
                <a class="qa-link qa-small" href="{{ GoogleEventResource::getUrl() }}">ดูเหตุการณ์จริงใน "ปัญหาการเชื่อม Google"</a>
            </div>
        </form>
    </x-filament::section>

    @if ($trace !== null)
        <x-filament::section>
            <x-slot name="heading">ผลการทดสอบ: {{ $trace['email'] }}</x-slot>

            <p class="qa-small" style="margin-bottom:10px;">
                Google user ID ที่ใช้:
                @if ($trace['sub'] !== null)
                    <span class="qa-code">{{ $trace['sub'] }}</span>
                    <span class="qa-muted">({{ ['typed' => 'กรอกเอง', 'identity' => 'จากบัญชีที่เชื่อม Google ด้วยอีเมลนี้', 'roster' => 'จากรายชื่อ Classroom ที่มีอีเมลนี้'][$trace['sub_source']] }})</span>
                @elseif ($trace['sub_source'] === 'several')
                    <span class="glf-mark-no">อีเมลนี้อยู่กับ Google user ID หลายค่าในรายชื่อ กรอก ID ที่ต้องการทดสอบเอง</span>
                @else
                    <span class="qa-muted">ไม่พบในระบบ ตอนเข้าสู่ระบบจริง Google ส่ง ID มาเสมอ แต่ถ้ายังไม่มีห้องใดซิงก์ ID นี้ไว้ ผลจะเหมือนข้างล่าง</span>
                @endif
            </p>

            <h3 style="font-weight:600;margin-bottom:6px;">ทาง ก: ถ้านักเรียนกดเข้าสู่ระบบด้วย Google ตอนนี้</h3>
            <ol class="glf-trace">
                @if ($identity !== null)
                    <li><span class="glf-mark-ok">✓</span><span>ขั้น 2: เชื่อมกับ <b>{{ $identity['name'] ?? '#'.$identity['user_id'] }}</b> ({{ $identity['role'] }}, เชื่อมด้วย {{ $identity['linked_via'] }}) แล้ว
                        → {{ $identity['active'] ? 'เข้าสู่ระบบทันที' : 'บัญชีปิดอยู่ เข้าไม่ได้' }}</span></li>
                @else
                    <li><span class="glf-mark-skip">–</span><span>ขั้น 2: บัญชี Google นี้ยังไม่เชื่อมกับใคร</span></li>
                    <li>
                        <span class="{{ $roster['result'] === StudentRosterLink::OK ? 'glf-mark-ok' : 'glf-mark-no' }}">{{ $roster['result'] === StudentRosterLink::OK ? '✓' : '✗' }}</span>
                        <span>
                            ขั้น 3: {{ $roster['label'] }}
                            @if ($roster['student'] !== null) · นักเรียน: <b>{{ $roster['student']['name'] }}</b>@endif
                            @if ($roster['several'] !== []) · {{ implode(', ', $roster['several']) }}@endif
                            <br><span class="qa-muted">ผล: {{ $roster['result'] === StudentRosterLink::OK ? 'เชื่อมเองโดยไม่ต้องกรอก PIN' : 'นักเรียนต้องยืนยันด้วย PIN/QR หรือกรอกรหัสห้อง' }}</span>
                        </span>
                    </li>
                @endif
            </ol>

            <h3 style="font-weight:600;margin:16px 0 6px;">ทาง ข: ถ้าครูนำเข้าหรือซิงก์ Classroom ที่มีบัญชีนี้</h3>
            @forelse ($trace['workspaces'] as $w)
                <p class="qa-small" wire:key="ws-{{ $w['id'] }}">
                    <span class="{{ $w['student'] !== null ? 'glf-mark-ok' : 'glf-mark-no' }}">{{ $w['student'] !== null ? '✓' : '✗' }}</span>
                    พื้นที่ <b>{{ $w['name'] }}</b>:
                    @if ($w['student'] !== null)
                        ใช้บัญชีเดิม <b>{{ $w['student'] }}</b> ({{ $w['matched_by'] }})
                    @else
                        ไม่พบนักเรียนเดิม (หรือพบหลายคน) → ห้องประจำชั้นจะสร้างบัญชีใหม่
                    @endif
                </p>
            @empty
                <p class="qa-small qa-muted">บัญชีนี้ไม่อยู่ในพื้นที่ใด เลือกพื้นที่ของครูด้านบนเพื่อดูผลการนำเข้า</p>
            @endforelse

            <h3 style="font-weight:600;margin:16px 0 6px;">แถวในรายชื่อห้องที่มี ID หรืออีเมลนี้</h3>
            @if ($trace['rows'] === [])
                <p class="qa-small qa-muted">ไม่มี (ยังไม่มีห้องใดนำเข้าหรือซิงก์บัญชีนี้จาก Google Classroom)</p>
            @else
                <div class="qa-table-wrap">
                    <table class="qa-table">
                        <thead><tr><th>พื้นที่</th><th>ห้อง</th><th>นักเรียน</th><th>Google user ID</th><th>อีเมลในรายชื่อ</th><th>สถานะ</th></tr></thead>
                        <tbody>
                            @foreach ($trace['rows'] as $r)
                                <tr wire:key="row-{{ $r['classroom_id'] }}-{{ $r['student_id'] }}">
                                    <td>{{ $r['school'] ?? '#'.$r['school_id'] }}</td>
                                    <td>{{ $r['classroom'] }} ({{ $r['academic_year'] }}) เลขที่ {{ $r['student_number'] }}</td>
                                    <td>{{ $r['student'] }}</td>
                                    <td class="qa-code">{{ $r['google_user_id'] ?? '—' }}</td>
                                    <td class="qa-code">{{ $r['google_email'] ?? '—' }}</td>
                                    <td class="qa-small">{{ $r['left_course_at'] !== null ? 'ออกจากคอร์สแล้ว' : ($r['google_user_id'] !== null ? 'จับคู่อยู่' : 'ไม่ได้จับคู่') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
