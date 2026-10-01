@if (session('status'))
    <div class="alert alert-info" role="status">{{ session('status') }}</div>
@endif
@error('rule')
    <div class="alert alert-danger" role="alert">{{ $message }}</div>
@enderror
