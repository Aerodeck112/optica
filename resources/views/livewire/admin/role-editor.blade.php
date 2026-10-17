<div class="max-w-5xl">
    <form wire:submit="save" data-preserve class="space-y-10">
        <section class="space-y-5" aria-labelledby="sec-name">
            <h2 id="sec-name" class="text-base font-semibold">{{ __('users.roles.name') }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                @foreach (config('optica.locales') as $l)
                    <x-admin.text model="name.{{ $l }}" :label="__('crm.languages.'.$l)" :required="$l === 'ro'" />
                @endforeach
            </div>
        </section>

        <section aria-labelledby="sec-perms">
            <h2 id="sec-perms" class="text-base font-semibold">{{ __('users.roles.permissions') }}</h2>
            <p class="mt-1 max-w-prose text-sm text-muted">{{ __('users.roles.permissions_hint') }}</p>
            @error('permissions')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                @foreach ($catalog as $module => $actions)
                    <fieldset class="panel p-4" wire:key="mod-{{ $module }}">
                        <legend class="sr-only">{{ __('permissions.modules.'.$module) }}</legend>
                        <p class="mb-2 text-sm font-semibold text-heading" aria-hidden="true">{{ __('permissions.modules.'.$module) }}</p>
                        <div class="space-y-1">
                            @foreach ($actions as $action)
                                @php($key = $module.'.'.$action)
                                <label class="flex min-h-10 items-center gap-3 text-sm">
                                    <input type="checkbox" value="{{ $key }}" wire:model="permissions" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
                                    <span>{{ __('permissions.actions.'.$key) }}</span>
                                    @if (in_array($key, $clinical, true))<span class="badge-warn">{{ __('users.roles.clinical') }}</span>@endif
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3 border-t border-line pt-6">
            <button type="submit" class="btn-primary">{{ __('users.roles.save') }}</button>
            <a href="{{ route('admin.roles.index') }}" wire:navigate class="btn-link ml-auto text-sm">{{ __('crm.actions.back_to_list') }}</a>
        </div>
    </form>

    @if ($role && ! $role->is_system)
        <x-admin.delete-zone :label="__('users.roles.delete')" :warning="__('users.roles.delete_warning')" />
    @endif
</div>
