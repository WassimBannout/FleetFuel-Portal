{{-- Expects $status (App\Enums\CardStatus). --}}
@switch ($status)
    @case (App\Enums\CardStatus::Active)
        <span class="badge text-bg-success">Active</span>
        @break
    @case (App\Enums\CardStatus::Blocked)
        <span class="badge text-bg-danger">Blocked</span>
        @break
    @default
        <span class="badge text-bg-secondary">Archived</span>
@endswitch
