<?php

namespace Tests\Feature\Cms;

use App\Domain\Cms\MediaLibrary;
use App\Domain\Cms\Sections;
use App\Livewire\Cms\MediaManager;
use App\Livewire\Cms\MenuEditor;
use App\Livewire\Cms\OfferEditor;
use App\Livewire\Cms\PageEditor;
use App\Livewire\Cms\PageIndex;
use App\Livewire\Cms\RedirectManager;
use App\Models\Media;
use App\Models\MenuItem;
use App\Models\NotFoundLog;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CmsFixtures;
use Tests\TestCase;

class CmsAdminTest extends TestCase
{
    use CmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function page(): Page
    {
        return $this->makePage('about', 'despre-noi', 'rolunk', ['title' => 'Despre noi', 'body' => 'Versiunea publicată'], ['title' => 'Rólunk', 'body' => 'Közzétett']);
    }

    public function test_cms_screens_are_refused_without_permission_on_the_server(): void
    {
        $page = $this->page();
        $this->signIn($this->staff('reception'));

        $this->get(route('cms.pages.index'))->assertForbidden();
        $this->get(route('cms.pages.edit', $page))->assertForbidden();
        $this->get(route('cms.media'))->assertForbidden();
        $this->get(route('cms.redirects'))->assertForbidden();
        Livewire::test(PageEditor::class, ['page' => $page])->assertForbidden();
    }

    public function test_an_editor_without_publish_right_saves_a_draft_that_does_not_reach_the_site(): void
    {
        $page = $this->page();
        $this->signIn($this->staff('marketing'));

        Livewire::test(PageEditor::class, ['page' => $page])
            ->set('draft.sections.0.text.ro.body', 'Text nou în ciornă')
            ->call('save')->assertHasNoErrors()
            ->call('publish')->assertForbidden();

        $this->assertSame('Text nou în ciornă', $page->fresh()->draft['sections'][0]['text']['ro']['body']);
        $this->get('/despre-noi')->assertSee('Versiunea publicată')->assertDontSee('Text nou în ciornă');
        $this->assertSame(0, $page->versions()->count());
    }

    public function test_publishing_creates_versions_and_an_old_version_can_be_restored_to_draft(): void
    {
        $page = $this->page();
        $this->signIn($this->staff('site_editor'));

        $editor = Livewire::test(PageEditor::class, ['page' => $page])
            ->set('draft.sections.0.text.ro.body', 'Prima versiune')->call('publish')->assertHasNoErrors()
            ->set('draft.sections.0.text.ro.body', 'A doua versiune')->call('publish')->assertHasNoErrors();
        $this->get('/despre-noi')->assertSee('A doua versiune');
        $this->assertSame([2, 1], $page->versions()->pluck('number')->all());

        $first = $page->versions()->where('number', 1)->first();
        $editor->call('restoreVersion', $first->id)->assertSet('draft.sections.0.text.ro.body', 'Prima versiune');
        // Restoring changes only the draft; the site changes after the next publication.
        $this->get('/despre-noi')->assertSee('A doua versiune');
    }

    public function test_two_editors_cannot_overwrite_each_other(): void
    {
        $page = $this->page();
        $this->signIn($this->staff('site_editor'));

        $first = Livewire::test(PageEditor::class, ['page' => $page]);
        $second = Livewire::test(PageEditor::class, ['page' => $page]);

        $first->set('draft.sections.0.text.ro.body', 'Modificarea primului')->call('save')->assertSet('conflict', false);
        $second->set('draft.sections.0.text.ro.body', 'Modificarea celui de-al doilea')->call('save')->assertSet('conflict', true);

        $this->assertSame('Modificarea primului', $page->fresh()->draft['sections'][0]['text']['ro']['body']);
        $second->call('reload')->assertSet('draft.sections.0.text.ro.body', 'Modificarea primului')->assertSet('conflict', false);
    }

    public function test_sections_are_added_reordered_removed_and_sanitized(): void
    {
        $page = $this->page();
        $this->signIn($this->staff('site_editor'));

        Livewire::test(PageEditor::class, ['page' => $page])
            ->set('newType', 'faq')->call('addSection')
            ->call('addItem', 1)
            ->set('draft.sections.1.text.ro.items.0.q', 'Întrebare demo?')
            ->set('draft.sections.1.text.ro.items.0.a', '<script>alert(1)</script> Răspuns')
            ->set('draft.sections.1.data.injected', 'x')
            ->call('moveSection', 1, -1)
            ->set('newType', 'cta')->call('addSection')->call('removeSection', 2)
            ->call('publish')->assertHasNoErrors();

        $sections = $page->fresh()->published['sections'];
        $this->assertSame(['faq', 'rich_text'], array_column($sections, 'type'));
        $this->assertArrayNotHasKey('injected', $sections[0]['data']);
        $this->get('/despre-noi')->assertSee('Întrebare demo?')
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_unknown_section_types_and_bad_links_are_refused(): void
    {
        $page = $this->page();
        $this->signIn($this->staff('site_editor'));

        Livewire::test(PageEditor::class, ['page' => $page])->set('newType', 'php_code')->call('addSection')->assertStatus(422);
        Livewire::test(PageEditor::class, ['page' => $page])
            ->set('newType', 'cta')->call('addSection')
            ->set('draft.sections.1.data.button_target', 'javascript:alert(1)')
            ->call('save')->assertHasErrors(['draft.sections.1.data.button_target']);
    }

    public function test_changing_the_address_of_a_published_page_keeps_the_old_one_working(): void
    {
        $page = $this->page();
        $this->signIn($this->staff('site_editor'));

        Livewire::test(PageEditor::class, ['page' => $page])->set('slug_ro', 'despre-optofarm')->call('save')->assertHasNoErrors();

        $this->get('/despre-optofarm')->assertOk();
        $this->get('/despre-noi')->assertRedirect('/despre-optofarm');
        $this->assertSame(301, (int) Redirect::where('from_path', '/despre-noi')->value('status_code'));
    }

    public function test_addresses_and_seo_texts_need_the_seo_permission(): void
    {
        $page = $this->page();
        $this->signIn($this->staffWith(['cms.edit']));

        Livewire::test(PageEditor::class, ['page' => $page])
            ->set('slug_ro', 'alta-adresa')
            ->set('draft.seo.ro.title', 'Titlu SEO nepermis')
            ->set('draft.sections.0.text.ro.body', 'Text permis')
            ->call('save')->assertHasNoErrors();

        $page->refresh();
        $this->assertSame('despre-noi', $page->slug_ro);
        $this->assertSame('', $page->draft['seo']['ro']['title']);
        $this->assertSame('Text permis', $page->draft['sections'][0]['text']['ro']['body']);

        Livewire::test(PageIndex::class)->set('title_ro', 'Pagină')->set('slug_ro', 'pagina')->set('slug_hu', 'oldal')->call('create')->assertForbidden();
    }

    public function test_creating_a_page_and_system_pages_cannot_be_deleted(): void
    {
        $this->signIn($this->staff('site_editor'));
        Livewire::test(PageIndex::class)
            ->set('title_ro', 'Întrebări frecvente')->set('slug_hu', 'gyik')->call('create')->assertHasNoErrors();
        $page = Page::where('slug_ro', 'intrebari-frecvente')->firstOrFail();
        $this->assertNull($page->published_at);
        $this->get('/intrebari-frecvente')->assertNotFound();

        $system = $this->page();
        $system->update(['is_system' => true]);
        Livewire::test(PageEditor::class, ['page' => $system])->call('delete')->assertHasErrors(['delete']);
        $this->assertNotSoftDeleted($system);

        Livewire::test(PageIndex::class)->set('title_ro', 'Panou')->set('slug_ro', 'crm')->set('slug_hu', 'crm-hu')->call('create')->assertHasErrors(['slug_ro']);
    }

    public function test_uploaded_images_are_reencoded_and_used_images_cannot_be_deleted(): void
    {
        $page = $this->page();
        $this->signIn($this->staff('site_editor'));

        Livewire::test(MediaManager::class)
            ->set('uploads', [UploadedFile::fake()->image('vitrina.jpg', 1200, 800)])
            ->assertHasNoErrors();
        $media = Media::firstOrFail();
        $this->assertSame('image/webp', $media->mime);
        $this->assertSame([480, 960, 1200], array_map('intval', array_keys($media->variants)));
        Storage::disk('public')->assertExists($media->path);

        Livewire::test(PageEditor::class, ['page' => $page])
            ->call('openPicker', 's.0.image_id')->assertStatus(422);
        Livewire::test(PageEditor::class, ['page' => $page])
            ->set('newType', 'text_image')->call('addSection')
            ->call('openPicker', 's.1.image_id')->call('pick', $media->id)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($media->id, $page->fresh()->draft['sections'][1]['data']['image_id']);

        Livewire::test(MediaManager::class)->call('select', $media->id)
            ->set('alt.ro', 'Vitrina magazinului')->call('saveAlt')
            ->call('delete')->assertHasErrors(['delete']);
        $this->assertSame('Vitrina magazinului', $media->fresh()->alt['ro']);
        $this->assertModelExists($media);
    }

    public function test_non_images_are_rejected(): void
    {
        $this->signIn($this->staff('site_editor'));
        Livewire::test(MediaManager::class)
            ->set('uploads', [UploadedFile::fake()->createWithContent('poza.jpg', '<?php echo 1;')])
            ->assertHasErrors(['uploads.0']);
        $this->assertSame(0, Media::count());
        $this->expectException(\RuntimeException::class);
        app(MediaLibrary::class)->storeFile(UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')->getRealPath());
    }

    public function test_menus_need_the_publish_right_and_link_to_pages(): void
    {
        $page = $this->page();
        $this->signIn($this->staff('marketing'));
        $this->get(route('cms.menus'))->assertForbidden();

        $this->signIn($this->staff('site_editor'));
        Livewire::test(MenuEditor::class)->call('create')
            ->set('form.label.ro', 'Despre')->set('form.label.hu', 'Rólunk')->set('form.page_id', $page->id)
            ->call('save')->assertHasNoErrors();
        $this->get('/hu/rolunk')->assertSee('href="'.url('/hu/rolunk').'"', false);

        Livewire::test(MenuEditor::class)->call('create')
            ->set('form.label.ro', 'Rău')->set('form.kind', 'url')->set('form.target', 'javascript:alert(1)')
            ->call('save')->assertHasErrors(['form.target']);
        $this->assertSame(1, MenuItem::count());
    }

    public function test_redirects_refuse_loops_and_resolve_logged_404s(): void
    {
        $this->signIn($this->staff('site_editor'));
        $this->get('/pagina-disparuta');
        $this->assertTrue(NotFoundLog::where('path', '/pagina-disparuta')->exists());

        Livewire::test(RedirectManager::class)->call('create', '/pagina-disparuta')
            ->set('to_url', '/contact')->call('save')->assertHasNoErrors();
        $this->assertFalse(NotFoundLog::where('path', '/pagina-disparuta')->exists());

        Livewire::test(RedirectManager::class)->call('create')
            ->set('from_path', '/contact')->set('to_url', '/pagina-disparuta')->call('save')->assertHasErrors(['to_url']);
        Livewire::test(RedirectManager::class)->call('create')
            ->set('from_path', '/x')->set('to_url', '/x/')->call('save')->assertHasErrors(['to_url']);
        $this->assertSame(1, Redirect::count());
    }

    public function test_offers_follow_draft_publish_and_period(): void
    {
        $this->signIn($this->staff('site_editor'));
        Livewire::test(OfferEditor::class)
            ->set('content.ro.title', 'Reducere demo la rame')
            ->set('content.ro.body', 'Condiții demo')
            ->set('starts_on', now('Europe/Bucharest')->toDateString())
            ->set('ends_on', now('Europe/Bucharest')->addDays(5)->toDateString())
            ->call('save')->assertHasNoErrors();
        $offer = Post::firstOrFail();
        $this->assertSame('reducere-demo-la-rame', $offer->slug_ro);
        $this->get('/reduceri-actuale/reducere-demo-la-rame')->assertNotFound();

        Livewire::test(OfferEditor::class, ['offer' => $offer])->call('publish');
        $this->get('/reduceri-actuale/reducere-demo-la-rame')->assertOk()->assertSee('Condiții demo');

        Livewire::test(OfferEditor::class, ['offer' => $offer])->set('ends_on', now()->subDays(10)->toDateString())->call('save')->assertHasErrors(['ends_on']);
    }
}
