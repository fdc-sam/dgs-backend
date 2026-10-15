<?php

namespace Tests\Feature\Api;

use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AppointmentConflictTest extends TestCase
{
    use BuildsSalon, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    /** Leave the database empty for the transactional tests that run after this class. */
    protected function tearDown(): void
    {
        $tables = array_values(array_diff(
            // Only this test database's tables (MySQL: schema = database name; Postgres: "public").
            array_map(fn ($t) => $t['name'], Schema::getTables(schema: DB::getDriverName() === 'pgsql' ? 'public' : DB::getDatabaseName())),
            ['migrations'],
        ));

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE '.implode(', ', array_map(fn ($t) => '"'.$t.'"', $tables)).' RESTART IDENTITY CASCADE');
        } else {
            Schema::withoutForeignKeyConstraints(fn () => array_map(fn ($t) => DB::table($t)->truncate(), $tables));
        }

        parent::tearDown();
    }

    public function test_second_customer_cannot_book_a_taken_slot(): void
    {
        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload())->assertCreated();

        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload())
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'SLOT_UNAVAILABLE');

        // Overlapping start inside the first booking (09:00–10:00 + 15 buffer).
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => '09:30']))->assertStatus(409);
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => '10:00']))->assertStatus(409);

        $this->assertSame(1, Appointment::count());
    }

    public function test_customer_cannot_double_book_themselves_with_different_staff(): void
    {
        $other = $this->makeStylist();
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload())->assertCreated();
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['staff_id' => $other->id]))
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    /**
     * Two separate processes book the same staff/slot at the same instant against MySQL.
     * The row locks in AppointmentService must serialise them: exactly one wins, one gets 409.
     */
    public function test_concurrent_bookings_for_same_slot_only_one_succeeds(): void
    {
        if (! function_exists('pcntl_fork') || ! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Requires pcntl and MySQL or PostgreSQL.');
        }

        $customers = User::factory()->count(4)->customer()->create();
        $payload = $this->bookingPayload();
        $dir = sys_get_temp_dir().'/dgs-race-'.uniqid();
        mkdir($dir);
        $go = microtime(true) + 1.0; // all children start at the same moment

        DB::disconnect();
        $pids = [];
        foreach ($customers as $i => $customer) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->runRacer($customer, $payload, $go, "{$dir}/{$i}");
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = array_map(fn ($f) => trim(file_get_contents($f)), glob("{$dir}/*"));
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        sort($results);
        $this->assertSame(['booked', 'slot_unavailable', 'slot_unavailable', 'slot_unavailable'], $results);
        $this->assertSame(1, Appointment::where('staff_profile_id', $this->stylist->id)->count());
    }

    private function runRacer(User $customer, array $payload, float $go, string $resultFile): never
    {
        try {
            DB::reconnect();
            usleep((int) max(0, ($go - microtime(true)) * 1_000_000));
            app(AppointmentService::class)->book($customer, $payload);
            $result = 'booked';
        } catch (SlotUnavailableException) {
            $result = 'slot_unavailable';
        } catch (\Throwable $e) {
            $result = 'error: '.$e->getMessage();
        }
        file_put_contents($resultFile, $result);
        // Hard exit: skip PHPUnit/Laravel shutdown handlers inherited from the parent process.
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
}
