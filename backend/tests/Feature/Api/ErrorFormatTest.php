<?php

namespace Tests\Feature\Api;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every error on /api/* must be JSON with the shape {message, errors, code}
 * (DESIGN §9), whatever the client sends and whichever layer raised it.
 */
class ErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_without_accept_header_is_a_json_401(): void
    {
        // Plain get(): no Accept: application/json, like a browser or a monitoring probe.
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors', 'code'])
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonPath('errors', []);
    }

    public function test_unknown_api_route_is_a_json_404_with_a_code(): void
    {
        $this->get('/api/v1/nope')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors', 'code'])
            ->assertJsonPath('code', 'not_found')
            ->assertJsonMissingPath('exception');
    }

    public function test_wrong_method_is_a_json_405_with_a_code(): void
    {
        $this->getJson('/api/v1/auth/teacher/login')
            ->assertMethodNotAllowed()
            ->assertJsonStructure(['message', 'errors', 'code'])
            ->assertJsonPath('code', 'method_not_allowed');
    }

    public function test_abort_on_an_api_route_keeps_the_shape_and_gets_a_generic_code(): void
    {
        Route::get('/api/v1/_test/teapot', fn () => abort(418));

        $this->getJson('/api/v1/_test/teapot')
            ->assertStatus(418)
            ->assertJsonStructure(['message', 'errors', 'code'])
            ->assertJsonPath('code', 'http_418');
    }

    public function test_web_guests_are_redirected_to_the_filament_login(): void
    {
        Route::middleware('auth')->get('/_test/secret', fn () => 'ok');

        $this->get('/_test/secret')->assertRedirect('/admin/login');
    }

    public function test_expected_client_errors_are_not_reported_to_the_log(): void
    {
        $handler = app(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(new ApiException('ผิดพลาด', 'some_code')));
    }
}
