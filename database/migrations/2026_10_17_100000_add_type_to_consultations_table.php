<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Service type of the consultation (Check-up, Dental concern, ...), so
     * the attending doctor can be matched to the right department. Existing
     * consultations inherit the type of the appointment they came from.
     */
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->string('type')->nullable()->after('visit_type');
        });

        DB::table('consultations')->whereNotNull('appointment_id')->orderBy('id')->each(function ($consultation) {
            $type = DB::table('appointments')->where('id', $consultation->appointment_id)->value('type');
            if ($type) {
                DB::table('consultations')->where('id', $consultation->id)->update(['type' => $type]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
