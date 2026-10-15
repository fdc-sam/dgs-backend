@props(['name', 'label' => null, 'options' => [], 'value' => null, 'placeholder' => null, 'hint' => null, 'required' => false, 'multiple' => false])
@php
    $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name));
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $error = $errors->first($key) ?: $errors->first($key.'.*');
    $selected = collect(old($key, $value))->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : (string) $v)->all();
@endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@endif</label>
    @endif
    <select id="{{ $id }}" name="{{ $name }}" @if ($required) required @endif @if ($multiple) multiple @endif
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}_error" @endif
            {{ $attributes->except(['class', 'id'])->class(['field', 'field-error' => $error, 'min-h-32' => $multiple]) }}>
        @if ($placeholder !== null && ! $multiple)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected(in_array((string) $optionValue, $selected, true))>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if ($error)
        <p id="{{ $id }}_error" class="mt-1.5 text-xs font-medium text-danger">{{ $error }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-on-surface-variant">{{ $hint }}</p>
    @endif
</div>
