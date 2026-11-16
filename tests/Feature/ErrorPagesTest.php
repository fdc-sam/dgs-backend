<?php

namespace Tests\Feature;

use App\Console\Commands\BuildStaticErrorPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/_test/abort/{code}/{message?}', fn (int $code, ?string $message = null) => abort($code, $message ?? ''));
    }

    public function test_a_missing_page_shows_the_dgs_404_page(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('DGS Hair Salon &amp; Spa', false)
            ->assertSee('We can’t find that page')
            ->assertSee('Go to home page')
            ->assertSee('Go back');
    }

    public function test_each_code_has_its_own_page(): void
    {
        $this->get('/_test/abort/403')->assertForbidden()->assertSee('This page is private')->assertSee('Error 403');
        $this->get('/_test/abort/419')->assertStatus(419)->assertSee('This page has expired');
        $this->get('/_test/abort/500')->assertStatus(500)->assertSee('Something went wrong')->assertSee('Try again');
        $this->get('/_test/abort/503')->assertStatus(503)->assertSee('We’ll be right back')->assertSee('Try again')->assertDontSee('Go back');
    }

    public function test_other_codes_fall_back_to_the_general_pages(): void
    {
        $this->get('/_test/abort/422/A completed appointment cannot be repriced.')
            ->assertStatus(422)
            ->assertSee('Error 422')
            ->assertSee('A completed appointment cannot be repriced.');

        // Framework messages are for developers, not visitors.
        $this->post('/_test/abort/404')
            ->assertStatus(405)
            ->assertSee('Error 405')
            ->assertDontSee('method is not supported');

        $this->get('/_test/abort/507')->assertStatus(507)->assertSee('Error 507')->assertSee('Try again');
    }

    public function test_the_api_still_answers_errors_in_json(): void
    {
        $this->getJson('/api/v1/this-does-not-exist')
            ->assertNotFound()
            ->assertJson(['success' => false, 'code' => 'NOT_FOUND']);
    }

    public function test_the_static_pages_for_nginx_match_the_views(): void
    {
        foreach (BuildStaticErrorPages::CODES as $code) {
            $path = public_path("errors/{$code}.html");
            $this->assertFileExists($path, "Run: php artisan errors:static");
            $this->assertSame(
                BuildStaticErrorPages::render($code),
                file_get_contents($path),
                "public/errors/{$code}.html is out of date. Run: php artisan errors:static",
            );
        }
    }
}
