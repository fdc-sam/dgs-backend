{{-- Add stock / Count stock dialogs. Expects an Alpine `target` = { name, unit, stock, restock, count } in scope. --}}
<x-admin.modal name="restock" title="Add stock">
    <form method="POST" :action="target.restock" class="space-y-4">
        @csrf
        <p class="text-sm text-on-surface-variant">A delivery of <span class="font-semibold text-on-surface" x-text="target.name"></span> arrived. Now in stock: <span x-text="`${target.stock} ${target.unit}`"></span>.</p>
        <div>
            <label for="restock_quantity" class="label">Quantity received<span class="text-danger" aria-hidden="true"> *</span></label>
            <div class="relative">
                <input id="restock_quantity" name="quantity" type="number" step="0.01" min="0.01" class="field pr-20" required>
                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-sm text-on-surface-variant" x-text="target.unit"></span>
            </div>
        </div>
        <x-admin.input name="note" id="restock_note" label="Note (optional)" placeholder="e.g. Supplier, receipt no." />
        <div class="flex justify-end gap-2">
            <x-admin.button variant="text" @click="$dispatch('close-modal', 'restock')">Close</x-admin.button>
            <x-admin.button type="submit" icon="add">Add to stock</x-admin.button>
        </div>
    </form>
</x-admin.modal>

<x-admin.modal name="count" title="Count stock">
    <form method="POST" :action="target.count" class="space-y-4">
        @csrf
        <p class="text-sm text-on-surface-variant">Enter how much <span class="font-semibold text-on-surface" x-text="target.name"></span> is on the shelf now. The system shows <span x-text="`${target.stock} ${target.unit}`"></span>; the difference is recorded as a stock count.</p>
        <div>
            <label for="count_counted" class="label">Counted on the shelf<span class="text-danger" aria-hidden="true"> *</span></label>
            <div class="relative">
                <input id="count_counted" name="counted" type="number" step="0.01" min="0" class="field pr-20" required>
                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-sm text-on-surface-variant" x-text="target.unit"></span>
            </div>
        </div>
        <x-admin.input name="note" id="count_note" label="Note (optional)" placeholder="e.g. Monthly count, spilled bottle" />
        <div class="flex justify-end gap-2">
            <x-admin.button variant="text" @click="$dispatch('close-modal', 'count')">Close</x-admin.button>
            <x-admin.button type="submit" icon="inventory">Save count</x-admin.button>
        </div>
    </form>
</x-admin.modal>
