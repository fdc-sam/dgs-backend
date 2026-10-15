@foreach (['success' => ['success', 'check_circle'], 'error' => ['danger', 'error'], 'warning' => ['warning', 'warning']] as $key => [$tone, $icon])
    @if (session($key))
        <div x-data="{ show: true }" x-show="show" x-transition role="status"
             @class([
                'mb-6 flex items-start gap-3 rounded-2xl px-4 py-3 text-sm',
                'bg-success-container text-success' => $tone === 'success',
                'bg-danger-container text-danger' => $tone === 'danger',
                'bg-warning-container text-warning' => $tone === 'warning',
             ])>
            <span class="material-symbols-outlined icon-fill" aria-hidden="true">{{ $icon }}</span>
            <p class="flex-1 pt-0.5 font-medium">{{ session($key) }}</p>
            <button type="button" @click="show = false" class="-m-1 rounded-full p-1 hover:bg-black/5" aria-label="Dismiss">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </div>
    @endif
@endforeach
@if ($errors->any() && ! session('suppress_error_summary'))
    <div class="mb-6 rounded-2xl bg-danger-container px-4 py-3 text-sm text-danger" role="alert">
        <p class="font-semibold">Please fix the highlighted fields.</p>
    </div>
@endif
