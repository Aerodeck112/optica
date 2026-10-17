@props(['status'])
@php
    $class = match ($status) {
        \App\Enums\AppointmentStatus::Confirmed, \App\Enums\AppointmentStatus::Completed => 'badge-ok',
        \App\Enums\AppointmentStatus::Pending, \App\Enums\AppointmentStatus::Held => 'badge-warn',
        \App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow => 'badge-bad',
        \App\Enums\AppointmentStatus::Arrived, \App\Enums\AppointmentStatus::InConsultation => 'badge-info',
        default => 'badge-neutral',
    };
@endphp
{{-- Colour is always paired with the text label (CAL-02). --}}
<span class="{{ $class }}">{{ $status->label() }}</span>
