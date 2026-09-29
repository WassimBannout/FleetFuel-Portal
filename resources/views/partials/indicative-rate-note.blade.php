{{-- Expects $rate (?ExchangeRate): the USD/LBP rate in effect now. --}}
@use('App\Enums\RateSource')
@use('App\Support\Display')
@if ($rate)
    <p class="small text-body-secondary mb-1">
        Indicative USD uses the {{ strtolower($rate->source->label()) }} rate of
        {{ Display::decimal($rate->rate, 8) }} LBP per USD, effective {{ Display::businessTime($rate->effective_at) }} (Beirut time).
        Purchases store their own LBP and USD amounts when they happen.
    </p>
    @if ($rate->source === RateSource::Provider)
        @include('partials.rate-attribution')
    @endif
@else
    <p class="small text-warning-emphasis mb-1">No valid USD/LBP rate right now, so indicative USD prices are not shown.</p>
@endif
