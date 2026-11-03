<?php

namespace App\Domain\Notificaciones\Services;

use App\Domain\Notificaciones\Data\TelegramEventCatalog;
use App\Domain\Notificaciones\Models\TelegramMessageTemplate;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use Illuminate\Validation\ValidationException;

class TelegramTemplateRenderer
{
    public const DEFAULT_BODY = "{{titulo}}\n{{detalle}}";

    public static function validateBody(string $body): void
    {
        if (mb_strlen($body) > 1200 || substr_count($body, '{{detalle}}') !== 1) {
            throw ValidationException::withMessages(['body' => 'Conserva {{detalle}} una sola vez y usa hasta 1200 caracteres.']);
        }
        preg_match_all('/\{\{(.*?)\}\}/us', $body, $matches);
        foreach ($matches[1] as $key) {
            if (! in_array($key, ['titulo', 'detalle', 'modulo', 'evento'], true)) {
                throw ValidationException::withMessages(['body' => 'Marcador no permitido. Usa {{titulo}}, {{detalle}}, {{modulo}} o {{evento}}.']);
            }
        }
    }

    public function revision(string $module, string $code): int
    {
        return (int) (TelegramMessageTemplate::where('module', $module)->where('event_code', $code)->value('revision') ?? 0);
    }

    public function apply(string $module, string $code, string $message, ?array $media): array
    {
        $template = TelegramMessageTemplate::where('module', $module)->where('event_code', $code)->first();
        if (! $template) {
            return ['message' => $message, 'media' => $media, 'revision' => 0, 'applied' => false];
        }
        $render = fn (string $text) => $this->render($template->body, $module, $code, $text);
        $text = $render($message);
        if (mb_strlen($text) > 4096) {
            return ['message' => $message, 'media' => $template->image_mode === 'ORIGINAL' ? $media : null, 'revision' => $template->revision, 'applied' => false];
        }
        $image = match ($template->image_mode) {
            'NONE' => null,'CUSTOM' => $template->image,default => $media
        };
        if ($image) {
            $caption = mb_strlen($text) <= 1024 ? $text : ($media && $template->image_mode === 'ORIGINAL' ? $render($media['caption']) : $text);
            $image = mb_strlen($caption) <= 1024 ? array_replace($image, ['caption' => $caption, 'version' => 3]) : null;
        }

        return ['message' => $text, 'media' => $image, 'revision' => $template->revision, 'applied' => true];
    }

    private function render(string $body, string $module, string $code, string $message): string
    {
        [$title,$details] = array_pad(explode("\n", $message, 2), 2, '');

        return strtr($body, ['{{titulo}}' => $title, '{{detalle}}' => $details, '{{modulo}}' => TelegramSettingsQuery::MODULES[$module], '{{evento}}' => app(TelegramEventCatalog::class)->event($module, $code)['title']]);
    }

    public function example(string $module, string $code, array $preview): array
    {
        $prefix = "EJEMPLO · Datos ficticios · No se envía\n\n";
        $message = str_starts_with($preview['message'], $prefix) ? substr($preview['message'], strlen($prefix)) : $preview['message'];
        $media = $preview['media'] ?? null;
        if ($media && str_starts_with($media['caption'], $prefix)) {
            $media['caption'] = substr($media['caption'], strlen($prefix));
        }
        $r = $this->apply($module, $code, $message, $media);
        $r['message'] = $prefix.$r['message'];
        if ($r['media']) {
            $r['media']['caption'] = $prefix.$r['media']['caption'];
            if (mb_strlen($r['media']['caption']) > 1024) {
                $r['media'] = null;
            }
        }

        return array_replace($preview, $r, ['presentation_revision' => $r['revision'], 'template_version' => $r['revision'] > 0 ? 3 : ($preview['template_version'] ?? 1), 'image_label' => $r['revision'] > 0 ? 'Imagen de la plantilla guardada; modo '.$this->mode($module, $code) : ($preview['image_label'] ?? '')]);
    }

    private function mode(string $module, string $code): string
    {
        return (string) TelegramMessageTemplate::where('module', $module)->where('event_code', $code)->value('image_mode');
    }
}
