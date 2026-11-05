{{-- The participant's four tasks (DESIGN §39.10): each rated and saved on its own. --}}
@use('App\Domain\Qa\QaText')
@use('App\Http\Controllers\Usability\UsabilityController')
@extends('sus.layout')
@section('title', 'ทดสอบการใช้งาน')
@section('footer', 'การทดสอบการใช้งาน Krucheck · ใช้เพื่อปรับปรุงระบบเท่านั้น')
@section('content')
    @php
        $saved = $results->filter(fn ($r) => $r->status !== null)->count();
        $total = count($tasks);
        $justSaved = session('usability_saved');
        // The task the last post was about: its card shows the error or the saved note.
        $current = session('usability_task');
    @endphp

    <h1>สวัสดี {{ $me->name }}</h1>
    <p class="muted">ทำทีละงานในระบบสาธิตด้วยบัญชีที่ผู้ดำเนินการทดสอบให้ เสร็จแต่ละงานแล้วกลับมาให้คะแนนที่หน้านี้ ไม่ต้องรีบ และไม่มีคำตอบที่ผิด เรากำลังทดสอบระบบ ไม่ได้ทดสอบคุณ</p>

    <div class="card">
        <p><strong>บันทึกแล้ว {{ $saved }} จาก {{ $total }} งาน</strong></p>
        @if ($saved === $total)
            <a class="btn full" href="{{ route('usability.sus') }}">ไปทำแบบประเมิน 10 ข้อ</a>
        @else
            <p class="muted small">ครบทุกงานแล้วจะไปทำแบบประเมิน 10 ข้อได้</p>
        @endif
    </div>

    @if (session('usability_tasks_first'))
        <div class="alert error" role="alert">บันทึกให้ครบ {{ $total }} งานก่อน แล้วจึงทำแบบประเมิน</div>
    @endif

    @foreach ($tasks as $i => $t)
        @php
            $r = $results->get($t['code']);
            $mine = $current === $t['code'];
            $status = $mine ? old('status', $r?->status) : $r?->status;
            $ease = (string) ($mine ? old('ease', $r?->ease) : $r?->ease);
            $note = $mine ? old('note', $r?->note) : $r?->note;
        @endphp
        <section class="card" id="{{ $t['code'] }}" style="display:grid;gap:12px;">
            <div>
                <p class="small muted" style="margin:0;">งานที่ {{ $i + 1 }} · ทำในฐานะ{{ $t['actor'] }}</p>
                <h2 style="margin:2px 0 6px;">{{ $t['title'] }}</h2>
                <p>{{ $t['goal'] }}</p>
                <ol class="small" style="margin:0;padding-left:1.3em;">
                    @foreach ($t['steps'] as $step)
                        <li>{!! QaText::render($step) !!}</li>
                    @endforeach
                </ol>
                <p class="small muted" style="margin-top:6px;">ถือว่าสำเร็จเมื่อ{{ $t['done'] }}</p>
            </div>

            @if ($r !== null && $r->status !== null && ! $mine)
                <div class="alert ok" style="margin:0;">บันทึกแล้ว: {{ UsabilityController::STATUSES[$r->status] ?? $r->status }} · ความง่าย {{ $r->ease ?? '—' }}/5 แก้ได้ด้านล่าง</div>
            @endif
            @if ($justSaved === $t['code'] && ! $errors->any())
                <div class="alert ok" role="status" style="margin:0;">บันทึกงานนี้แล้ว</div>
            @endif
            @if ($mine && $errors->any())
                <div class="alert error" role="alert" style="margin:0;">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('usability.task', $t['code']) }}" style="display:grid;gap:12px;">
                @csrf
                <fieldset>
                    <legend>คุณทำงานนี้ได้ไหม</legend>
                    <div class="stack">
                        @foreach (UsabilityController::STATUSES as $value => $label)
                            <label class="choice"><input type="radio" name="status" value="{{ $value }}" @checked($status === $value) required><span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                <fieldset>
                    <legend>งานนี้ง่ายแค่ไหน</legend>
                    <div class="likert">
                        @foreach ([1, 2, 3, 4, 5] as $n)
                            <label class="choice"><input type="radio" name="ease" value="{{ $n }}" @checked($ease === (string) $n) required aria-label="ความง่ายระดับ {{ $n }}"><span>{{ $n }}</span></label>
                        @endforeach
                    </div>
                    <div class="ends"><span>ยากมาก</span><span>ง่ายมาก</span></div>
                </fieldset>
                <label class="field">ติดตรงไหน หรืออยากบอกอะไร (ไม่บังคับ)
                    <textarea name="note" maxlength="2000">{{ $note }}</textarea>
                </label>
                <button class="btn full" type="submit">บันทึกงานที่ {{ $i + 1 }}</button>
            </form>
        </section>
    @endforeach
@endsection
