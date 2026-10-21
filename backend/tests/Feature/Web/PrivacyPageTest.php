<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

/**
 * GET /privacy: the public privacy policy that the Google Cloud consent
 * screens link to (DESIGN §24.14). Google reads it without signing in.
 */
class PrivacyPageTest extends TestCase
{
    public function test_anyone_can_read_the_policy_without_a_session(): void
    {
        $page = $this->get('/privacy');

        $page->assertOk();
        $page->assertSee('นโยบายความเป็นส่วนตัว Krucheck');
        $page->assertCookieMissing(config('session.cookie'));
        $this->assertStringContainsString("default-src 'none'", (string) $page->headers->get('Content-Security-Policy'));
    }

    public function test_it_carries_the_google_limited_use_disclosure(): void
    {
        $page = $this->get('/privacy');

        $page->assertSee('Google API Services User Data Policy');
        $page->assertSee('Limited Use requirements');
        // Retention matches what the code does (DESIGN §31.3, §38).
        $page->assertSee('เมื่อครบ 30 วัน ระบบลบข้อมูลต่อไปนี้อย่างถาวร');
        $page->assertSee('"ครูที่ถูกลบ"', false);
        $page->assertSee('Cloud Firestore');
        $page->assertSee('https://developers.google.com/terms/api-services-user-data-policy', false);
    }

    public function test_it_shows_the_configured_contact_address(): void
    {
        config(['eduvision.privacy.contact_email' => 'privacy@example.test']);

        $this->get('/privacy')->assertOk()->assertSee('mailto:privacy@example.test', false);
    }

    public function test_without_a_contact_address_it_points_at_the_teacher(): void
    {
        config(['eduvision.privacy.contact_email' => null]);

        $page = $this->get('/privacy')->assertOk();

        $page->assertDontSee('mailto:', false);
        $page->assertSee('ติดต่อครูหรือผู้ดูแลระบบ');
    }

    public function test_it_names_the_site_it_is_served_from(): void
    {
        // The same view serves production and the self-hosted test server (DESIGN §26).
        $this->get('http://krucheck.example.test/privacy')->assertOk()->assertSee('เว็บไซต์ krucheck.example.test');
    }
}
