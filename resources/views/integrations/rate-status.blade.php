{{-- Expects $rate (the row), $current (?ExchangeRate in effect) and $now. Words, not colour alone. --}}
@if ($current && $rate->is($current))
    <span class="badge text-bg-success">In effect</span>
@elseif ($rate->effective_at->isAfter($now))
    <span class="badge text-bg-info">Scheduled</span>
@elseif ($rate->isEligibleAt($now))
    <span class="badge text-bg-light border">Valid, not in use</span>
@else
    <span class="badge text-bg-secondary">Expired</span>
@endif
