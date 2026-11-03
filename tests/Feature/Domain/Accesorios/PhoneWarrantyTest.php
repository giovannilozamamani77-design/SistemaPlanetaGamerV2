<?php

use App\Domain\Accesorios\Calculators\PhoneWarrantyCalculator;
use App\Domain\Accesorios\Models\PhoneUnit;
use Carbon\CarbonImmutable;

it('C264 uses calendar months in Bolivia with no overflow and freezes coverage terms', function () {
    $new = new PhoneUnit(['condition' => 'NEW']);
    $used = new PhoneUnit(['condition' => 'USED']);
    $calc = app(PhoneWarrantyCalculator::class);
    $a = $calc->calculate($new, CarbonImmutable::parse('2026-02-01 02:00:00', 'UTC'));
    $b = $calc->calculate($used, CarbonImmutable::parse('2026-02-01 02:00:00', 'UTC'));
    expect($a['starts_on'])->toBe('2026-01-31')->and($a['expires_on'])->toBe('2026-04-30')->and($a['months'])->toBe(3);
    expect($b['expires_on'])->toBe('2026-03-31')->and($b['months'])->toBe(2);
    expect($a['terms'])->toContain('hardware', 'humedad', 'reparaciones externas')->and($a['terms_version'])->toBe('C264-1');
});
