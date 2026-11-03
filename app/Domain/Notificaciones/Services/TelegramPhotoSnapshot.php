<?php

namespace App\Domain\Notificaciones\Services;

use Illuminate\Support\Facades\File;

class TelegramPhotoSnapshot
{
    public function fromLogo(string $filename, string $caption): ?array
    {
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '\\') || mb_strlen($caption, 'UTF-8') > 1024) {
            return null;
        }
        $path = base_path('logos/'.$filename);
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 262144) {
            return null;
        }
        try {
            $bytes = File::get($path);
            $info = @getimagesizefromstring($bytes);
        } catch (\Throwable $exception) {
            return null;
        }
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
            return null;
        }

        return ['filename' => $filename, 'mime' => $info['mime'], 'content' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes), 'caption' => $caption, 'version' => 2];
    }
}
