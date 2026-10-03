<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')
            ->whereIn('clinic_name', ['Trinidad Municipal College Clinic', 'Tr Municipal College Clinic'])
            ->update([
                'clinic_name' => 'TMC Expansion Clinic',
                'clinic_description' => 'Primary healthcare facility of the Trinidad Municipal College Expansion campus, serving students and personnel of TMC.',
            ]);
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('clinic_name', 'TMC Expansion Clinic')
            ->update(['clinic_name' => 'Trinidad Municipal College Clinic']);
    }
};
