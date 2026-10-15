@php $editing = $achievement->exists; @endphp
<x-admin.layout :title="$editing ? 'Edit achievement' : 'Add achievement'">
    <x-admin.page-header :title="$editing ? 'Edit achievement' : 'Add achievement'" :back="route('achievements.index')"
                         subtitle="Achievements appear on the app home page above Offers for you as soon as you save.">
    </x-admin.page-header>

    <form method="POST" action="{{ $editing ? route('achievements.update', $achievement) : route('achievements.store') }}" enctype="multipart/form-data" class="max-w-3xl">
        @csrf @if ($editing) @method('PUT') @endif
        <x-admin.card title="Achievement details" subtitle="Use a landscape photo and a short story about this salon milestone.">
            <div class="grid gap-5">
                <x-admin.image-field label="Achievement image (landscape works best)" :current="$achievement->image ? \App\Support\Fmt::url($achievement->image) : null" />
                <x-admin.input name="title" label="Title" :value="$achievement->title" required maxlength="160" />
                <x-admin.textarea name="description" label="Description" :value="$achievement->description" rows="5" required maxlength="5000" hint="Tell customers what this achievement means to the salon." />
            </div>
        </x-admin.card>
        <div class="mt-6 flex justify-end gap-2">
            <x-admin.button :href="route('achievements.index')" variant="text">Cancel</x-admin.button>
            <x-admin.button type="submit" icon="save">{{ $editing ? 'Save changes' : 'Add achievement' }}</x-admin.button>
        </div>
    </form>
</x-admin.layout>
