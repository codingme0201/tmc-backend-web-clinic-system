<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('visit_type')->default('New Consultation')->after('type');
            $table->foreignId('previous_consultation_id')->nullable()->after('visit_type')->constrained('consultations')->nullOnDelete();
            $table->unsignedInteger('queue_number')->nullable()->after('status');
            $table->timestamp('checked_in_at')->nullable()->after('queue_number');
        });

        DB::table('appointments')->where('type', 'Follow-up')->update(['visit_type' => 'Follow-up Consultation']);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['previous_consultation_id']);
            $table->dropColumn(['visit_type', 'previous_consultation_id', 'queue_number', 'checked_in_at']);
        });
    }
};
