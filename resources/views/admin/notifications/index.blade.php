<x-admin.layout title="Notifications">
    <x-admin.page-header title="Notifications" subtitle="Booking alerts for you, and announcements to customers.">
        <x-slot:actions>
            <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<x-admin.button type="submit" variant="text" icon="done_all">Mark all read</x-admin.button></form>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-admin.card title="Your notifications" :padding="false" @class(['lg:col-span-2' => auth()->user()->can('manage'), 'lg:col-span-3' => ! auth()->user()->can('manage')])>
            @forelse ($notifications as $n)
                <form method="POST" action="{{ route('notifications.read', $n->id) }}">
                    @csrf
                    <button type="submit" @class(['flex w-full gap-4 border-b border-outline-variant/50 px-5 py-4 text-left last:border-0 hover:bg-surface-container/60', 'bg-gold-soft/40' => ! $n->read_at])>
                        <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-container text-on-primary-container">
                            <span class="material-symbols-outlined" aria-hidden="true">{{ str_starts_with($n->type, 'PAYMENT') ? 'payments' : (str_contains($n->type, 'CANCEL') ? 'event_busy' : (str_contains($n->type, 'RESCHED') ? 'event_repeat' : 'event_available')) }}</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2"><span class="font-medium">{{ $n->data['title'] ?? 'Notification' }}</span>@unless ($n->read_at)<span class="h-2 w-2 rounded-full bg-primary" aria-label="Unread"></span>@endunless</span>
                            <span class="block text-sm text-on-surface-variant">{{ $n->data['body'] ?? '' }}</span>
                            <span class="block text-xs text-outline">{{ $n->created_at->diffForHumans() }}</span>
                        </span>
                    </button>
                </form>
            @empty
                <x-admin.empty-state icon="notifications" title="You're all caught up" message="New bookings, cancellations and reschedules will show up here." />
            @endforelse
            <x-admin.pagination :paginator="$notifications" />
        </x-admin.card>

        @can('manage')
            <x-admin.card title="Send to customers" subtitle="Push + in-app notification to every active customer.">
                <form method="POST" action="{{ route('notifications.broadcast') }}" class="space-y-4" x-data="{ type: @js(old('type', 'GENERAL')) }"
                      data-confirm="Send this notification to all active customers?">
                    @csrf
                    <x-admin.select name="type" label="Type" :options="['GENERAL' => 'Announcement', 'PROMOTION' => 'Promotion']" value="GENERAL" x-model="type" />
                    <div x-show="type === 'PROMOTION'">
                        <x-admin.select name="promotion_id" label="Promotion (opens on tap)" :options="$promotions" placeholder="Select a live promotion" />
                    </div>
                    <x-admin.input name="title" label="Title" maxlength="80" required />
                    <x-admin.textarea name="body" label="Message" rows="3" maxlength="240" required />
                    <x-admin.button type="submit" icon="send" class="w-full">Send notification</x-admin.button>
                </form>
            </x-admin.card>
        @endcan
    </div>
</x-admin.layout>
