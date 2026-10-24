<?php

namespace Tests\Feature\Cms;

use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Services\PageEditor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PageEditorTest extends TestCase
{
    use RefreshDatabase;

    private function page(): Page
    {
        return Page::query()->create([
            'key' => 'despre', 'template' => 'blocks', 'slug' => ['ro' => 'despre'], 'title' => ['ro' => 'Despre'],
            'blocks' => ['ro' => [['type' => 'rich_text', 'data' => ['body' => 'Versiunea publicată']]]],
            'published_locales' => ['ro'], 'status' => 'published', 'published_at' => now()->subDay(),
        ]);
    }

    private function state(string $body, array $extra = []): array
    {
        return array_replace_recursive([
            'title' => ['ro' => 'Despre'], 'slug' => ['ro' => 'despre'], 'seo_title' => [], 'seo_description' => [],
            'published_locales' => ['ro'],
            'blocks' => ['ro' => [['type' => 'rich_text', 'data' => ['body' => $body]], ['type' => 'script_injection', 'data' => []]]],
        ], $extra);
    }

    public function test_saving_a_draft_does_not_change_the_live_page(): void
    {
        $page = $this->page();

        app(PageEditor::class)->saveDraft($page, $this->state('Ciornă'), null);

        $page->refresh();
        $this->assertSame('Versiunea publicată', $page->blocks['ro'][0]['data']['body']);
        $this->assertTrue($page->has_draft);
        $this->assertCount(1, $page->draft_blocks['ro'], 'Tipurile de bloc necunoscute sunt eliminate.');
    }

    public function test_publish_replaces_live_content_and_restore_brings_back_old_version_as_draft(): void
    {
        $page = $this->page();
        $editor = app(PageEditor::class);
        $editor->saveDraft($page, $this->state('Text nou'), null);
        $editor->publish($page->refresh(), null);
        $this->assertSame('Text nou', $page->refresh()->blocks['ro'][0]['data']['body']);

        $first = $page->revisions()->where('reason', 'draft_saved')->oldest('id')->first();
        $editor->restore($first, null);

        $page->refresh();
        $this->assertSame('Text nou', $page->blocks['ro'][0]['data']['body'], 'Restaurarea nu publică automat.');
        $this->assertSame('Text nou', $page->draft_blocks['ro'][0]['data']['body']);
    }

    public function test_incomplete_translation_cannot_be_published(): void
    {
        $page = $this->page();
        $editor = app(PageEditor::class);
        $editor->saveDraft($page, $this->state('Text', ['published_locales' => ['ro', 'en'], 'title' => ['en' => 'About']]), null);

        $this->expectException(ValidationException::class);
        $editor->publish($page->refresh(), null);
    }

    public function test_slug_change_creates_permanent_redirect(): void
    {
        $page = $this->page();
        $editor = app(PageEditor::class);
        $editor->saveDraft($page, $this->state('Text', ['slug' => ['ro' => 'despre-noi']]), null);
        $editor->publish($page->refresh(), null);

        $this->assertSame('/despre-noi', Redirect::query()->where('from_path', '/despre')->value('to_url'));
    }

    public function test_scheduled_publication_happens_only_when_due(): void
    {
        $page = $this->page();
        $editor = app(PageEditor::class);
        $editor->saveDraft($page, $this->state('Programat'), null);
        $editor->publish($page->refresh(), null, CarbonImmutable::now()->addHour());

        $this->assertSame(0, $editor->publishDue());
        $this->travel(61)->minutes();
        $this->assertSame(1, $editor->publishDue());
        $this->assertSame('Programat', $page->refresh()->blocks['ro'][0]['data']['body']);
    }
}
