<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_box_close_drafts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cash_box_session_id')->unique()->constrained('cash_box_sessions');
            $t->foreignId('user_id')->constrained('users');
            $t->string('status')->default('DRAFT');
            $t->uuid('request_key')->unique();
            $t->longText('payload')->nullable();
            $t->unsignedBigInteger('version')->default(0);
            $t->foreignId('cash_box_close_id')->nullable()->constrained('cash_box_closes');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_box_close_drafts');
    }
};
