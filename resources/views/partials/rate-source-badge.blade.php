{{-- Expects $source (App\Enums\RateSource). Where a USD/LBP rate came from, in words, not colour alone. --}}
@php
    $rateSourceClass = match ($source) {
        App\Enums\RateSource::Provider => 'text-bg-primary',
        App\Enums\RateSource::Manual => 'text-bg-warning',
        App\Enums\RateSource::Fixture => 'text-bg-secondary',
    };
@endphp
<span class="badge {{ $rateSourceClass }}">{{ $source->label() }}</span>
