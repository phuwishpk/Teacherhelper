{{-- The participant's SUS (DESIGN §39.10): once, after the four tasks. --}}
@extends('sus.layout')
@section('title', 'แบบประเมิน SUS')
@section('content')
    @if ($sent)
        <h1>ขอบคุณ {{ $me->name }}</h1>
        <div class="card"><p>คุณทำการทดสอบครบแล้ว คำตอบของคุณช่วยให้ Krucheck ใช้ง่ายขึ้น ปิดหน้านี้ได้เลย</p></div>
    @else
        <h1>แบบประเมินความรู้สึกต่อการใช้งาน</h1>
        <p class="muted">ตอบจากงานทั้ง 4 ที่เพิ่งทำ</p>

        <section class="card intro" aria-labelledby="how">
            <h2 id="how">ก่อนตอบ</h2>
            <ol>
                <li>ตอบ<strong>ทุกข้อ</strong> ด้วยความรู้สึกแรก ไม่ต้องคิดนาน</li>
                <li>ข้อที่ไม่แน่ใจ ให้เลือก <strong>3</strong></li>
                <li>บางข้อเป็นด้านบวก บางข้อเป็นด้านลบ อ่านให้จบก่อนเลือก</li>
            </ol>
        </section>

        @if ($errors->any())
            <div class="alert error" role="alert">ยังตอบไม่ครบ {{ $errors->count() }} จุด ดูข้อที่มีข้อความสีแดง</div>
        @endif

        <form method="POST" action="{{ route('usability.sus.store') }}">
            @csrf
            @include('sus._questions')
            <button class="btn full" type="submit">ส่งแบบประเมิน</button>
            <p class="small muted" style="margin-top:10px;text-align:center;">ส่งแล้วแก้ไม่ได้</p>
        </form>
    @endif
@endsection
