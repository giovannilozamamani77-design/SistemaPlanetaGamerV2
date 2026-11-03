<?php

use App\Domain\Prestamos\Calculators\LoanRecoveryCalculator;

it('C237 separates loan earnings from sale profit and recovers all repairs', function (int $price, int $saleProfit) {
    $quote = (new LoanRecoveryCalculator)->calculate(20000, 3000, 4000, 20000, $price);
    expect($quote['minimum_cents'])->toBe(47000)->and($quote['loan_profit_cents'])->toBe(7000)->and($quote['sale_profit_cents'])->toBe($saleProfit)->and($quote['total_profit_cents'])->toBe(7000 + $saleProfit)->and($quote['invested_cents'])->toBe(40000);
})->with([[60000, 13000], [47000, 0]]);

it('C237 rejects prices even one cent below the minimum and negative repair costs', function () {
    $calc = new LoanRecoveryCalculator;
    expect(fn () => $calc->calculate(20000, 3000, 4000, 20000, 46999))->toThrow(InvalidArgumentException::class);
    expect(fn () => $calc->calculate(20000, 3000, 4000, -1, 60000))->toThrow(InvalidArgumentException::class);
});
