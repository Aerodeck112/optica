<?php

namespace App\Livewire\Cms;

use App\Support\Audit\AuditLogger;
use App\Support\Settings;
use Livewire\Component;

/**
 * Contact details shown on every page of the site (header, footer, hero). Live immediately.
 */
class SiteSettings extends Component
{
    public string $phones = '';

    public string $contact_email = '';

    public string $factory_phone = '';

    public string $factory_email = '';

    public function boot(): void
    {
        $this->authorize('cms.publish');
    }

    public function mount(): void
    {
        $this->phones = implode("\n", (array) Settings::get('site.booking_phones', []));
        $this->contact_email = (string) Settings::get('site.contact_email', '');
        $factory = (array) Settings::get('site.lens_factory_contact', []);
        $this->factory_phone = (string) ($factory['phone'] ?? '');
        $this->factory_email = (string) ($factory['email'] ?? '');
    }

    public function save(): void
    {
        $phones = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $this->phones)))));
        $this->validate([
            'phones' => ['required', 'string', 'max:300'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'factory_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9 +().-]{9,}$/'],
            'factory_email' => ['nullable', 'email', 'max:190'],
        ]);
        foreach ($phones as $phone) {
            if (! preg_match('/^[0-9 +().-]{9,32}$/', $phone)) {
                $this->addError('phones', __('cms.settings.invalid_phone', ['phone' => $phone]));

                return;
            }
        }
        $user = auth()->id();
        Settings::put('site.booking_phones', array_slice($phones, 0, 4), $user);
        Settings::put('site.contact_email', $this->contact_email ?: null, $user);
        Settings::put('site.lens_factory_contact', ['phone' => $this->factory_phone ?: null, 'email' => $this->factory_email ?: null], $user);
        app(AuditLogger::class)->log('cms.settings_saved');
        session()->flash('status', __('cms.settings.saved'));
        $this->redirectRoute('cms.settings', navigate: true);
    }

    public function render()
    {
        return view('livewire.cms.site-settings')->layout('components.layouts.crm')->title(__('cms.settings.title'));
    }
}
