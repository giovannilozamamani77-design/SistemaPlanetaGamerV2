<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_monthly_closes', function (Blueprint $table): void {
            $table->id();
            $table->string('month', 7)->unique();
            $table->uuid('request_uuid')->unique();
            $table->string('request_hash', 64);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->foreignId('pin_audit_id')->constrained('user_audits')->restrictOnDelete();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('snapshot');
            $table->string('snapshot_hash', 64);
            $table->unsignedBigInteger('source_count');
            $table->unsignedBigInteger('pending_count');
            $table->boolean('accepted_pending');
            $table->timestamp('confirmed_at');
        });
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->foreignId('monthly_close_id')->nullable()->constrained('report_monthly_closes')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER report_monthly_closes_no_'.strtolower($event).' BEFORE '.$event." ON report_monthly_closes BEGIN SELECT RAISE(ABORT, 'Monthly report closes are immutable'); END");
            }
        } elseif (DB::getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER report_monthly_closes_no_'.strtolower($event).' BEFORE '.$event." ON report_monthly_closes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Monthly report closes are immutable'");
            }
        }
    }

    public function down(): void
    {
        Schema::table('report_exports', fn (Blueprint $table) => $table->dropConstrainedForeignId('monthly_close_id'));
        Schema::dropIfExists('report_monthly_closes');
    }
};
