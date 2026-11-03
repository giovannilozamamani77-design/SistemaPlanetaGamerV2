<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_workdays', function (Blueprint $t): void {
            $t->timestamp('absence_checked_at')->nullable()->index();
        });
        Schema::table('time_incidents', function (Blueprint $t): void {
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_workdays', function (Blueprint $t): void {
            $t->dropColumn('absence_checked_at');
        });
        Schema::table('time_incidents', function (Blueprint $t): void {
            $t->dropColumn(['period_start', 'period_end']);
        });
    }
};
