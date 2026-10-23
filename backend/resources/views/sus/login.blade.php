@extends('sus.layout')
@section('title', 'เข้าสู่ระบบผู้ประเมิน')
@section('content')
    <h1>ขอบคุณที่ช่วยประเมิน Krucheck</h1>
    <p class="muted">หน้านี้สำหรับผู้ประเมินที่ได้รับเชิญ ใช้อีเมลและรหัสผ่านที่ผู้ดำเนินการทดสอบให้ไว้ ใช้เวลาตอบประมาณ 3 นาที</p>

    @if ($errors->any())
        <div class="alert error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form class="card" method="POST" action="{{ route('sus.login.attempt') }}" style="display:grid;gap:14px;">
        @csrf
        <label class="field">อีเมล
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required @class(['has-error' => $errors->has('email')])>
        </label>
        <label class="field">รหัสผ่าน
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn full" type="submit">เริ่มประเมิน</button>
    </form>
    <p class="small muted">ข้อมูลที่ตอบใช้เพื่อประเมินและปรับปรุงระบบเท่านั้น ไม่มีผลต่อคะแนนหรือการเรียนของใคร</p>
@endsection
