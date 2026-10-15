@php $selectedBranch = old('branch_id', $branches->first()?->id); @endphp
<x-admin.layout title="Record walk-in">
    <x-admin.page-header title="Record a walk-in" :back="route('walk-ins.index')" subtitle="Choose the services being provided and record the guest visit. No account or appointment is created." />

    <form method="POST" action="{{ route('walk-ins.store') }}" x-data="walkInWizard()" @submit="if (!canSubmit()) $event.preventDefault()">
        @csrf
        <div class="relative mb-8" aria-label="Walk-in progress">
            <div aria-hidden="true" style="position: absolute; left: 16.6667%; right: 16.6667%; top: 14px; height: 3px; border-radius: 9999px; background: #e5e5e5;">
                <div style="height: 100%; border-radius: 9999px; background: #38b21e; transition: width 300ms ease;" :style="`width: ${(step / (steps.length - 1)) * 100}%`"></div>
            </div>
            <div class="relative flex" style="display: flex; justify-content: space-between;">
                <template x-for="(label, index) in steps" :key="label">
                    <div class="flex flex-1 flex-col items-center text-center" style="flex: 1; display: flex; flex-direction: column; align-items: center; text-align: center;">
                        <button type="button" @click="if (index < step) step = index" :disabled="index > step"
                                class="relative z-10 flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold disabled:cursor-default"
                                :style="index < step ? 'box-sizing: border-box; background: #38b21e; border: 2px solid #38b21e; color: #fff' : index === step ? 'box-sizing: border-box; background: #7a5900; border: 2px solid #7a5900; color: #fff' : 'box-sizing: border-box; background: #fff; border: 2px solid #d6d6d6; color: #666'"
                                :aria-current="step === index ? 'step' : null" :aria-label="`${index + 1}. ${label}`">
                            <svg x-show="index < step" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="m5 12 4 4L19 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            <span x-show="index >= step" x-text="index + 1"></span>
                        </button>
                        <span class="mt-2 text-xs font-medium text-on-surface-variant" x-text="label"></span>
                    </div>
                </template>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-admin.card>
                    <section x-show="step === 0" x-cloak>
                        <h2 class="text-lg font-semibold">Choose branch and services</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">Select the branch and all services the guest is receiving now.</p>
                        <div class="mt-5">
                            <label for="walkInBranch" class="label">Branch</label>
                            <select id="walkInBranch" name="branch_id" class="field" required x-model="branchId" @change="updateAvailability()">
                                <option value="">Select branch</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected($selectedBranch === $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                            @error('branch_id')<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div id="walkInServices" class="mt-5 grid gap-3 sm:grid-cols-2">
                            @foreach ($services as $service)
                                @php
                                    $branchPrices = $service->branches->mapWithKeys(fn ($branch) => [$branch->id => $branch->pivot->custom_price ?? $service->regular_price])->all();
                                    $branchIds = array_keys($branchPrices);
                                    $checkedServices = old('services', []);
                                @endphp
                                <label class="walkin-service flex cursor-pointer items-start gap-3 rounded-xl border border-outline-variant p-3 hover:bg-surface-container/60"
                                       data-branch-ids="{{ implode(',', $branchIds) }}" data-branch-prices='@json($branchPrices)'>
                                    <input type="checkbox" name="services[]" value="{{ $service->id }}" class="mt-1 h-5 w-5 accent-[var(--color-primary)]" @checked(in_array($service->id, $checkedServices, true)) @change="servicesChanged()">
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-medium">{{ $service->name }}</span>
                                        <span class="block text-xs text-on-surface-variant">{{ $service->category?->name }}</span>
                                    </span>
                                    <span class="walkin-service-price shrink-0 text-sm font-semibold">₱{{ number_format($service->regular_price, 2) }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('services')<p class="mt-3 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                        @error('services.*')<p class="mt-3 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                    </section>

                    <section x-show="step === 1" x-cloak>
                        <h2 class="text-lg font-semibold">Guest and stylists</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">Assign each service to a stylist who offers it. Stylist assignments are optional.</p>
                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <x-admin.input name="guest_name" label="Guest name (optional)" :value="old('guest_name')" placeholder="Walk-in guest" />
                            <x-admin.input name="phone" type="tel" label="Phone (optional)" :value="old('phone')" placeholder="09xx xxx xxxx" />
                            <div class="sm:col-span-2">
                                <p class="label">Stylist for each selected service</p>
                                <div id="walkInServiceStaff" class="space-y-3"></div>
                                @error('staff_by_service')<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                                @error('staff_by_service.*')<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div class="sm:col-span-2">
                                <x-admin.textarea name="notes" label="Internal note (optional)" :value="old('notes')" rows="3" placeholder="Optional details about the visit" />
                            </div>
                        </div>
                    </section>

                    <section x-show="step === 2" x-cloak>
                        <h2 class="text-lg font-semibold">Review walk-in</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">Confirm the services and record the visit. No appointment date or time is needed.</p>
                        <dl class="mt-5 space-y-3 rounded-xl bg-surface-container p-4 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-on-surface-variant">Guest</dt><dd class="font-medium" x-text="guestName || 'Walk-in guest' "></dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-on-surface-variant">Branch</dt><dd class="font-medium" x-text="branchName"></dd></div>
                            <div class="border-t border-outline-variant pt-3">
                                <dt class="mb-2 text-on-surface-variant">Services and stylists</dt>
                                <dd id="walkInReviewServices" class="space-y-2"></dd>
                            </div>
                            <div class="flex justify-between border-t border-outline-variant pt-3 text-base font-semibold"><dt>Total</dt><dd id="walkInTotal">₱0.00</dd></div>
                        </dl>
                        <p class="mt-4 text-xs text-on-surface-variant">This records a guest service visit only; it does not create an account or appointment.</p>
                    </section>

                    <div class="mt-6 flex justify-between border-t border-outline-variant pt-4">
                        <x-admin.button type="button" variant="text" x-show="step > 0" @click="step--">Back</x-admin.button>
                        <span x-show="step === 0"></span>
                        <x-admin.button type="button" x-show="step < 2" @click="next()">Continue</x-admin.button>
                        <x-admin.button type="submit" icon="save" x-show="step === 2">Record walk-in</x-admin.button>
                    </div>
                </x-admin.card>
            </div>

            <div class="lg:col-span-1">
                <x-admin.card title="Walk-in visit" subtitle="A present visit, separate from appointments and customer accounts.">
                    <p class="text-sm text-on-surface-variant">Choose services, optionally assign a stylist, then save the services provided.</p>
                    <p class="mt-4 text-sm font-medium">Current total: <span id="walkInSidebarTotal">₱0.00</span></p>
                </x-admin.card>
            </div>
        </div>
    </form>

    <script>
        function walkInWizard() {
            return {
                step: @js($errors->any() && !$errors->has('services') && !$errors->has('branch_id') ? 1 : 0),
                steps: ['Services', 'Guest & stylists', 'Review'],
                branchId: @js($selectedBranch),
                guestName: @js(old('guest_name', '')),
                staffSelections: @js(old('staff_by_service', [])),
                staffOptions: @js($staff->map(fn ($profile) => ['id' => $profile->id, 'name' => $profile->user?->name.($profile->position ? ' · '.$profile->position : ''), 'branchIds' => $profile->branches->pluck('id')->all(), 'serviceIds' => $profile->services->pluck('id')->all()])->values()),
                branchName: '',
                money: (value) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value),
                cards() { return [...document.querySelectorAll('.walkin-service')]; },
                selected() { return this.cards().filter(card => card.querySelector('input').checked); },
                updateAvailability() {
                    this.cards().forEach(card => {
                        const available = card.dataset.branchIds.split(',').filter(Boolean).includes(this.branchId);
                        card.hidden = !available;
                        if (!available) card.querySelector('input').checked = false;
                        const price = JSON.parse(card.dataset.branchPrices || '{}')[this.branchId] ?? 0;
                        card.querySelector('.walkin-service-price').textContent = this.money(Number(price));
                    });
                    this.renderServiceStaff();
                    this.updateTotal();
                },
                servicesChanged() { this.renderServiceStaff(); this.updateTotal(); },
                renderServiceStaff() {
                    const container = document.getElementById('walkInServiceStaff');
                    if (!container) return;
                    const rows = this.selected().map(card => {
                        const serviceId = card.querySelector('input').value;
                        const serviceName = card.querySelector('span span').textContent.trim();
                        const wrapper = document.createElement('div');
                        wrapper.className = 'grid gap-2 rounded-xl border border-outline-variant p-3 sm:grid-cols-2 sm:items-center';
                        const label = document.createElement('label');
                        label.className = 'text-sm font-medium';
                        label.textContent = serviceName;
                        label.htmlFor = `walkInStaff-${serviceId}`;
                        const select = document.createElement('select');
                        select.id = label.htmlFor;
                        select.name = `staff_by_service[${serviceId}]`;
                        select.className = 'field';
                        const options = [{ id: '', name: 'Any qualified stylist' }, ...this.staffOptions.filter(person => person.branchIds.includes(this.branchId) && person.serviceIds.includes(serviceId))];
                        options.forEach(person => {
                            const option = document.createElement('option');
                            option.value = person.id;
                            option.textContent = person.name;
                            option.selected = person.id === (this.staffSelections[serviceId] || '');
                            select.append(option);
                        });
                        if (!options.some(person => person.id === this.staffSelections[serviceId])) this.staffSelections[serviceId] = '';
                        select.addEventListener('change', () => { this.staffSelections[serviceId] = select.value; });
                        wrapper.append(label, select);
                        return wrapper;
                    });
                    container.replaceChildren(...rows);
                },
                updateTotal() {
                    const total = this.selected().reduce((sum, card) => sum + Number(JSON.parse(card.dataset.branchPrices || '{}')[this.branchId] ?? 0), 0);
                    ['walkInTotal', 'walkInSidebarTotal'].forEach(id => { const node = document.getElementById(id); if (node) node.textContent = this.money(total); });
                },
                next() {
                    if (this.step === 0 && (!this.branchId || this.selected().length === 0)) {
                        alert(!this.branchId ? 'Select a branch to continue.' : 'Choose at least one service to continue.');
                        return;
                    }
                    if (this.step === 1) this.prepareReview();
                    this.step++;
                },
                prepareReview() {
                    this.branchName = document.getElementById('walkInBranch').selectedOptions[0]?.textContent.trim() || '—';
                    this.guestName = document.querySelector('[name="guest_name"]').value.trim();
                    this.renderServiceStaff();
                    const list = document.getElementById('walkInReviewServices');
                    list.replaceChildren(...this.selected().map(card => {
                        const row = document.createElement('div');
                        row.className = 'flex justify-between gap-4';
                        const name = document.createElement('span');
                        const serviceId = card.querySelector('input').value;
                        const serviceName = card.querySelector('span span').textContent.trim();
                        const stylist = this.staffOptions.find(person => person.id === this.staffSelections[serviceId])?.name || 'Any qualified stylist';
                        name.textContent = `${serviceName} · ${stylist}`;
                        const price = document.createElement('span');
                        price.textContent = this.money(Number(JSON.parse(card.dataset.branchPrices || '{}')[this.branchId] ?? 0));
                        row.append(name, price);
                        return row;
                    }));
                    this.updateTotal();
                },
                canSubmit() { return this.step === 2 && this.branchId && this.selected().length > 0; },
                init() { this.updateAvailability(); }
            };
        }
    </script>
</x-admin.layout>
