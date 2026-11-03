<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_notice_reads', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('notice_key', 120);
            $table->string('version', 120);
            $table->timestamp('read_at');
            $table->primary(['user_id', 'notice_key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_notice_reads');
    }
};
