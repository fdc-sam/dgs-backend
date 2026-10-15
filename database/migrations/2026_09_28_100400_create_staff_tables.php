<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('position')->nullable();
            $table->text('bio')->nullable();
            $table->string('employee_code', 64)->nullable()->unique();
            $table->date('hire_date')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branch_staff', function (Blueprint $table) {
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->primary(['branch_id', 'staff_profile_id']);
        });

        Schema::create('service_staff', function (Blueprint $table) {
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['service_id', 'staff_profile_id']);
        });

        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday ... 6 = Saturday
            $table->boolean('is_working')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->timestamps();
            // A staff member works at most one branch per weekday.
            $table->unique(['staff_profile_id', 'day_of_week']);
            $table->index(['branch_id', 'day_of_week']);
        });

        Schema::create('staff_days_off', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('staff_profile_id')->constrained()->cascadeOnDelete();
            // Null = off at every branch.
            $table->foreignUlid('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['staff_profile_id', 'branch_id', 'date']);
            $table->index(['staff_profile_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_days_off');
        Schema::dropIfExists('staff_schedules');
        Schema::dropIfExists('service_staff');
        Schema::dropIfExists('branch_staff');
        Schema::dropIfExists('staff_profiles');
    }
};
