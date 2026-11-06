{{-- An on/off switch: a checkbox drawn as a track and knob (grey off, gold on). Sends 0 when off (like x-admin.checkbox), 1 when on. --}}
@props(['name', 'label', 'checked' => false])
@php
    $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name));
    $isChecked = (bool) old(str_replace(['[', ']'], ['.', ''], $name), $checked);
@endphp
<label for="{{ $id }}" {{ $attributes->only('class')->class(['relative inline-flex min-h-11 cursor-pointer select-none items-center gap-3']) }}>
    <span class="text-sm font-medium">{{ $label }}</span>
    <input type="hidden" name="{{ $name }}" value="0">
    <input id="{{ $id }}" type="checkbox" role="switch" name="{{ $name }}" value="1" @checked($isChecked)
           class="peer sr-only" {{ $attributes->except(['class', 'id']) }}>
    <span aria-hidden="true"
          class="relative inline-block h-6 w-11 shrink-0 rounded-full bg-on-surface-variant/40 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:bg-gold peer-checked:after:translate-x-5 peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40 peer-focus-visible:ring-offset-2 peer-disabled:cursor-not-allowed peer-disabled:opacity-60"></span>
</label>
