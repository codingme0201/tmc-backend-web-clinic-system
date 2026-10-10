<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who sent a manual notification, so the sender can see what they sent
     * (and to whom) on the web Notifications page.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('sender_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->index(['sender_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['sender_id', 'created_at']);
            $table->dropConstrainedForeignId('sender_id');
        });
    }
};
