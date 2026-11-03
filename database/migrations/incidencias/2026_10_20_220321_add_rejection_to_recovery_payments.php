<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recovery_payments', function (Blueprint $table): void {
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->index(['confirmed_at', 'rejected_at', 'id'], 'recovery_verification_queue');
        });
    }

    public function down(): void
    {
        Schema::table('recovery_payments', function (Blueprint $table): void {
            $table->dropIndex('recovery_verification_queue');
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejected_at', 'rejected_by', 'rejection_reason']);
        });
    }
};
