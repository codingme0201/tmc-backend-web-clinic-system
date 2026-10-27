<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->date('effective_from');
            $table->string('start_time');
            $table->string('end_time');
            $table->json('excluded_dates')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'weekday']);
        });
        Schema::table('staff_schedules', function (Blueprint $table) {
            $table->foreignId('weekly_staff_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_override')->default(false);
            $table->unique(['weekly_staff_schedule_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('staff_schedules', function (Blueprint $table) {
            $table->dropUnique(['weekly_staff_schedule_id', 'date']);
            $table->dropConstrainedForeignId('weekly_staff_schedule_id');
            $table->dropColumn('is_override');
        });
        Schema::dropIfExists('weekly_staff_schedules');
    }
};
