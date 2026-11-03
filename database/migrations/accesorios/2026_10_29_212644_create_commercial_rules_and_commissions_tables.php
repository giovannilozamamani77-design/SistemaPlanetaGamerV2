<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_sale_rules', function (Blueprint $t): void {
            $t->id();
            $t->string('scope', 20)->unique();
            $t->json('rules');
            $t->unsignedInteger('revision')->default(0);
        });
        Schema::create('commercial_rule_changes', function (Blueprint $t): void {
            $t->id();
            $t->string('scope', 20);
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->json('before_rules');
            $t->json('after_rules');
            $t->unsignedInteger('previous_revision');
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['scope', 'previous_revision']);
        });
        Schema::create('commission_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sale_line_id')->unique()->constrained('accessory_sale_lines')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->unsignedBigInteger('amount_cents');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commercial_rule_changes');
        Schema::dropIfExists('commercial_sale_rules');
    }
};
