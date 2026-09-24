<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_filament_login_page_boots(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_the_dashboard_requires_a_session(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
