<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('username')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $first = preg_split('/\s+/u', trim($user->name))[0] ?? '';
                $base = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', Str::ascii($first)), 0, 64);
                if (! preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/', $base)) {
                    continue;
                }
                $candidate = $base;
                $number = 0;
                while (DB::table('users')->where('username_key', strtolower($candidate))->exists()) {
                    $suffix = (string) ++$number;
                    $candidate = substr($base, 0, 64 - strlen($suffix)).$suffix;
                }
                DB::table('users')->where('id', $user->id)->whereNull('username')->update(['username' => $candidate, 'username_key' => strtolower($candidate)]);
            }
        });
    }

    public function down(): void
    {
        // Preserve assigned login identities when rolling back this data migration.
    }
};
