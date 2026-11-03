<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_deposit_accounts', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('bank', 120);
            $t->string('number', 120);
            $t->string('holder', 120);
            $t->string('qr_path')->nullable();
            $t->string('qr_sha256', 64)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::table('card_inventory_movements', function (Blueprint $t): void {
            $t->json('destination_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('card_inventory_movements', fn (Blueprint $t) => $t->dropColumn('destination_snapshot'));
        Schema::dropIfExists('card_deposit_accounts');
    }
};
