<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_record_medications', function (Blueprint $table) {
            $table->foreignId('prescribed_by_id')->nullable()->after('medical_record_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medical_record_medications', function (Blueprint $table) {
            $table->dropForeign(['prescribed_by_id']);
            $table->dropColumn('prescribed_by_id');
        });
    }
};
