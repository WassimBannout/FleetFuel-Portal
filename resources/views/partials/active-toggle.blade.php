{{-- Expects $action (URL of the PATCH .../active route), $active (bool) and $name (record label). --}}
<form method="POST" action="{{ $action }}" class="d-inline"
      @if ($active) data-confirm="Deactivate {{ $name }}? Its history is kept." @endif>
    @csrf
    @method('PATCH')
    <input type="hidden" name="is_active" value="{{ $active ? 0 : 1 }}">
    <button type="submit" @class(['btn btn-sm', 'btn-outline-secondary' => $active, 'btn-outline-success' => ! $active])>
        {{ $active ? 'Deactivate' : 'Activate' }}<span class="visually-hidden"> {{ $name }}</span>
    </button>
</form>
