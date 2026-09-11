<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('clinic_name')->default('Tr Municipal College Clinic');
            $table->string('clinic_address')->nullable();
            $table->string('clinic_phone')->nullable();
            $table->string('clinic_email')->nullable();
            $table->string('clinic_hours')->default('8:00 AM - 5:00 PM');
            $table->string('clinic_days')->default('Monday - Friday');
            $table->text('clinic_description')->nullable();
            $table->string('emergency_hotline')->nullable();
            $table->boolean('online_appointments_enabled')->default(true);
            $table->integer('appointment_buffer_minutes')->default(15);
            $table->integer('max_daily_appointments')->default(50);
            $table->json('notification_settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
