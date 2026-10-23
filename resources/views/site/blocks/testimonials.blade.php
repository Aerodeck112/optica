<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="wrap">
        @if ($b['heading'])<div class="section-head"><h2>{{ $b['heading'] }}</h2></div>@endif
        <div class="reviews">
            @foreach ($b['reviews'] as $review)
                <figure class="review">
                    @if ($review->rating)<p class="stars" aria-label="{{ $review->rating }} din 5">{{ str_repeat('★', $review->rating) }}</p>@endif
                    <blockquote><p>{{ $review->body }}</p></blockquote>
                    <figcaption>
                        <footer>{{ $review->author_name }} —
                            @if ($review->source_url)<a href="{{ $review->source_url }}" rel="noopener" target="_blank">{{ ucfirst($review->source) }}</a>@else{{ ucfirst($review->source) }}@endif
                            @if ($review->reviewed_on), {{ $review->reviewed_on->translatedFormat('F Y') }}@endif
                        </footer>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
