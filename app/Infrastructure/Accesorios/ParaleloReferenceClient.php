<?php

namespace App\Infrastructure\Accesorios;

use App\Domain\Accesorios\Data\PricingInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ParaleloReferenceClient
{
    public function fetch(): array
    {
        try {
            $response = Http::acceptJson()->withOptions(['verify' => config('accesorios.reference_ca_bundle') ?: true])->connectTimeout(3)->timeout(6)->withoutRedirecting()->get(config('accesorios.reference_url'));
            $response->throw();
            if (strlen($response->body()) > 65536) {
                throw new \UnexpectedValueException;
            }
            $json = $response->json();
            if (! is_array($json) || ! isset($json['buy'],$json['timestamp']) || (! is_string($json['buy']) && ! is_int($json['buy']) && ! is_float($json['buy']))) {
                throw new \UnexpectedValueException;
            }
            $rate = PricingInput::rate((string) $json['buy'], 'reference');
            if (! is_string($json['timestamp']) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $json['timestamp'])) {
                throw new \UnexpectedValueException;
            }
            $date = CarbonImmutable::parse($json['timestamp'])->utc();
            if ($date->greaterThan(now()->addMinutes(5))) {
                throw new \UnexpectedValueException;
            }

            return ['provider' => 'paralelo.bo', 'rate_micros' => $rate, 'source_at' => $date, 'fingerprint' => hash('sha256', 'paralelo.bo|'.$date->format('Y-m-d\TH:i:s.u\Z').'|'.$rate)];
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['reference' => 'No se pudo obtener una referencia válida. Se conserva la última guardada; puedes usar dólar físico manual.']);
        }
    }
}
