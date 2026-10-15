@props(['status'])
<x-admin.badge :tone="$status->color()">{{ $status->label() }}</x-admin.badge>
