{{-- Expects $active (bool). Status is shown in words, not colour alone. --}}
@if ($active)
    <span class="badge text-bg-success">Active</span>
@else
    <span class="badge text-bg-secondary">Inactive</span>
@endif
