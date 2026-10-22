@php $s = $settings; $hours = implode(', ', array_map(fn ($m) => (int) ($m / 60), (array) ($s['reminder_offsets_minutes'] ?? []))); @endphp
<x-admin.layout title="Settings">
    <x-admin.page-header title="Settings" subtitle="Business information and booking rules used by the app and the admin." />

    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf @method('PUT')
        {{-- Test accounts see the shared setup read-only. --}}
        <fieldset class="min-w-0 space-y-6" @cannot('change-setup') disabled @endcannot>
        <x-admin.card title="Business" subtitle="Shown to customers in the app. Enter official DGS details only.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-admin.input name="salon_name" label="Business name" :value="$s['salon_name']" required />
                <x-admin.input name="salon_phone" label="Phone" :value="$s['salon_phone']" />
                <x-admin.input name="salon_email" type="email" label="Email" :value="$s['salon_email']" />
                <x-admin.input name="salon_address" label="Main address" :value="$s['salon_address']" />
                <x-admin.input name="currency" label="Currency code" :value="$s['currency']" required maxlength="3" />
                <x-admin.input name="currency_symbol" label="Currency symbol" :value="$s['currency_symbol']" required />
                <x-admin.image-field name="logo" label="Logo" :current="$logoUrl" class="sm:col-span-2" />
            </div>
        </x-admin.card>

        <x-admin.card title="Booking rules">
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <x-admin.input name="booking_minimum_notice_hours" type="number" min="0" label="Minimum notice (hours)" :value="$s['booking_minimum_notice_hours']" required hint="Earliest bookable time from now." />
                <x-admin.input name="booking_maximum_days" type="number" min="1" label="Booking window (days)" :value="$s['booking_maximum_days']" required hint="How far ahead customers can book." />
                <x-admin.select name="booking_slot_interval_minutes" label="Slot interval" :options="[10 => '10 minutes', 15 => '15 minutes', 20 => '20 minutes', 30 => '30 minutes', 45 => '45 minutes', 60 => '60 minutes']" :value="$s['booking_slot_interval_minutes']" required />
                <x-admin.input name="cancellation_hours" type="number" min="0" label="Cancellation cut-off (hours)" :value="$s['cancellation_hours']" required hint="Customers can't cancel/reschedule later than this." />
                <x-admin.input name="reminder_offsets" label="Reminders (hours before)" :value="$hours" hint="Comma-separated, e.g. 24, 2" class="sm:col-span-2" />
                <x-admin.checkbox name="auto_confirm_appointments" label="Auto-confirm new bookings" :checked="$s['auto_confirm_appointments']" hint="Otherwise bookings stay pending until confirmed." />
                <x-admin.checkbox name="auto_approve_reviews" label="Publish reviews without moderation" :checked="$s['auto_approve_reviews']" />
            </div>
        </x-admin.card>

        <x-admin.card title="GCash" id="payments"
                      subtitle="Customers pay a downpayment by GCash after booking and upload the receipt; staff verify it under Payments. Unpaid bookings are released automatically.">
            <div class="grid gap-5 sm:grid-cols-2" x-data="{ enabled: @js((bool) old('payment_gcash_enabled', $s['payment_gcash_enabled'])), type: @js(old('payment_deposit_type', $s['payment_deposit_type'])) }">
                <p class="rounded-xl bg-gold-soft px-4 py-3 text-sm sm:col-span-2">
                    <span class="font-semibold">Per-service downpayments:</span> give a service its own fixed-amount GCash QR under
                    <a href="{{ route('services.index') }}" class="font-semibold underline">Services</a> → Edit → GCash downpayment (e.g. ₱50 for a ₱100 haircut).
                </p>
                <x-admin.input name="payment_gcash_account_name" label="GCash account name" :value="$s['payment_gcash_account_name']" hint="Shown on every payment screen, exactly as GCash shows it, e.g. DGS HAIR SALON." x-bind:required="enabled" />
                <x-admin.input name="payment_gcash_number" label="GCash number" :value="$s['payment_gcash_number']" placeholder="09XXXXXXXXX" inputmode="tel" hint="Customers can also Send Money to this number." />
                <x-admin.input name="payment_window_minutes" type="number" min="15" max="1440" label="Time to pay (minutes)" :value="$s['payment_window_minutes']"
                               hint="The slot is held this long, then released if no receipt arrives." />
                <x-admin.textarea name="payment_policy" label="Deposit policy" :value="$s['payment_policy']" rows="3" class="sm:col-span-2"
                                  hint="Shown before booking and on the payment screen, e.g. whether the downpayment is refundable." />

                <div class="border-t border-outline-variant/60 pt-4 sm:col-span-2">
                    <x-admin.checkbox name="payment_gcash_enabled" label="Also ask a deposit for services without their own QR" :checked="$s['payment_gcash_enabled']"
                                      hint="Uses the salon QR below (any amount) and this rule. Walk-ins and bookings made by staff never need a deposit." x-model="enabled" />
                </div>
                <div x-show="enabled" class="contents">
                    <x-admin.image-field name="payment_qr" label="Salon GCash QR (any amount)" :current="$paymentQrUrl" class="sm:col-span-2" />
                    <x-admin.select name="payment_deposit_type" label="Deposit" :options="['fixed' => 'Fixed amount', 'percent' => 'Percentage of the total', 'full' => 'Full payment']" :value="$s['payment_deposit_type']" x-model="type" />
                    <div x-show="type !== 'full'">
                        <x-admin.input name="payment_deposit_value" type="number" min="1" label="Deposit value" :value="$s['payment_deposit_value']"
                                       hint="Pesos for a fixed amount, or 1–100 for a percentage (rounded up to whole pesos). Never more than the booking total." />
                    </div>
                </div>
            </div>
        </x-admin.card>

        <x-admin.card title="Loyalty card & referrals" id="loyalty"
                      subtitle="Shown in the app under the customer's loyalty card. The referral rewards also fill in the amount when you complete a referral.">
            @php
                $rewardRows = collect(old('referral_rewards', $s['referral_rewards'] ?? []))
                    ->map(fn ($row) => ['service' => $row['service'] ?? '', 'amount' => isset($row['amount']) ? (string) (float) $row['amount'] : ''])->values()->all();
            @endphp
            <div class="grid gap-6 lg:grid-cols-2">
                <x-admin.textarea name="loyalty_card_perks" label="Card perks / discounts" :value="$s['loyalty_card_perks']" rows="7" maxlength="2000"
                                  hint="One perk per line, as printed on the card, e.g. Less ₱200 for rebond." />

                <div x-data="{ rows: @js($rewardRows) }">
                    <input type="hidden" name="referral_rewards_shown" value="1">
                    <p class="label">Direct referral rewards</p>
                    <p class="mb-3 text-xs text-on-surface-variant">Sent to the referrer's GCash when the person they referred gets the service. "Haircut" also covers services like "Men's Haircut".</p>
                    {{-- A row left blank is ignored on save; a half-filled one must be finished. --}}
                    <template x-for="(row, i) in rows" :key="i">
                        <div class="mb-2 grid grid-cols-12 gap-2">
                            <div class="col-span-6"><label class="sr-only" :for="`rr_service_${i}`">Service</label><input :id="`rr_service_${i}`" class="field" :name="`referral_rewards[${i}][service]`" x-model="row.service" placeholder="Service, e.g. Haircut" maxlength="60" :required="row.service !== '' || row.amount !== ''"></div>
                            <div class="relative col-span-4"><label class="sr-only" :for="`rr_amount_${i}`">Reward</label><span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm text-on-surface-variant">₱</span><input :id="`rr_amount_${i}`" class="field pl-8" type="number" step="0.01" min="1" max="100000" :name="`referral_rewards[${i}][amount]`" x-model="row.amount" placeholder="0" :required="row.service !== '' || row.amount !== ''"></div>
                            <div class="col-span-2"><button type="button" class="inline-flex min-h-11 w-full items-center justify-center rounded-full text-danger hover:bg-danger-container/60" @click="rows.splice(i, 1)" aria-label="Remove reward"><span class="material-symbols-outlined" aria-hidden="true">delete</span></button></div>
                        </div>
                    </template>
                    <p x-show="rows.length === 0" class="mb-3 text-sm text-on-surface-variant">No rewards listed. You can still enter any amount when you complete a referral.</p>
                    @error('referral_rewards')<p class="mb-2 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                    @error('referral_rewards.*.service')<p class="mb-2 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                    @error('referral_rewards.*.amount')<p class="mb-2 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                    <x-admin.button variant="tonal" icon="add" size="sm" class="mt-1" @click="rows.push({ service: '', amount: '' })">Add reward</x-admin.button>
                </div>
            </div>
        </x-admin.card>

        <x-admin.card title="Website & Google" id="website"
                      subtitle="Your public homepage at {{ url('/') }}. It shows your branches, services, prices, promotions and reviews from this admin.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-admin.input name="business_city" label="City / municipality" :value="$s['business_city']" placeholder="e.g. Carcar City" hint="Used in the page title and Google's location data." />
                <x-admin.input name="business_province" label="Province" :value="$s['business_province']" placeholder="e.g. Cebu" />
                <x-admin.input name="facebook_url" type="url" label="Facebook page link" :value="$s['facebook_url']" placeholder="https://www.facebook.com/…" hint="Links the website to your Facebook page for Google." class="sm:col-span-2" />
                <x-admin.textarea name="website_description" label="Search result description (optional)" :value="$s['website_description']" rows="2" maxlength="300"
                                  hint="The short text under your name on Google. Leave blank to build one from your services and city." class="sm:col-span-2" />
                <x-admin.input name="google_site_verification" label="Google Search Console code (optional)" :value="$s['google_site_verification']"
                               hint="From Search Console → HTML tag method: paste only the code inside content=&quot;…&quot;." class="sm:col-span-2" />
                <div class="sm:col-span-2 rounded-2xl bg-surface-container p-4">
                    <x-admin.checkbox name="website_indexing" label="Allow Google to show the website in search results" :checked="$s['website_indexing']"
                                      hint="Turn on only after your real branches, hours and services replace the sample data." />
                </div>
            </div>
        </x-admin.card>

        <x-admin.card title="Policies" subtitle="Shown in the app's Terms, Privacy and booking screens.">
            <div class="space-y-5">
                <x-admin.textarea name="cancellation_policy" label="Cancellation policy" :value="$s['cancellation_policy']" rows="3" />
                <x-admin.textarea name="terms_and_conditions" label="Terms & conditions" :value="$s['terms_and_conditions']" rows="6" />
                <x-admin.textarea name="privacy_policy" label="Privacy policy" :value="$s['privacy_policy']" rows="6" />
            </div>
        </x-admin.card>

        <div class="flex justify-end">@can('change-setup')<x-admin.button type="submit" icon="save">Save settings</x-admin.button> @endcan</div>
        </fieldset>
    </form>
</x-admin.layout>
