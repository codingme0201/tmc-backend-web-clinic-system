<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the unavailable schedules table (Module 9 — Block Unavailable Schedule).
     *
     * Stores clinic-wide blocked periods that prevent appointments from
     * being scheduled during those times. Unlike StaffSchedule unavailability
     * (which is per-user), these blocks apply to the entire clinic.
     */
    public function up(): void
    {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unavailable_schedules');
    }
};
