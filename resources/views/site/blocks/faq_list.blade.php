<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap">
        @if ($b['heading'])<div class="section-head"><h2>{{ $b['heading'] }}</h2></div>@endif
        <div class="faq">
            @if ($b['grouped'])
                @foreach ($b['faqs']->groupBy('category') as $category => $items)
                    <div class="faq__group">
                        <h3>{{ __('site.faq.categories.'.$category) }}</h3>
                        @foreach ($items as $faq)
                            <details><summary>{{ $faq->question }}</summary><div class="faq__answer prose">{!! \App\Modules\Cms\Services\SafeMarkdown::render($faq->answer) !!}</div></details>
                        @endforeach
                    </div>
                @endforeach
            @else
                @foreach ($b['faqs'] as $faq)
                    <details><summary>{{ $faq->question }}</summary><div class="faq__answer prose">{!! \App\Modules\Cms\Services\SafeMarkdown::render($faq->answer) !!}</div></details>
                @endforeach
            @endif
        </div>
    </div>
</section>
