<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_box_types', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 120);
            $t->string('family', 20);
            $t->json('components');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('cash_box_type_audits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_box_type_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('payload_hash', 64);
            $t->json('before')->nullable();
            $t->json('after');
            $t->text('reason');
            $t->timestamp('created_at');
            $t->index(['cash_box_type_id', 'id']);
        });
        Schema::table('cash_box_accounts', function (Blueprint $t): void {
            $t->string('category', 30)->nullable();
            $t->string('reference', 120)->nullable();
            $t->unsignedSmallInteger('position')->default(0);
            $t->boolean('is_active')->default(true);
        });
        Schema::table('cash_box_configurations', function (Blueprint $t): void {
            $t->string('settlement_source', 20)->nullable();
        });
        $row = fn (string $code, string $name, string $category, bool $required = false, ?string $reference = null): array => compact('code', 'name', 'category', 'required', 'reference');
        $common = [$row('efectivo', 'Efectivo', 'efectivo', true)];
        $templates = [
            'BCP' => ['name' => 'Agente BCP', 'components' => [...$common, $row('bcp', 'Cuenta BCP', 'banco', true), $row('yape', 'Yape operativo', 'billetera', true)]],
            'TIGO_MONEY' => ['name' => 'Tigo Money', 'components' => [...$common, $row('tigo_money', 'Tigo Money', 'tigo_money', true), $row('yape', 'Yape operativo', 'billetera', true),
                $row('recarga_entel', 'Recarga Entel', 'recarga'), $row('paquetes_entel', 'Paquetes Entel', 'recarga'),
                $row('tigo_75885195', 'Tigo 75885195', 'tigo_money', false, '75885195'), $row('tigo_75249579', 'Tigo 75249579', 'tigo_money', false, '75249579'), $row('recarga_viva', 'Recarga Viva', 'recarga')]],
        ];
        foreach ($templates as $code => $values) {
            DB::table('cash_box_types')->insert(['code' => $code, 'name' => $values['name'], 'family' => $code, 'components' => json_encode($values['components'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['efectivo' => 'efectivo', 'bcp' => 'banco', 'yape' => 'billetera', 'tigo_money' => 'tigo_money'] as $code => $category) {
            DB::table('cash_box_accounts')->where('code', $code)->update(['category' => $category]);
        }
    }

    public function down(): void
    {
        Schema::table('cash_box_configurations', fn (Blueprint $t) => $t->dropColumn('settlement_source'));
        Schema::table('cash_box_accounts', fn (Blueprint $t) => $t->dropColumn(['category', 'reference', 'position', 'is_active']));
        Schema::dropIfExists('cash_box_type_audits');
        Schema::dropIfExists('cash_box_types');
    }
};
