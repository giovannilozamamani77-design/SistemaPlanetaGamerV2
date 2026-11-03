<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_monthly_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monthly_close_id')->constrained('report_monthly_closes')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('previous_adjustment_id')->nullable()->constrained('report_monthly_adjustments')->restrictOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->string('request_hash', 64);
            $table->string('preview_hash', 64);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->foreignId('pin_audit_id')->constrained('user_audits')->restrictOnDelete();
            $table->text('reason');
            $table->string('before_hash', 64);
            $table->json('snapshot');
            $table->string('snapshot_hash', 64);
            $table->json('changes');
            $table->bigInteger('sales_delta_cents');
            $table->bigInteger('collections_delta_cents');
            $table->unsignedBigInteger('pending_count');
            $table->boolean('accepted_pending');
            $table->timestamp('confirmed_at');
            $table->unique(['monthly_close_id', 'sequence'], 'report_monthly_adjustment_version_unique');
        });
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->foreignId('monthly_adjustment_id')->nullable()->constrained('report_monthly_adjustments')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER report_monthly_adjustments_no_'.strtolower($event).' BEFORE '.$event." ON report_monthly_adjustments BEGIN SELECT RAISE(ABORT, 'Monthly adjustments are immutable'); END");
            }
        } elseif (DB::getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER report_monthly_adjustments_no_'.strtolower($event).' BEFORE '.$event." ON report_monthly_adjustments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Monthly adjustments are immutable'");
            }
        }
    }

    public function down(): void
    {
        Schema::table('report_exports', fn (Blueprint $table) => $table->dropConstrainedForeignId('monthly_adjustment_id'));
        Schema::dropIfExists('report_monthly_adjustments');
    }
};
