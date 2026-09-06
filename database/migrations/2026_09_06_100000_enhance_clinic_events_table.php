<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enhance the clinic_events table for the full Clinic Calendar module.
     *
     * Adds time support, event types, all-day flag, status tracking,
     * and ownership. The existing `date` column (display string) is
     * replaced with a proper `date` column plus optional time fields.
     */
    public function up(): void
    {
        Schema::table('clinic_events', function (Blueprint $table) {
            // Rename the string date column to a proper date column
            $table->date('start_date')->nullable()->after('id');
            $table->date('end_date')->nullable()->after('start_date');
            $table->string('start_time', 10)->nullable()->after('end_date');
            $table->string('end_time', 10)->nullable()->after('start_time');
            $table->boolean('all_day')->default(false)->after('end_time');
            $table->string('type')->default('Event')->after('all_day');
            $table->string('status')->default('Scheduled')->after('type');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('status');

            $table->index(['start_date', 'status']);
            $table->index(['type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinic_events', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropIndex(['start_date', 'status']);
            $table->dropIndex(['type']);
            $table->dropColumn([
                'start_date', 'end_date', 'start_time', 'end_time',
                'all_day', 'type', 'status', 'created_by',
            ]);
        });
    }
};
