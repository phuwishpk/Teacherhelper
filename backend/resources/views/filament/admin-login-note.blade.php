{{-- Under the Filament login form (AdminPanelProvider render hook, DESIGN §7.4). --}}
@if (session()->has(\App\Http\Controllers\AdminHandoffLinkController::ERROR_KEY))
    <p role="alert" style="color: rgb(220 38 38); font-size: 0.875rem; margin-top: 0.5rem;">
        {{ session(\App\Http\Controllers\AdminHandoffLinkController::ERROR_KEY) }}
    </p>
@endif
<p style="font-size: 0.875rem; opacity: 0.75; text-align: center; margin-top: 0.5rem;">
    เข้าสู่ระบบจากแอป EduVision ได้ด้วยบัญชีเดียวกัน
</p>
