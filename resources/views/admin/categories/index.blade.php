<x-admin.layout title="Categories">
    <x-admin.page-header title="Service categories" subtitle="Group services in the app's catalog.">
        <x-slot:actions><x-admin.button :href="route('categories.create')" icon="add">New category</x-admin.button></x-slot:actions>
    </x-admin.page-header>

    <x-admin.card :padding="false">
        @if ($categories->isEmpty())
            <x-admin.empty-state icon="category" title="No categories yet" message="Create a category, then add services to it.">
                <x-admin.button :href="route('categories.create')" icon="add">New category</x-admin.button>
            </x-admin.empty-state>
        @else
            <x-admin.table :headers="['Category', 'Services', 'Order', 'Status', '>Actions']">
                @foreach ($categories as $category)
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                @if ($category->image)<img src="{{ \App\Support\Fmt::url($category->image) }}" alt="" class="h-10 w-10 rounded-xl object-cover">@else<span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gold-soft text-primary"><span class="material-symbols-outlined" aria-hidden="true">category</span></span>@endif
                                <div><p class="font-medium">{{ $category->name }}</p><p class="text-xs text-on-surface-variant">{{ $category->slug }}</p></div>
                            </div>
                        </td>
                        <td>{{ $category->services_count }}</td>
                        <td>{{ $category->sort_order }}</td>
                        <td><x-admin.badge :tone="$category->status->value === 'active' ? 'success' : 'neutral'">{{ $category->status->label() }}</x-admin.badge></td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                <x-admin.button :href="route('categories.edit', $category)" variant="text" size="sm">Edit</x-admin.button>
                                <form method="POST" action="{{ route('categories.destroy', $category) }}" data-confirm="Delete {{ $category->name }}?">
                                    @csrf @method('DELETE')
                                    <x-admin.button type="submit" variant="danger-text" size="sm">Delete</x-admin.button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$categories" />
        @endif
    </x-admin.card>
</x-admin.layout>
