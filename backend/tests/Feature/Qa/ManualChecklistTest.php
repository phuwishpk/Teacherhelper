<?php

namespace Tests\Feature\Qa;

use App\Domain\Qa\ManualChecklist;
use App\Models\QaTester;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §39.7: the manual web checklist in the Test Lab, its boxes to tick
 * and its test files, downloadable by Test Lab accounts only.
 */
class ManualChecklistTest extends TestCase
{
    use RefreshDatabase;

    private QaTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = QaTester::create(['name' => 'ผู้ทดสอบหนึ่ง', 'email' => 'one@qa.test', 'password' => 'secret1234']);
    }

    private function asQa(): static
    {
        $this->forgetGuards();
        Filament::setCurrentPanel(Filament::getPanel('qa'));

        return $this->actingAs($this->tester, 'qa');
    }

    public function test_every_file_the_checklist_links_exists(): void
    {
        $markdown = (string) file_get_contents(ManualChecklist::readme());
        preg_match_all('/\]\(files\/([^)]+)\)/', $markdown, $m);

        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $name) {
            $this->assertNotNull(ManualChecklist::file($name), "files/{$name}");
        }
        $this->assertSame(count(array_unique($m[1])), count(ManualChecklist::files()));
    }

    public function test_the_steps_are_numbered_one_after_another(): void
    {
        preg_match_all('/^- \[ \] \*\*(\d+)\.\*\*/m', (string) file_get_contents(ManualChecklist::readme()), $m);

        $this->assertSame(range(1, count($m[1])), array_map('intval', $m[1]));
        $this->assertGreaterThanOrEqual(128, count($m[1]));
    }

    public function test_the_page_shows_tickable_steps_and_download_links(): void
    {
        $this->get('/qa/checklist')->assertRedirect('/qa/login');

        $html = $this->asQa()->get('/qa/checklist')->assertOk()
            ->assertSee('เช็คลิสต์ทดสอบด้วยมือ')
            ->assertSee('ม.4/9 ทดสอบ')
            ->assertSee('href="'.url('/qa/checklist/files/questions.pdf').'"', false)
            ->assertDontSee('href="files/', false)
            ->getContent();

        $this->assertGreaterThanOrEqual(80, substr_count($html, 'data-qa-check="'));
        $this->assertStringNotContainsString('disabled="" type="checkbox"', $html);
    }

    public function test_only_test_lab_accounts_download_the_files_and_only_those_files(): void
    {
        $this->get('/qa/checklist/files/questions.pdf')->assertRedirect('/qa/login');

        $this->asQa()->get('/qa/checklist/files/questions.pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('questions.pdf');
        $this->get('/qa/checklist/files/README.md')->assertNotFound();
        $this->get('/qa/checklist/files/..%2F..%2F.env')->assertNotFound();
        $this->get('/qa/checklist/files/nope.pdf')->assertNotFound();
    }
}
