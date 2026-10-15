@props(['name', 'label', 'checked' => false, 'value' => '1', 'hint' => null])
@php
    $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name).'_'.$value);
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $isChecked = str_ends_with($name, '[]')
        ? in_array((string) $value, array_map('strval', (array) old($key, $checked ? [$value] : [])), true)
        : (bool) old($key, $checked);
@endphp
<label for="{{ $id }}" {{ $attributes->only('class')->class(['flex min-h-11 cursor-pointer items-start gap-3 rounded-xl py-2']) }}>
    @unless (str_ends_with($name, '[]'))<input type="hidden" name="{{ $name }}" value="0">@endunless
    <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked($isChecked)
           class="mt-0.5 h-5 w-5 rounded border-outline text-primary accent-[var(--color-primary)] focus:ring-primary/30" {{ $attributes->except(['class', 'id']) }}>
    <span>
        <span class="block text-sm font-medium">{{ $label }}</span>
        @if ($hint)<span class="block text-xs text-on-surface-variant">{{ $hint }}</span>@endif
    </span>
</label>
