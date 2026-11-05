{{-- Sign-up of an outside participant (DESIGN §39.10): name, occupation, the group's code. --}}
@extends('sus.layout')
@section('title', 'ลงทะเบียนทดสอบการใช้งาน')
@section('footer', 'การทดสอบการใช้งาน Krucheck · ใช้เพื่อปรับปรุงระบบเท่านั้น')
@section('content')
    <h1>ขอบคุณที่ช่วยทดสอบ Krucheck</h1>
    <p class="muted">คุณจะลองทำงานสั้นๆ 4 งานในระบบสาธิต แล้วให้คะแนนว่าแต่ละงานง่ายแค่ไหน ปิดท้ายด้วยแบบประเมิน 10 ข้อ รวมราว 20 นาที</p>

    @if (! $open)
        <div class="card"><p><strong>ตอนนี้ยังไม่เปิดรับผู้ทดสอบ</strong></p><p class="muted small">ติดต่อผู้ดำเนินการทดสอบเพื่อขอรหัสกลุ่ม</p></div>
    @endif

    @if ($errors->any())
        <div class="alert error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form class="card" method="POST" action="{{ route('usability.signup') }}" style="display:grid;gap:14px;">
        @csrf
        <label class="field">ชื่อ
            <input type="text" name="name" value="{{ old('name') }}" maxlength="100" autocomplete="name" required @class(['has-error' => $errors->has('name')])>
        </label>
        <label class="field">อาชีพ
            <input type="text" name="occupation" value="{{ old('occupation') }}" maxlength="100" placeholder="เช่น ครู นักเรียน พนักงานบริษัท" required @class(['has-error' => $errors->has('occupation')])>
        </label>
        <label class="field">รหัสกลุ่ม
            <input type="text" name="code" inputmode="numeric" maxlength="20" autocomplete="off" placeholder="6 หลักจากผู้ดำเนินการทดสอบ" required @class(['has-error' => $errors->has('code')])>
        </label>
        <button class="btn full" type="submit">เริ่มทดสอบ</button>
    </form>
    <p class="small muted">เราเก็บแค่ชื่อ อาชีพ และคำตอบของคุณ เพื่อสรุปผลการทดสอบในรายงานโครงงาน ถ้าปิดเบราว์เซอร์กลางทาง ให้ลงทะเบียนใหม่</p>
@endsection
