<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_coverages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('source_workday_id')->unique()->constrained('scheduled_workdays')->restrictOnDelete();
            $t->foreignId('workday_id')->unique()->constrained('scheduled_workdays')->restrictOnDelete();
            $t->foreignId('credit_workday_id')->nullable()->constrained('scheduled_workdays')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->date('work_date');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('reason', 2000);
            $t->uuid('request_key')->unique();
            $t->timestamps();
            $t->index(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_coverages');
    }
};
