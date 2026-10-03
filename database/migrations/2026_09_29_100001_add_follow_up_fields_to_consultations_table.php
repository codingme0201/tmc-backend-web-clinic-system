<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->string('visit_type')->default('New Consultation')->after('status');
            $table->foreignId('previous_consultation_id')->nullable()->after('visit_type')->constrained('consultations')->nullOnDelete();
            $table->boolean('follow_up_required')->default(false)->after('disposition');
            $table->date('follow_up_date')->nullable()->after('follow_up_required');
            $table->text('follow_up_notes')->nullable()->after('follow_up_date');
            $table->foreignId('follow_up_appointment_id')->nullable()->after('follow_up_notes')->constrained('appointments')->nullOnDelete();
        });

        $followUpAppointmentIds = DB::table('appointments')
            ->where('visit_type', 'Follow-up Consultation')
            ->pluck('id');

        if ($followUpAppointmentIds->isNotEmpty()) {
            DB::table('consultations')
                ->whereIn('appointment_id', $followUpAppointmentIds)
                ->update(['visit_type' => 'Follow-up Consultation']);
        }
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropForeign(['previous_consultation_id']);
            $table->dropForeign(['follow_up_appointment_id']);
            $table->dropColumn([
                'visit_type', 'previous_consultation_id', 'follow_up_required',
                'follow_up_date', 'follow_up_notes', 'follow_up_appointment_id',
            ]);
        });
    }
};
