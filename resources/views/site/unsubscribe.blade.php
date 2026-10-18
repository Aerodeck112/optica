<x-layouts.site :title="__('messages.unsubscribe.title')" noindex>
    <section class="bg-paper">
        <div class="mx-auto max-w-xl px-4 py-16 sm:px-6">
            <h1 class="text-3xl font-light text-heading">{{ __('messages.unsubscribe.title') }}</h1>
            @if ($done)
                <p class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-ok" role="status">{{ __('messages.unsubscribe.done', ['channel' => __('messages.channels.'.$message->channel)]) }}</p>
            @else
                <p class="mt-4 leading-relaxed">{{ __('messages.unsubscribe.question', ['channel' => __('messages.channels.'.$message->channel)]) }}</p>
                <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6">
                    @csrf
                    <button type="submit" class="btn-primary">{{ __('messages.unsubscribe.confirm') }}</button>
                </form>
            @endif
            <p class="mt-6 text-sm text-muted">{{ __('messages.unsubscribe.operational_note') }}</p>
        </div>
    </section>
</x-layouts.site>
