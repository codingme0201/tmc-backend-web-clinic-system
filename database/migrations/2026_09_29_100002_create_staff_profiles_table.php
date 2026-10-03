<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('position')->nullable();
            $table->string('specialization')->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->string('license_type')->nullable();
            $table->string('license_number', 50)->nullable();
            $table->date('license_issued_at')->nullable();
            $table->date('license_expires_at')->nullable();
            $table->string('other_credentials', 500)->nullable();
            $table->string('credential_status')->default('Not Submitted')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
