@use('App\Domain\Qa\QaSus')
@extends('sus.layout')
@section('title', 'แบบประเมิน SUS')
@section('header-end')
    <form method="POST" action="{{ route('sus.logout') }}">
        @csrf
        <button class="btn ghost" type="submit">ออกจากระบบ</button>
    </form>
@endsection
@section('content')
    @php $done = isset($sent[$role]); $justSent = session('sus_sent') === $role; @endphp

    <h1>สวัสดี {{ $evaluator->name }}</h1>
    <p class="muted">ประเมินความรู้สึกต่อการใช้งาน Krucheck ที่คุณเพิ่งใช้</p>

    <section class="card intro" aria-labelledby="how">
        <h2 id="how">ก่อนตอบ</h2>
        <ol>
            <li>ตอบ<strong>ทันทีหลังใช้งานเสร็จ</strong> ก่อนพูดคุยกับผู้ดำเนินการทดสอบ</li>
            <li>ตอบ<strong>ทุกข้อ</strong> ด้วยความรู้สึกแรก ไม่ต้องคิดนาน</li>
            <li>ข้อที่ไม่แน่ใจ ให้เลือก <strong>3</strong></li>
            <li>บางข้อเป็นด้านบวก บางข้อเป็นด้านลบ อ่านให้จบก่อนเลือก</li>
        </ol>
    </section>

    <nav class="tabs" aria-label="ฝั่งของแอปที่ประเมิน">
        @foreach (QaSus::ROLES as $value => $label)
            <a class="tab {{ $value === $role ? 'on' : '' }}" href="{{ route('sus.form', ['role' => $value]) }}" @if ($value === $role) aria-current="page" @endif>
                <b>{{ $label }}</b>
                <span>{{ isset($sent[$value]) ? 'ส่งแล้ว '.$sent[$value] : 'ยังไม่ได้ประเมิน' }}</span>
            </a>
        @endforeach
    </nav>

    @if ($justSent)
        <div class="alert ok" role="status">ส่งแบบประเมิน{{ QaSus::ROLES[$role] }}แล้ว ขอบคุณมาก @if (count($sent) < count(QaSus::ROLES)) ถ้าคุณใช้แอปอีกฝั่งด้วย เลือกฝั่งนั้นด้านบนแล้วประเมินแยกอีกชุด @endif</div>
    @endif

    @if ($done)
        @unless ($justSent)
            <div class="card"><p>คุณส่งแบบประเมิน{{ QaSus::ROLES[$role] }}แล้วเมื่อ {{ $sent[$role] }} แต่ละฝั่งตอบได้ครั้งเดียว</p></div>
        @endunless
    @else
        @if ($errors->any())
            <div class="alert error" role="alert">ยังตอบไม่ครบ {{ $errors->count() }} จุด ดูข้อที่มีข้อความสีแดง</div>
        @endif

        <form method="POST" action="{{ route('sus.store') }}">
            @csrf
            <input type="hidden" name="role" value="{{ $role }}">

            @include('sus._questions')

            <button class="btn full" type="submit">ส่งแบบประเมิน{{ QaSus::ROLES[$role] }}</button>
            <p class="small muted" style="margin-top:10px;text-align:center;">ส่งแล้วแก้ไม่ได้</p>
        </form>
    @endif
@endsection
