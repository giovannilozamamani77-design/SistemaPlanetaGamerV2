<?php

namespace App\Domain\Usuarios\Support;

use App\Models\User;
use Illuminate\Support\Str;

class Username
{
    public static function fromName(string $name): ?string
    {
        $first = preg_split('/\s+/u', trim($name))[0] ?? '';
        $first = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', Str::ascii($first)), 0, 64);

        return preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/', $first) ? $first : null;
    }

    public static function availableFromName(string $name): ?string
    {
        $base = self::fromName($name);
        if ($base === null) {
            return null;
        }
        $candidate = $base;
        $number = 0;
        while (User::where('username_key', self::key($candidate))->exists()) {
            $suffix = (string) ++$number;
            $candidate = substr($base, 0, 64 - strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    public static function key(?string $username): ?string
    {
        return filled($username) ? strtolower(trim($username)) : null;
    }
}
