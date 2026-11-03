<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_recoveries', function (Blueprint $table): void {
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->index(['user_id', 'confirmed_at', 'rejected_at'], 'global_recovery_pending_owner');
        });
    }

    public function down(): void
    {
        Schema::table('global_recoveries', function (Blueprint $table): void {
            $table->dropIndex('global_recovery_pending_owner');
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejected_at', 'rejected_by', 'rejection_reason']);
        });
    }
};
