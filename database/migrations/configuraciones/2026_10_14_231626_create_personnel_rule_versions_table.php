<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_rule_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('request_key')->unique();
            $t->text('reason');
            $t->json('values');
            $t->timestamp('effective_from');
            $t->timestamps();
            $t->index(['effective_from', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_rule_versions');
    }
};
