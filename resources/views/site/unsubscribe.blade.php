@extends('site.layout')

@section('content')
<section class="section">
    <div class="wrap--narrow" style="max-width:34rem">
        @if ($done)
            <h1>Te-ai dezabonat</h1>
            <p class="muted mt-4">Nu vei mai primi oferte și noutăți prin {{ $channel === 'sms' ? 'SMS' : 'e-mail' }}. Mesajele despre rezervările tale (confirmări, remindere) continuă să ajungă la tine.</p>
        @else
            <h1>Dezabonare</h1>
            <p class="muted mt-4">Confirmă că nu mai vrei să primești oferte și noutăți prin {{ $channel === 'sms' ? 'SMS' : 'e-mail' }}.</p>
            <form method="post" action="{{ request()->fullUrl() }}" class="mt-6">
                @csrf
                <button class="btn btn--primary" type="submit">Mă dezabonez</button>
            </form>
        @endif
    </div>
</section>
@endsection
