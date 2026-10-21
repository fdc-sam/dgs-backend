<x-admin.layout title="Achievements">
    <x-admin.page-header title="Salon achievements" subtitle="Share milestones and memories with customers on the app home page.">
        @can('change-setup')<x-slot:actions><x-admin.button :href="route('achievements.create')" icon="add">Add achievement</x-admin.button></x-slot:actions> @endcan
    </x-admin.page-header>

    <x-admin.card :padding="false">
        @if ($achievements->isEmpty())
            <x-admin.empty-state icon="emoji_events" title="No achievements yet" message="Add a photo, title, and story to feature it above offers in the app.">
                @can('change-setup')<x-admin.button :href="route('achievements.create')" icon="add">Add achievement</x-admin.button> @endcan
            </x-admin.empty-state>
        @else
            <x-admin.table :headers="['Achievement', 'Added', '>Actions']">
                @foreach ($achievements as $achievement)
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                <img src="{{ \App\Support\Fmt::url($achievement->image) }}" alt="" class="h-14 w-20 rounded-lg object-cover">
                                <div class="min-w-0"><p class="font-medium">{{ $achievement->title }}</p><p class="line-clamp-2 max-w-2xl text-sm text-on-surface-variant">{{ $achievement->description }}</p></div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $achievement->created_at->format('M j, Y') }}</td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                <x-admin.button :href="route('achievements.edit', $achievement)" variant="text" size="sm">@can('change-setup') Edit @else View @endcan</x-admin.button>
                                @can('change-setup')<form method="POST" action="{{ route('achievements.destroy', $achievement) }}" data-confirm="Remove {{ $achievement->title }} from the app?">
                                    @csrf @method('DELETE')
                                    <x-admin.button type="submit" variant="danger-text" size="sm">Delete</x-admin.button>
                                </form> @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$achievements" />
        @endif
    </x-admin.card>
</x-admin.layout>
