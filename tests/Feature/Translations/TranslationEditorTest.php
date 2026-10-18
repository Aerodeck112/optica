<?php

namespace Tests\Feature\Translations;

use App\Http\Middleware\TranslationMode;
use App\Livewire\Admin\TranslationCenter;
use App\Livewire\Translations\InlineEditor;
use App\Models\Patient;
use App\Models\TranslationOverride;
use App\Support\Translation\TranslationOverrides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CmsFixtures;
use Tests\TestCase;

class TranslationEditorTest extends TestCase
{
    use CmsFixtures, RefreshDatabase;

    private const KEY = 'crm.nav.dashboard';

    private function dictionary(string $html): ?array
    {
        if (! preg_match('/window\.__trDict = (.*?);window\.dispatchEvent/s', $html, $m)) {
            return null;
        }

        return json_decode($m[1], true);
    }

    public function test_only_translators_can_switch_the_mode_on_and_the_redirect_stays_local(): void
    {
        $this->signIn($this->staff('reception'))->post(route('crm.translation-mode'), ['return_to' => '/crm'])->assertForbidden();

        $editor = $this->staff('site_editor');
        $this->signIn($editor)->post(route('crm.translation-mode'), ['return_to' => '//evil.example/x'])->assertRedirect('/');
        $this->assertTrue(session(TranslationMode::SESSION_KEY));
        $this->post(route('crm.translation-mode'), ['return_to' => '/crm'])->assertRedirect('/crm');
        $this->assertFalse(session(TranslationMode::SESSION_KEY));
    }

    public function test_pages_carry_the_dictionary_of_displayed_texts_only_in_translation_mode(): void
    {
        $editor = $this->staff('site_editor');
        $page = $this->signIn($editor)->get(route('crm.dashboard'))->assertOk()->getContent();
        $this->assertNull($this->dictionary($page));
        $this->assertStringContainsString(__('translations.mode.turn_on'), $page);

        $page = $this->withSession([TranslationMode::SESSION_KEY => true])->get(route('crm.dashboard'))->assertOk()->getContent();
        $dict = $this->dictionary($page);
        $this->assertSame(__(self::KEY), $dict[self::KEY]['text']);
        $this->assertFalse($dict[self::KEY]['fallback']);
        $this->assertStringContainsString(__('translations.mode.on'), $page);
        // Texts with variables are kept as templates: the user's name is on the page, not in the dictionary.
        $this->assertStringContainsString($editor->name, $page);
        $this->assertSame(__('crm.dashboard.greeting'), $dict['crm.dashboard.greeting']['text']);
        $this->assertTrue($dict['crm.dashboard.greeting']['pattern']);
        $this->assertStringNotContainsString($editor->name, json_encode($dict, JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseHas('translation_usages', ['key' => self::KEY, 'route' => 'crm.dashboard']);

        // A user without the permission never gets the dictionary, even with the flag in the session.
        $reception = $this->staff('reception');
        $page = $this->signIn($reception)->withSession([TranslationMode::SESSION_KEY => true])->get(route('crm.dashboard'))->getContent();
        $this->assertNull($this->dictionary($page));
    }

    public function test_patient_data_is_never_offered_as_a_translatable_text(): void
    {
        $user = $this->staffWith(['patients.view', 'translations.edit']);
        $user->update(['all_locations' => true]);
        $patient = Patient::create(['code' => 'P900001', 'first_name' => 'Ionela', 'last_name' => 'Fictivescu', 'phone' => '+40700000001', 'locale' => 'ro']);

        $page = $this->signIn($user)->withSession([TranslationMode::SESSION_KEY => true])->get(route('crm.patients.show', $patient))->assertOk()->getContent();
        $this->assertStringContainsString('Fictivescu', $page);
        $dict = $this->dictionary($page);
        $this->assertNotEmpty($dict);
        foreach ($dict as $key => $entry) {
            $this->assertStringNotContainsString('Fictivescu', $entry['text'], $key);
            $this->assertStringNotContainsString('+40700000001', $entry['text'], $key);
        }
    }

    public function test_inline_editor_saves_drafts_and_only_publishers_publish(): void
    {
        Livewire::actingAs($this->staff('reception'))->test(InlineEditor::class)->assertForbidden();

        $translator = $this->staffWith(['translations.edit']);
        Livewire::actingAs($translator)->test(InlineEditor::class)
            ->call('open', [self::KEY, 'unknown.key', '../etc.passwd'])
            ->assertSet('keys', [self::KEY])
            ->assertSet('values.ro', __(self::KEY, [], 'ro'))
            ->set('values.ro', 'Pagina principală')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('tr-saved')
            ->call('publish')
            ->assertForbidden();
        $this->assertDatabaseHas('translation_overrides', ['locale' => 'ro', 'key' => self::KEY, 'draft_value' => 'Pagina principală', 'published_value' => null]);
        $this->assertNotSame('Pagina principală', __(self::KEY, [], 'ro'));

        Livewire::actingAs($this->staff('site_editor'))->test(InlineEditor::class)
            ->call('open', [self::KEY])
            ->call('publish')
            ->assertDispatched('tr-preview', key: self::KEY, text: 'Pagina principală');
        $this->assertSame('Pagina principală', __(self::KEY, [], 'ro'));
        // Hungarian was not touched.
        $this->assertDatabaseMissing('translation_overrides', ['locale' => 'hu', 'key' => self::KEY]);
    }

    public function test_inline_editor_protects_placeholders_restores_history_and_resets(): void
    {
        $key = 'translations.center.import_done';
        $editor = $this->staff('site_editor');
        $component = Livewire::actingAs($editor)->test(InlineEditor::class)->call('open', [$key])
            ->set('values.hu', 'Kész.')
            ->call('save')
            ->assertHasErrors('values');
        $this->assertDatabaseMissing('translation_overrides', ['key' => $key]);

        $component->set('values.hu', 'Új piszkozatok: :drafts, változatlan: :unchanged.')->call('publish')->assertHasNoErrors();
        $component->set('values.hu', 'Második: :drafts / :unchanged')->call('save');
        $first = DB::table('translation_revisions')->where(['key' => $key, 'action' => 'draft'])->orderBy('id')->first();
        $component->call('restore', $first->id)->assertSet('values.hu', 'Új piszkozatok: :drafts, változatlan: :unchanged.');
        $this->assertDatabaseHas('translation_revisions', ['key' => $key, 'action' => 'restore', 'locale' => 'hu']);

        $component->call('revert', 'hu');
        $this->assertDatabaseMissing('translation_overrides', ['key' => $key]);
        $this->assertSame(__($key, ['drafts' => 1, 'unchanged' => 2], 'hu'), trans($key, ['drafts' => 1, 'unchanged' => 2], 'hu'));
        $this->assertStringStartsWith('Létrehozott', __($key, [], 'hu'));
    }

    public function test_centre_requires_permission_and_filters_by_state_text_module_and_page(): void
    {
        $this->signIn($this->staff('reception'))->get(route('admin.translations'))->assertForbidden();
        $editor = $this->staff('site_editor');
        $this->signIn($editor)->get(route('admin.translations'))->assertOk()->assertSee(__('translations.center.title'));

        app(TranslationOverrides::class)->saveDraft('hu', 'crm.nav.calendar', 'Naptár (javítva)');
        DB::table('translation_usages')->insert(['key' => 'crm.nav.patients', 'route' => 'crm.patients.index', 'last_seen_at' => now()]);

        Livewire::actingAs($editor)->test(TranslationCenter::class)
            ->set('status', 'draft')
            ->assertSee('crm.nav.calendar')->assertSee('Naptár (javítva)')->assertDontSee('crm.nav.patients')
            ->set('status', '')
            ->set('screen', 'crm.patients.index')
            ->assertSee('crm.nav.patients')->assertDontSee('crm.nav.calendar')
            ->set('screen', '')
            ->set('group', 'translations')
            ->set('q', 'variabile')
            ->assertSee('translations.placeholders_mismatch')->assertDontSee('crm.nav.calendar');
    }

    public function test_import_creates_drafts_with_validated_placeholders_and_export_downloads(): void
    {
        $editor = $this->staff('site_editor');
        $csv = "\xEF\xBB\xBFkey;module;status;ro_standard;ro;hu_standard;hu\n"
            ."crm.nav.dashboard;crm;;x;Acasă;y;\n"
            ."translations.center.import_done;translations;;x;;y;Hibás\n"
            ."nu.exista;x;;x;Ceva;y;\n"
            ."crm.nav.calendar;crm;;x;'-Calendar;y;\n";
        $file = UploadedFile::fake()->createWithContent('traduceri.csv', $csv);

        $component = Livewire::actingAs($editor)->test(TranslationCenter::class)
            ->set('importFile', $file)
            ->call('import')
            ->assertHasNoErrors();
        $result = $component->get('importResult');
        $this->assertSame(2, $result['drafts']);
        $this->assertCount(2, $result['errors']);
        $this->assertDatabaseHas('translation_overrides', ['locale' => 'ro', 'key' => 'crm.nav.dashboard', 'draft_value' => 'Acasă', 'published_value' => null]);
        // The formula guard added by the export is removed again.
        $this->assertDatabaseHas('translation_overrides', ['locale' => 'ro', 'key' => 'crm.nav.calendar', 'draft_value' => '-Calendar']);
        $this->assertDatabaseMissing('translation_overrides', ['key' => 'translations.center.import_done']);
        $this->assertSame(2, DB::table('translation_revisions')->where('action', 'import')->count());
        // Import never publishes.
        $this->assertNotSame('Acasă', __('crm.nav.dashboard', [], 'ro'));

        $component->call('export', 'csv')->assertFileDownloaded();

        // Publishing all drafts needs the publish permission.
        Livewire::actingAs($this->staffWith(['translations.edit']))->test(TranslationCenter::class)->call('publishDrafts')->assertForbidden();
        Livewire::actingAs($editor)->test(TranslationCenter::class)->call('publishDrafts');
        $this->assertSame('Acasă', __('crm.nav.dashboard', [], 'ro'));
        $this->assertSame(0, TranslationOverride::whereColumn('draft_value', '!=', 'published_value')->count());
    }

    public function test_site_offers_the_mode_switch_only_to_translators(): void
    {
        $this->makePage('about', 'despre-noi', 'rolunk', ['title' => 'Despre noi', 'body' => 'Text'], ['title' => 'Rólunk', 'body' => 'Szöveg']);
        $this->get('/despre-noi')->assertOk()->assertDontSee(__('translations.mode.turn_on'));
        $this->signIn($this->staff('site_editor'))->get('/despre-noi')->assertOk()->assertSee(__('translations.mode.turn_on'));
        $page = $this->withSession([TranslationMode::SESSION_KEY => true])->get('/despre-noi')->assertOk()->getContent();
        $this->assertNotNull($this->dictionary($page));
    }
}
