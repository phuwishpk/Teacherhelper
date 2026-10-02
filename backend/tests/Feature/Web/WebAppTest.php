<?php

namespace Tests\Feature\Web;

use App\Domain\Web\WebApp;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Flutter web app installed next to the API (DESIGN §25): the upload of
 * tools/build-web.sh in public/app. It is not in the repository, so the
 * front page and the result link only point at it when it is there.
 */
class WebAppTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->public = sys_get_temp_dir().'/eduvision-public-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->public);
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->public);
        parent::tearDown();
    }

    private function install(): void
    {
        File::makeDirectory($this->public.'/app');
        File::put($this->public.'/app/index.html', '<!DOCTYPE html><title>EduVision</title>');
    }

    public function test_without_the_upload_nothing_points_at_the_web_app(): void
    {
        $this->assertFalse(WebApp::installed());
        $this->get('/')->assertOk();
        $this->get('/r/7')->assertOk()->assertDontSee('เปิดบนเว็บ')->assertDontSee('/app/', false);
    }

    public function test_the_front_page_goes_to_the_installed_web_app(): void
    {
        $this->install();

        $this->assertTrue(WebApp::installed());
        $this->get('/')->assertRedirect('/app/');
    }

    public function test_the_result_link_also_opens_the_result_on_the_web(): void
    {
        $this->install();

        $page = $this->get('/r/7')->assertOk();

        $page->assertSee('เปิดบนเว็บ');
        $page->assertSee(url('/app').'/#/student/results/7', false);
        // The Android intent link stays, and the page still loads nothing from anywhere.
        $page->assertSee('intent://r/7#Intent;scheme=eduvision;package=com.eduvision.app;end', false);
        $this->assertStringContainsString("default-src 'none'", (string) $page->headers->get('Content-Security-Policy'));
    }
}
