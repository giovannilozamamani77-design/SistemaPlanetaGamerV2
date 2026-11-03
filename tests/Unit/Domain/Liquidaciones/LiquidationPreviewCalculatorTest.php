<?php

use App\Domain\Liquidaciones\Calculators\LiquidationPreviewCalculator;

it('keeps the current operating capital exactly by withdrawing only the available excess', function () {
    $calculator = new LiquidationPreviewCalculator;
    $result = $calculator->calculate(['efectivo' => 500000, 'bcp' => 1505000], 2000000, 'bcp');
    expect($result['amount_cents'])->toBe(5000)->and($result['after_balances']['bcp'])->toBe(1500000)
        ->and($result['capital_after_cents'])->toBe(2000000)->and($result['can_liquidate'])->toBeTrue();
    $temporary = $calculator->calculate(['efectivo' => 500000, 'yape' => 3505000], 4000000, 'yape');
    expect($temporary['amount_cents'])->toBe(5000)->and($temporary['capital_after_cents'])->toBe(4000000);
});

it('never uses custody or invents a transfer when capital or source money is missing', function () {
    $calculator = new LiquidationPreviewCalculator;
    $custody = $calculator->calculate(['efectivo' => 500000, 'bcp' => 1515000], 2000000, 'bcp', ['bcp' => 10000]);
    expect($custody['amount_cents'])->toBe(5000)->and($custody['after_balances']['bcp'])->toBe(1510000);
    $missing = $calculator->calculate(['efectivo' => 1999900, 'bcp' => 0], 2000000, 'bcp');
    expect($missing['status'])->toBe('FALTANTE')->and($missing['amount_cents'])->toBe(0)->and($missing['can_liquidate'])->toBeFalse();
    $source = $calculator->calculate(['efectivo' => 2005000, 'bcp' => 0], 2000000, 'bcp');
    expect($source['status'])->toBe('ORIGEN_INSUFICIENTE')->and($source['after_balances'])->toBeNull();
});
