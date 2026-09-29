@props(['name', 'label', 'options' => [], 'selected' => null, 'placeholder' => null, 'help' => null, 'required' => false])
@php
    $id = $attributes->get('id') ?? $name;
    $hasError = $errors->has($name);
    $current = (string) old($name, $selected);
    $describedBy = trim(($help ? $id.'-help ' : '').($hasError ? $id.'-error' : ''));
@endphp
<div class="mb-3">
    <label for="{{ $id }}" class="form-label">{{ $label }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@endif</label>
    <select id="{{ $id }}" name="{{ $name }}"
            {{ $attributes->except('id')->class(['form-select', 'is-invalid' => $hasError]) }}
            @required($required)
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected($current === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($help)
        <div id="{{ $id }}-help" class="form-text">{{ $help }}</div>
    @endif
    @error($name)
        <div id="{{ $id }}-error" class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
