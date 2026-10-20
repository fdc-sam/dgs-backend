<x-site.page :s="$s" title="Delete your account" :logo-url="$logoUrl">
    @if (session('deleted'))
        <div class="mt-6 rounded-2xl border border-outline-variant bg-white p-6" role="status">
            <p class="text-lg font-semibold">Your account has been deleted.</p>
            <p class="mt-2 text-on-surface-variant">You have been signed out of the {{ $s['salon_name'] }} app on all your devices. Thank you for visiting us.</p>
        </div>
    @else
        <p class="mt-4 leading-relaxed text-on-surface-variant">
            You can delete your {{ $s['salon_name'] }} account and its personal data at any time.
            The quickest way is in the app: <strong class="text-on-surface">Profile → Settings → Delete account</strong>.
            If you no longer have the app, sign in below to delete your account here.
        </p>

        <section class="mt-8 rounded-2xl border border-outline-variant bg-white p-6">
            <h2 class="text-lg font-semibold">What happens when you delete your account</h2>
            <ul class="mt-3 list-disc space-y-1.5 pl-5 text-on-surface-variant">
                <li>Your upcoming appointments are cancelled.</li>
                <li>Your profile details, photo, favorites, reviews, notifications and QR code are deleted, and your name, email address and mobile number are removed from our records.</li>
                <li>Records of past appointments and payments are kept without your name or contact details, for accounting and tax purposes.</li>
                <li>Deletion happens immediately and cannot be undone. You can register again later with the same email.</li>
            </ul>
        </section>

        <form method="POST" action="{{ route('account-deletion.store') }}" class="mt-8 space-y-5 rounded-2xl border border-outline-variant bg-white p-6">
            @csrf
            <x-admin.input name="email" type="email" label="Email" autocomplete="username" required />
            <x-admin.input name="password" type="password" label="Password" autocomplete="current-password" required />
            <x-admin.checkbox name="confirm" label="I understand that my account will be deleted permanently." />
            @error('confirm')<p class="-mt-3 text-xs font-medium text-danger">{{ $message }}</p>@enderror
            <x-admin.button type="submit" variant="danger" class="w-full">Delete my account</x-admin.button>
        </form>

        <p class="mt-6 text-sm text-on-surface-variant">Forgot your password? Reset it in the app first, or contact the salon. See our <a href="{{ route('privacy') }}" class="underline">privacy policy</a> for details.</p>
    @endif
</x-site.page>
