<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Clinic Calendar module has been removed completely: its events
     * and blocked (unavailable) periods are no longer stored.
     */
    public function up(): void
    {
        Schema::dropIfExists('unavailable_schedules');
        Schema::dropIfExists('clinic_events');
    }

    public function down(): void
    {
        Schema::create('clinic_events', function (Blueprint $table) {
            $table->id();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('start_time', 10)->nullable();
            $table->string('end_time', 10)->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('type')->default('Event');
            $table->string('status')->default('Scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('date');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['start_date', 'status']);
            $table->index(['type']);
        });

        Schema::create('unavailable_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('start_time', 10)->nullable();
            $table->string('end_time', 10)->nullable();
            $table->boolean('all_day')->default(true);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });
    }
};
