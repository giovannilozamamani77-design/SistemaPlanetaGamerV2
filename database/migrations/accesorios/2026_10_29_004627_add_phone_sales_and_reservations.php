<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_reservations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('unit_id')->constrained('phone_units')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->uuid('cart_key');
            $t->string('status', 15);
            $t->unsignedBigInteger('price_cents');
            $t->timestamp('expires_at');
            $t->timestamp('created_at');
            $t->timestamp('ended_at')->nullable();
            $t->index(['unit_id', 'status']);
            $t->index(['user_id', 'cart_key', 'status']);
            $t->index(['status', 'expires_at']);
        });
        Schema::table('phone_units', function (Blueprint $t): void {
            $t->foreignId('reservation_id')->nullable()->constrained('phone_reservations')->restrictOnDelete();
        });
        Schema::table('accessory_sales', function (Blueprint $t): void {
            $t->foreignId('customer_id')->nullable()->constrained('loan_borrowers')->restrictOnDelete();
            $t->json('customer_snapshot')->nullable();
            $t->boolean('receipt_required')->default(false);
        });
        Schema::table('accessory_sale_lines', function (Blueprint $t): void {
            $t->foreignId('product_id')->nullable()->change();
            $t->string('kind', 15)->default('ACCESSORY');
            $t->foreignId('phone_unit_id')->nullable()->constrained('phone_units')->restrictOnDelete();
            $t->json('phone_snapshot')->nullable();
            $t->unique(['sale_id', 'phone_unit_id']);
        });
        Schema::create('phone_warranties', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sale_line_id')->unique()->constrained('accessory_sale_lines')->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('phone_units')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('expires_on');
            $t->unsignedTinyInteger('months');
            $t->string('terms_version', 20);
            $t->text('terms');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_warranties');
        Schema::table('accessory_sale_lines', function (Blueprint $t): void {
            $t->dropUnique(['sale_id', 'phone_unit_id']);
            $t->dropConstrainedForeignId('phone_unit_id');
            $t->dropColumn(['kind', 'phone_snapshot']);
        });
        Schema::table('accessory_sales', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropColumn(['customer_snapshot', 'receipt_required']);
        });
        Schema::table('phone_units', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('reservation_id');
        });
        Schema::dropIfExists('phone_reservations');
    }
};
