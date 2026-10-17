<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Translation\TranslationOverrides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TranslationOverridesTest extends TestCase
{
    use RefreshDatabase;

    private function overrides(): TranslationOverrides
    {
        return app(TranslationOverrides::class);
    }

    public function test_draft_is_invisible_until_published_then_applies(): void
    {
        $user = User::factory()->create();
        $key = 'appointments.status.no_show';
        $this->assertSame('Neprezentat', __($key, [], 'ro'));

        $this->overrides()->saveDraft('ro', $key, 'Nu s-a prezentat', $user);
        $this->assertSame('Neprezentat', __($key, [], 'ro'));

        $this->overrides()->publish('ro', $key, $user);
        $this->assertSame('Nu s-a prezentat', __($key, [], 'ro'));
        // The other language is unaffected.
        $this->assertSame('Nem jelent meg', __($key, [], 'hu'));

        $this->assertDatabaseHas('translation_revisions', ['key' => $key, 'action' => 'draft', 'user_id' => $user->id]);
        $this->assertDatabaseHas('translation_revisions', ['key' => $key, 'action' => 'publish', 'user_id' => $user->id]);
    }

    public function test_placeholders_are_protected(): void
    {
        $key = 'translations.placeholders_mismatch';

        try {
            $this->overrides()->saveDraft('hu', $key, 'Hibás változók: :expected');
            $this->fail('Missing :actual must be rejected');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('translation_overrides', ['key' => $key]);
        }

        $this->overrides()->saveDraft('hu', $key, 'Változók – elvárt: :expected, kapott: :actual');
        $this->overrides()->publish('hu', $key);
        $this->assertSame('Változók – elvárt: A, kapott: B', __($key, ['expected' => 'A', 'actual' => 'B'], 'hu'));
    }

    public function test_brace_placeholders_are_extracted(): void
    {
        $this->assertSame(['{date}', '{patient_name}', '{time}'], TranslationOverrides::placeholders('Bună {patient_name}, vă așteptăm pe {date} la {time}. Ora 09:30.'));
    }

    public function test_reset_restores_the_standard_text(): void
    {
        $key = 'appointments.status.pending';
        $this->overrides()->saveDraft('ro', $key, 'Neconfirmată');
        $this->overrides()->publish('ro', $key);
        $this->assertSame('Neconfirmată', __($key, [], 'ro'));

        $this->overrides()->reset('ro', $key);
        $this->assertSame('În așteptare', __($key, [], 'ro'));
    }

    public function test_corrections_survive_replacing_the_language_files(): void
    {
        // A ZIP update replaces lang/*.php but never touches the database table (TRD-04, T06).
        $key = 'booking.errors.too_far';
        $this->overrides()->saveDraft('ro', $key, 'Data aleasă este prea îndepărtată.');
        $this->overrides()->publish('ro', $key);

        app('translator')->setLoaded([]);
        $this->assertSame('Data aleasă este prea îndepărtată.', __($key, [], 'ro'));
        $this->assertSame('Az időpont túllépi az online foglalás maximális időszakát.', __($key, [], 'hu'));
    }
}
