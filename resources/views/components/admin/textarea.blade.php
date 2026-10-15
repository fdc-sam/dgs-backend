@props(['name', 'label' => null, 'value' => null, 'hint' => null, 'required' => false, 'rows' => 4])
@php
    $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name));
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $error = $errors->first($key);
@endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@endif</label>
    @endif
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" @if ($required) required @endif
              @if ($error) aria-invalid="true" aria-describedby="{{ $id }}_error" @endif
              {{ $attributes->except(['class', 'id'])->class(['field', 'field-error' => $error]) }}>{{ old($key, $value) }}</textarea>
    @if ($error)
        <p id="{{ $id }}_error" class="mt-1.5 text-xs font-medium text-danger">{{ $error }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-on-surface-variant">{{ $hint }}</p>
    @endif
</div>
