<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable();
            $table->string('username_key', 64)->nullable()->unique();
        });
        DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $first = preg_split('/\s+/u', trim($user->name))[0] ?? '';
                $first = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', Str::ascii($first)), 0, 64);
                if (! preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/', $first)) {
                    continue;
                }
                $key = strtolower($first);
                if (! DB::table('users')->where('username_key', $key)->exists()) {
                    DB::table('users')->where('id', $user->id)->update(['username' => $first, 'username_key' => $key]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username_key']);
            $table->dropColumn(['username', 'username_key']);
        });
    }
};
