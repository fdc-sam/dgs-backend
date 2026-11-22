@props(['name' => 'image', 'label' => 'Image', 'current' => null, 'previewable' => false])
@php $error = $errors->first($name); @endphp
<div x-data="{ preview: @js($current), previewOpen: false }" {{ $attributes->only('class') }}>
    <span class="label">{{ $label }}</span>
    <div class="flex items-center gap-4">
        @if ($previewable)
        <button type="button" class="group relative flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-outline-variant bg-surface-container focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                :disabled="!preview" :aria-label="preview ? 'View {{ addslashes($label) }} full screen' : 'No {{ addslashes(strtolower($label)) }} uploaded'"
                @click="if (preview) { previewOpen = true; $nextTick(() => $refs.imagePreview.showModal()) }">
            <template x-if="preview"><img :src="preview" alt="Selected image preview" class="h-full w-full object-cover"></template>
            <template x-if="!preview"><span class="material-symbols-outlined text-3xl text-outline" aria-hidden="true">image</span></template>
            <template x-if="preview"><span class="absolute inset-0 flex items-center justify-center bg-black/45 text-white opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100"><span class="material-symbols-outlined" aria-hidden="true">zoom_in</span><span class="sr-only">View image full screen</span></span></template>
        </button>
        <dialog x-ref="imagePreview" @click="if ($event.target === $refs.imagePreview) { $refs.imagePreview.close(); previewOpen = false }"
                @close="previewOpen = false" class="m-auto max-h-[92dvh] max-w-[92vw] overflow-visible rounded-2xl bg-transparent p-0 backdrop:bg-black/85">
            <div class="relative flex max-h-[92dvh] max-w-[92vw] items-center justify-center">
                <img :src="preview" alt="{{ $label }} full-size preview" class="max-h-[88dvh] max-w-[90vw] rounded-xl bg-white object-contain shadow-2xl">
                <button type="button" @click="$refs.imagePreview.close(); previewOpen = false" aria-label="Close image preview"
                        class="absolute -right-3 -top-3 inline-flex h-11 w-11 items-center justify-center rounded-full bg-white text-on-surface shadow-lg hover:bg-surface-container">
                    <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </button>
            </div>
        </dialog>
        @else
        <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-outline-variant bg-surface-container">
            <template x-if="preview"><img :src="preview" alt="Selected image preview" class="h-full w-full object-cover"></template>
            <template x-if="!preview"><span class="material-symbols-outlined text-3xl text-outline" aria-hidden="true">image</span></template>
        </div>
        @endif
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
