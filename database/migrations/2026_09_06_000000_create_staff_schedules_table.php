<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the staff schedules table (Module 8 — Doctor/Nurse Schedule).
     *
     * Stores individual schedule entries for doctors and nurses. Each row
     * represents a time block on a specific date for a staff member (User).
     * The `status` column tracks availability (Available / Unavailable).
     *
     * Conflict detection prevents overlapping time blocks for the same
     * staff member on the same date.
     */
    public function up(): void
    {
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('start_time', 10);
            $table->string('end_time', 10);
            $table->string('status')->default('Available');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date', 'start_time']);
            $table->index(['date', 'status']);
            $table->index(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_schedules');
    }
};
