<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['card_stocks', 'entel_digital_accounts'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('cost_value_cents')->nullable();
                $table->unsignedBigInteger('cost_revision')->default(0);
            });
        }
        foreach (['card_sale_lines', 'entel_recharges'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('cost_cents')->nullable();
                $table->bigInteger('gross_margin_cents')->nullable();
            });
        }
        foreach (['card_inventory_movements', 'entel_digital_movements'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('cost_before_cents')->nullable();
                $table->unsignedBigInteger('cost_after_cents')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['card_inventory_movements', 'entel_digital_movements'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['cost_before_cents', 'cost_after_cents']));
        }
        foreach (['card_sale_lines', 'entel_recharges'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['cost_cents', 'gross_margin_cents']));
        }
        foreach (['card_stocks', 'entel_digital_accounts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['cost_value_cents', 'cost_revision']));
        }
    }
};
