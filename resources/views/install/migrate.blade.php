<x-layouts.install :step="$step" :steps="$steps">
    <p class="text-sm">{{ __('install.migrate.intro') }}</p>
    <form method="POST" action="{{ route('install.migrate') }}" class="mt-6" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <button type="submit" class="btn-primary" :disabled="busy">{{ __('install.migrate.submit') }}</button>
    </form>
</x-layouts.install>
