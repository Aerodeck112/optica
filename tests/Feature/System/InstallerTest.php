<?php

namespace Tests\Feature\System;

use App\Support\Install\EnvBootstrap;
use App\Support\Install\InstallState;
use Tests\TestCase;

/** INS-02: the installer is the only page before installation, needs the token and then locks. */
class InstallerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['optica.installed' => false]);
    }

    protected function tearDown(): void
    {
        @unlink(InstallState::tokenPath());
        parent::tearDown();
    }

    public function test_every_page_leads_to_the_installer_until_it_has_run(): void
    {
        $this->get('/')->assertRedirect('/install');
        $this->get('/crm/login')->assertRedirect('/install');
        $this->get('/pagina-inexistenta')->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertSee(__('install.token.heading'))->assertSee('storage/app/install-token.txt');
        $this->assertFileExists(InstallState::tokenPath());
    }

    public function test_the_token_is_required_and_steps_cannot_be_skipped(): void
    {
        $this->get('/install/database')->assertRedirect(route('install.index'));
        $this->post('/install/database', ['host' => 'x', 'port' => 3306, 'database' => 'x', 'username' => 'x'])->assertForbidden();
        $this->get('/install');
        $this->post('/install/token', ['token' => 'wrong'])->assertSessionHasErrors('token');
        $this->post('/install/token', ['token' => InstallState::token()])->assertRedirect(route('install.step', 'requirements'));
        $this->get('/install/requirements')->assertOk()->assertSee(__('install.req.extension', ['name' => 'sodium']));
        // The administrator step needs the database and the structure first.
        $this->get('/install/admin')->assertRedirect();
    }

    public function test_installer_speaks_hungarian(): void
    {
        $this->get('/install?lang=hu')->assertOk()->assertSee(__('install.token.heading', [], 'hu'));
    }

    public function test_after_installation_the_installer_is_gone(): void
    {
        config(['optica.installed' => true]);
        $this->get('/install')->assertNotFound();
        $this->post('/install/token', ['token' => 'x'])->assertNotFound();
    }

    public function test_env_values_are_written_safely(): void
    {
        $env = EnvBootstrap::apply("APP_NAME=x\nDB_PASSWORD=old\n", ['DB_PASSWORD' => 'p@ss "word" $HOME', 'APP_URL' => 'https://optofarm.ro']);
        $this->assertStringContainsString('DB_PASSWORD="p@ss \"word\" \$HOME"', $env);
        $this->assertStringContainsString("APP_URL=https://optofarm.ro\n", $env);
        $this->assertSame(1, substr_count($env, 'DB_PASSWORD='));
    }
}
