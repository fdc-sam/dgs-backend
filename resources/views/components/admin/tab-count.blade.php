@props(['count' => 0])
{{-- The number on a status tab: a gold pill like the sidebar badges when there is something there, a quiet 0 otherwise. --}}
@php $count = (int) $count; @endphp
@if ($count > 0)
    <span {{ $attributes->class('rounded-full bg-gold px-2 py-0.5 text-xs font-semibold text-on-surface') }}>{{ $count > 99 ? '99+' : $count }}</span>
@else
    <span {{ $attributes->class('text-xs opacity-70') }}>0</span>
@endif
