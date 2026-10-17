<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T05: two employees work at the same time in different languages. */
class StaffLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_employee_sees_their_own_language(): void
    {
        $ro = $this->staff('reception', ['locale' => 'ro', 'name' => 'Ana Demo']);
        $hu = $this->staff('reception', ['locale' => 'hu', 'name' => 'Béla Demo']);

        $this->signIn($ro)->get('/crm')->assertSee('Bună ziua, Ana Demo')->assertSee('<html lang="ro">', false);
        $this->signIn($hu)->get('/crm')->assertSee('Jó napot, Béla Demo')->assertSee('<html lang="hu">', false);
        // Interleaved requests do not leak the other's language.
        $this->signIn($ro)->get('/crm')->assertSee('Programările de azi');
    }

    public function test_switching_language_changes_only_that_employee_and_keeps_the_page(): void
    {
        $a = $this->staff('reception', ['locale' => 'ro']);
        $b = $this->staff('reception', ['locale' => 'ro']);

        $this->signIn($a)->post('/crm/locale', ['locale' => 'hu', 'return_to' => '/crm?filter=today'])
            ->assertRedirect('/crm?filter=today');

        $this->assertSame('hu', $a->fresh()->locale);
        $this->assertSame('ro', $b->fresh()->locale);
    }

    public function test_switch_rejects_external_redirects_and_unknown_languages(): void
    {
        $a = $this->staff('reception');

        $this->signIn($a)->post('/crm/locale', ['locale' => 'hu', 'return_to' => '//evil.example/x'])->assertRedirect(route('crm.dashboard'));
        $this->signIn($a)->post('/crm/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');
    }
}
