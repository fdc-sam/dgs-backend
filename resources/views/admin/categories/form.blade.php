@php $editing = $category->exists; @endphp
<x-admin.layout :title="$editing ? 'Edit category' : 'New category'">
    <x-admin.page-header :title="$editing ? 'Edit '.$category->name : 'New category'" :back="route('categories.index')" />

    <form method="POST" action="{{ $editing ? route('categories.update', $category) : route('categories.store') }}" enctype="multipart/form-data" class="max-w-3xl">
        @csrf @if ($editing) @method('PUT') @endif
        <x-admin.card>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-admin.input name="name" label="Name" :value="$category->name" required class="sm:col-span-2" />
                <x-admin.input name="slug" label="URL slug" :value="$category->slug" hint="Leave blank to generate from the name." />
                <x-admin.input name="sort_order" type="number" min="0" label="Display order" :value="$category->sort_order ?? 0" />
                <x-admin.textarea name="description" label="Description" :value="$category->description" rows="3" class="sm:col-span-2" />
                <x-admin.select name="status" label="Status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="$category->status?->value ?? 'active'" required />
                <x-admin.image-field :current="\App\Support\Fmt::url($category->image)" class="sm:col-span-2" />
            </div>
        </x-admin.card>
        <div class="mt-6 flex justify-end gap-2">
            <x-admin.button :href="route('categories.index')" variant="text">Cancel</x-admin.button>
            <x-admin.button type="submit" icon="save">{{ $editing ? 'Save changes' : 'Create category' }}</x-admin.button>
        </div>
    </form>
</x-admin.layout>
