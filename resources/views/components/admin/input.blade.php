@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'hint' => null, 'required' => false, 'prefix' => null])
@php
    $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name));
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $error = $errors->first($key);
@endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@endif</label>
    @endif
    <div class="relative">
        @if ($prefix)
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm text-on-surface-variant">{{ $prefix }}</span>
        @endif
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
               @if ($type !== 'password' && $type !== 'file') value="{{ old($key, $value) }}" @endif
               @if ($required) required @endif
               @if ($error) aria-invalid="true" aria-describedby="{{ $id }}_error" @elseif ($hint) aria-describedby="{{ $id }}_hint" @endif
               {{ $attributes->except(['class', 'id'])->class(['field', 'field-error' => $error, 'pl-8' => $prefix]) }}>
    </div>
    @if ($error)
        <p id="{{ $id }}_error" class="mt-1.5 text-xs font-medium text-danger">{{ $error }}</p>
    @elseif ($hint)
        <p id="{{ $id }}_hint" class="mt-1.5 text-xs text-on-surface-variant">{{ $hint }}</p>
    @endif
</div>
