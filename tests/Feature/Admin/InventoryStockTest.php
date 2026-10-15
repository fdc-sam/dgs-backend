<?php

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\Product;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WalkInVisit;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/** Inventory stock: opening stock, use by completed services, deliveries, counts and low-stock flags. */
class InventoryStockTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private Product $shampoo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/products', ['name' => 'Shampoo', 'unit' => 'ml', 'unit_cost' => '0.50', 'stock_quantity' => '1000', 'reorder_level' => '100'])
            ->assertSessionHasNoErrors();
        $this->shampoo = Product::where('name', 'Shampoo')->firstOrFail();
        $this->service->products()->attach($this->shampoo, ['quantity' => 15]); // 15 ml per service
    }

    public function test_a_new_product_starts_with_its_opening_stock(): void
    {
        $this->assertSame('1000.00', $this->shampoo->stock_quantity);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $this->shampoo->id, 'type' => StockMovement::OPENING, 'quantity' => 1000, 'balance_after' => 1000]);

        // Stock is not edited through the product form afterwards.
        $this->put("/products/{$this->shampoo->id}", ['name' => 'Shampoo', 'unit' => 'ml', 'unit_cost' => '0.50', 'stock_quantity' => '5', 'reorder_level' => '200'])
            ->assertSessionHasNoErrors();
        $this->assertSame('1000.00', $this->shampoo->fresh()->stock_quantity);
        $this->assertSame('200.00', $this->shampoo->fresh()->reorder_level);
    }

    public function test_completing_an_appointment_takes_its_products_off_stock_once(): void
    {
        $appointment = $this->appointment();

        $this->post("/appointments/{$appointment->id}/status", ['status' => 'completed'])->assertSessionHas('success');
        $this->assertSame('985.00', $this->shampoo->fresh()->stock_quantity);

        $movement = StockMovement::where('type', StockMovement::USAGE)->sole();
        $this->assertSame('-15.00', $movement->quantity);
        $this->assertTrue($movement->source->is($appointment->items->first()));

        // Recording usage again for the same item never takes stock twice.
        app(InventoryService::class)->recordUsage($appointment->fresh()->items);
        $this->assertSame('985.00', $this->shampoo->fresh()->stock_quantity);

        $this->get("/products/{$this->shampoo->id}/edit")->assertOk()
            ->assertSee('Used in service')->assertSee($appointment->appointment_number)->assertSee('985 ml');
    }

    public function test_only_completed_services_use_stock(): void
    {
        $appointment = $this->appointment();
        $this->post("/appointments/{$appointment->id}/status", ['status' => 'in_progress']);
        $this->post("/appointments/{$appointment->id}/cancel", ['reason' => 'Changed mind']);

        $this->post('/walk-ins', ['branch_id' => $this->branch->id, 'services' => [$this->service->id]])->assertSessionHasNoErrors();

        $this->assertSame('1000.00', $this->shampoo->fresh()->stock_quantity);
    }

    public function test_walk_ins_use_stock_and_corrections_put_it_back(): void
    {
        $conditioner = Product::create(['name' => 'Conditioner', 'unit' => 'ml', 'unit_cost' => 1, 'stock_quantity' => 500]);
        $treatment = Service::factory()->duration(30)->atBranch($this->branch)->create(['regular_price' => 500]);
        $treatment->products()->attach($conditioner, ['quantity' => 40]);

        $this->post('/walk-ins', ['branch_id' => $this->branch->id, 'services' => [$this->service->id, $treatment->id]])->assertSessionHasNoErrors();
        $visit = WalkInVisit::firstOrFail();
        $this->post("/walk-ins/{$visit->id}/complete")->assertRedirect();
        $this->assertSame('985.00', $this->shampoo->fresh()->stock_quantity);
        $this->assertSame('460.00', $conditioner->fresh()->stock_quantity);

        // Completing twice changes nothing.
        $this->post("/walk-ins/{$visit->id}/complete");
        $this->assertSame('985.00', $this->shampoo->fresh()->stock_quantity);

        // The haircut was really a treatment: its shampoo goes back, the treatment's conditioner comes off.
        $haircut = $visit->items()->where('service_id', $this->service->id)->firstOrFail();
        $this->put("/walk-ins/{$visit->id}/items/{$haircut->id}", ['service_id' => $treatment->id])->assertSessionHasNoErrors();
        $this->assertSame('1000.00', $this->shampoo->fresh()->stock_quantity);
        $this->assertSame('420.00', $conditioner->fresh()->stock_quantity);

        // Removing a service that was not done returns what it used.
        $this->delete("/walk-ins/{$visit->id}/items/{$haircut->id}")->assertSessionHasNoErrors();
        $this->assertSame('460.00', $conditioner->fresh()->stock_quantity);
        $this->assertSame(2, StockMovement::where('type', StockMovement::RETURN)->count());
    }

    public function test_deliveries_and_stock_counts(): void
    {
        $this->post("/products/{$this->shampoo->id}/restock", ['quantity' => '500', 'note' => 'Supplier A'])
            ->assertSessionHas('success', 'Added 500 ml of Shampoo. In stock: 1,500 ml.');
        $this->post("/products/{$this->shampoo->id}/count", ['counted' => '1450', 'note' => 'Monthly count'])->assertSessionHas('success');
        $this->assertSame('1450.00', $this->shampoo->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', ['type' => StockMovement::ADJUSTMENT, 'quantity' => -50, 'balance_after' => 1450, 'note' => 'Monthly count']);

        // A count that already matches records nothing.
        $this->post("/products/{$this->shampoo->id}/count", ['counted' => '1450'])->assertSessionHas('success', 'Shampoo stock already matches the count.');
        $this->assertSame(3, StockMovement::count());

        $this->post("/products/{$this->shampoo->id}/restock", ['quantity' => '0'])->assertSessionHasErrors('quantity');
        $this->post("/products/{$this->shampoo->id}/count", ['counted' => '-1'])->assertSessionHasErrors('counted');
    }

    public function test_low_and_out_of_stock_are_flagged(): void
    {
        $this->post("/products/{$this->shampoo->id}/count", ['counted' => '90']); // below the 100 ml alert
        $empty = Product::create(['name' => 'Hair Spa Cream', 'unit' => 'ml', 'unit_cost' => 1]);
        Product::create(['name' => 'Nail Polish', 'unit' => 'bottle', 'unit_cost' => 120, 'stock_quantity' => 10, 'reorder_level' => 2]);

        $this->assertSame('low', $this->shampoo->fresh()->stockStatus());
        $this->assertSame('out', $empty->stockStatus());
        $this->assertEqualsCanonicalizing(['Shampoo', 'Hair Spa Cream'], Product::needsRestock()->pluck('name')->all());

        $this->get('/products?stock=low')->assertOk()->assertSee('Shampoo')->assertSee('Hair Spa Cream')->assertDontSee('Nail Polish')
            ->assertSee('Low · alert at 100');
        $this->get('/products')->assertSee('Not counted'); // the two products created without a count
    }

    public function test_stock_can_go_below_zero_without_blocking_a_service(): void
    {
        $this->post("/products/{$this->shampoo->id}/count", ['counted' => '10']);
        $appointment = $this->appointment();

        $this->post("/appointments/{$appointment->id}/status", ['status' => 'completed'])->assertSessionHas('success');
        $this->assertSame('-5.00', $this->shampoo->fresh()->stock_quantity);
        $this->get('/products')->assertSee('Out of stock');
    }

    private function appointment(): Appointment
    {
        $appointment = Appointment::factory()->at(now()->toDateString().' 10:00')->confirmed()
            ->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id, 'subtotal' => 1000, 'total_amount' => 1000]);
        $appointment->items()->create(['service_id' => $this->service->id, 'service_name' => $this->service->name, 'unit_price' => 1000, 'duration_minutes' => 60, 'subtotal' => 1000]);

        return $appointment;
    }
}
