@props(['name' => 'image', 'label' => 'Image', 'current' => null])
@php $error = $errors->first($name); @endphp
<div x-data="{ preview: @js($current) }" {{ $attributes->only('class') }}>
    <span class="label">{{ $label }}</span>
    <div class="flex items-center gap-4">
        <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-outline-variant bg-surface-container">
            <template x-if="preview"><img :src="preview" alt="Selected image preview" class="h-full w-full object-cover"></template>
            <template x-if="!preview"><span class="material-symbols-outlined text-3xl text-outline" aria-hidden="true">image</span></template>
        </div>
        <div>
            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-outline px-5 text-sm font-medium text-primary hover:bg-primary/5">
                <span class="material-symbols-outlined" aria-hidden="true">upload</span> Choose image
                <input type="file" name="{{ $name }}" accept="image/jpeg,image/png,image/webp" class="sr-only"
                       @change="const f = $event.target.files[0]; if (f) preview = URL.createObjectURL(f)">
            </label>
            <p class="mt-1.5 text-xs text-on-surface-variant">JPG, PNG or WebP, up to 4 MB. Large images are resized automatically.</p>
            @if ($error)<p class="mt-1 text-xs font-medium text-danger">{{ $error }}</p>@endif
        </div>
    </div>
</div>
