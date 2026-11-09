<?php

use App\Domain\Cajas\Calculators\CashBoxClosingBalancePreview;

it('compares totals in cents allowing redistribution and blank balances', function () {
    $calculator = new CashBoxClosingBalancePreview;
    $result = $calculator->calculate(['efectivo' => '10.10', 'bcp' => '', 'recarga_viva' => '0.20'], ['efectivo' => 0, 'bcp' => 1000, 'recarga_viva' => 30]);
    expect($result['total_cents'])->toBe(1030)->and($result['difference_cents'])->toBe(0)->and($result['kind'])->toBe('balanced');
    expect($calculator->calculate([], ['efectivo' => 100])['difference_cents'])->toBe(-100);
});

it('does not present invalid money as a valid total', function ($value) {
    $result = (new CashBoxClosingBalancePreview)->calculate(['efectivo' => $value], ['efectivo' => 0]);
    expect($result['valid'])->toBeFalse()->and($result['total_cents'])->toBeNull();
})->with(['negative' => '-1', 'exponent' => '1e3', 'extra decimals' => '1.001', 'text' => 'abc', 'array' => [[]]]);

it('rejects components outside the active session', function () {
    expect((new CashBoxClosingBalancePreview)->calculate(['otra' => '100'], ['efectivo' => 0])['valid'])->toBeFalse();
});

it('includes the mobile decimal comma in the closing total', function () {
    $result = (new CashBoxClosingBalancePreview)->calculate(
        ['efectivo' => '4859.90', 'bcp' => '11013.42', 'yape' => '227,38'],
        ['efectivo' => 1600000, 'bcp' => 0, 'yape' => 0],
    );
    expect($result['total_cents'])->toBe(1610070)->and($result['difference_cents'])->toBe(10070);
});
