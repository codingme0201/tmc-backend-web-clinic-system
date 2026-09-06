<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table) {
            $table->foreignId('issued_by_id')->nullable()->after('medical_record_id')->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by_id')->nullable()->after('issued_by_id')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->after('requested_by_id')->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by_id')->nullable()->after('approved_by_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table) {
            $table->dropForeign(['issued_by_id']);
            $table->dropForeign(['requested_by_id']);
            $table->dropForeign(['approved_by_id']);
            $table->dropForeign(['rejected_by_id']);
            $table->dropColumn(['issued_by_id', 'requested_by_id', 'approved_by_id', 'rejected_by_id']);
        });
    }
};
