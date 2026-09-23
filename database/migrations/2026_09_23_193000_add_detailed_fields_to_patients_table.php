<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->unsignedInteger('age')->nullable()->after('last_name');
            $table->string('block')->nullable()->after('course_dept');
            $table->text('address')->nullable()->after('block');
            $table->string('nationality')->default('Filipino')->after('address');
            $table->string('emergency_contact_name')->nullable()->after('emergency_contact');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'middle_name',
                'last_name',
                'age',
                'block',
                'address',
                'nationality',
                'emergency_contact_name',
                'emergency_contact_phone',
            ]);
        });
    }
};
