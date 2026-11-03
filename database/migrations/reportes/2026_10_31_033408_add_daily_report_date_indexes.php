<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'card_sales' => ['created_at'],
        'entel_recharges' => ['created_at'],
        'accessory_sales' => ['created_at'],
        'loan_movements' => ['created_at', 'kind'],
        'cash_box_operations' => ['status', 'created_at'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, fn (Blueprint $schema) => $schema->index($columns, 'reports_'.$table.'_day_index'));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, fn (Blueprint $schema) => $schema->dropIndex('reports_'.$table.'_day_index'));
        }
    }
};
