<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Professional background (education, training, experience) that each
     * doctor/nurse writes about themselves and students can read.
     */
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->text('background')->nullable()->after('specialization');
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropColumn('background');
        });
    }
};
