<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('sex')->nullable();
        });

        DB::table('medical_records')->whereIn('sex', ['Female', 'Male', 'Other'])
            ->orderBy('id')->each(function ($record) {
                DB::table('patients')->where('patient_id', $record->patient_id)
                    ->whereNull('sex')->update(['sex' => $record->sex]);
            });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('sex');
        });
    }
};
