<?php

namespace App\Infrastructure\Accesorios;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductImageStorage
{
    public function inspect(mixed $image): ?array
    {
        if ($image === null) {
            return null;
        }
        if (! $image instanceof UploadedFile) {
            throw ValidationException::withMessages(['image' => 'Selecciona una imagen válida desde tus archivos o cámara.']);
        }
        Validator::make(['image' => $image], ['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=5000,max_height=5000']])->validate();
        $info = @getimagesize($image->getRealPath());
        if (! $info || $info[0] * $info[1] > 12000000) {
            throw ValidationException::withMessages(['image' => 'Usa una foto de hasta 12 megapíxeles y 5 MB.']);
        }

        return ['hash' => hash_file('sha256', $image->getRealPath()), 'mime' => $info['mime'], 'width' => $info[0], 'height' => $info[1]];
    }

    public function store(UploadedFile $image, string $requestKey, string $directory = 'products'): string
    {
        if (! in_array($directory, ['products', 'phones'], true)) {
            throw new \InvalidArgumentException('Directorio de imagen no permitido.');
        }
        $meta = $this->inspect($image);
        $path = 'accessories/'.$directory.'/'.strtolower($requestKey).'/'.$meta['hash'].'.jpg';
        $disk = Storage::disk('local');
        try {
            if ($disk->exists($path)) {
                return $path;
            }
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['image' => 'No se pudo acceder al almacenamiento de imágenes. Intenta nuevamente.']);
        }
        if (! function_exists('imagecreatetruecolor')) {
            throw ValidationException::withMessages(['image' => 'El servidor debe tener GD habilitado para guardar imágenes.']);
        }
        $source = match ($meta['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($image->getRealPath()),'image/png' => @imagecreatefrompng($image->getRealPath()),'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($image->getRealPath()) : false,default => false
        };
        if (! $source) {
            throw ValidationException::withMessages(['image' => 'No se pudo procesar la imagen. Usa JPG, PNG o WebP.']);
        }
        $scale = min(1, 1600 / max($meta['width'], $meta['height']));
        $w = max(1, (int) round($meta['width'] * $scale));
        $h = max(1, (int) round($meta['height'] * $scale));
        $target = imagecreatetruecolor($w, $h);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $meta['width'], $meta['height']);
        imagedestroy($source);
        if ($meta['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($image->getRealPath(), 'IFD0', true);
            $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
            $angle = match ($orientation) {
                3 => 180,5,8 => 90,6,7 => -90,default => 0
            };
            if ($angle) {
                $rotated = imagerotate($target, $angle, 0);
                if ($rotated) {
                    imagedestroy($target);
                    $target = $rotated;
                }
            }
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($target, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 4) {
                imageflip($target, IMG_FLIP_VERTICAL);
            }
        }
        ob_start();
        $ok = imagejpeg($target, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($target);
        try {
            $saved = $ok && $disk->put($path, $bytes);
        } catch (\Throwable $e) {
            report($e);
            $saved = false;
        }
        if (! $saved) {
            try {
                $disk->delete($path);
            } catch (\Throwable) {
            }
            throw ValidationException::withMessages(['image' => 'No se pudo guardar la foto. Revisa el almacenamiento e intenta nuevamente.']);
        }

        return $path;
    }
}
