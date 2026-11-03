<?php

use App\Domain\Arqueos\Calculators\CashCountCalculator;

it('adds pieces and packed money once with exact cent precision', function () {
    $result = (new CashCountCalculator)->calculate(['b100' => '3', 'm5' => '3', 'm010' => '20'], ['b100' => '50', 'm5' => '1000', 'm010' => '0.20']);
    expect($result['valid'])->toBeTrue()->and($result['snapshot']['loose_total_cents'])->toBe(31700)
        ->and($result['snapshot']['packed_total_cents'])->toBe(105020)->and($result['snapshot']['total_cents'])->toBe(136720)
        ->and($result['snapshot']['row_total_cents']['m5'])->toBe(101500);
});

it('normalizes absent and blank counts to zero and checks accepted cash coherence', function () {
    $calculator = new CashCountCalculator;
    $result = $calculator->calculate(['b100' => '', 'm5' => null], ['m010' => null]);
    expect($result['snapshot']['total_cents'])->toBe(0)->and($result['snapshot']['quantities']['b200'])->toBe(0)
        ->and($calculator->matchesCash($result['snapshot'], ''))->toBeTrue()
        ->and($calculator->matchesCash($result['snapshot'], '0.00'))->toBeTrue()
        ->and($calculator->matchesCash($result['snapshot'], '1'))->toBeFalse()
        ->and($calculator->matchesCash($result['snapshot'], '-1'))->toBeFalse();
});

it('rejects invalid quantities and packed amounts without presenting a valid total', function (array $quantities, array $packed) {
    $result = (new CashCountCalculator)->calculate($quantities, $packed);
    expect($result['valid'])->toBeFalse()->and($result['snapshot'])->toBeNull()->and($result['errors'])->not->toBeEmpty();
})->with([
    'negative pieces' => [['b100' => '-1'], []],
    'fractional pieces' => [['m5' => '1.5'], []],
    'exponent pieces' => [['b100' => '1e3'], []],
    'too many pieces' => [['b100' => '1000000'], []],
    'nested pieces' => [['b100' => []], []],
    'negative packed money' => [[], ['m5' => '-1']],
    'fractional cent' => [[], ['b100' => '1.001']],
    'nested packed money' => [[], ['b100' => []]],
    'unknown cut' => [['otra' => '1'], []],
    'unknown packed cut' => [[], ['otra' => '1']],
    'total overflow' => [[], ['b100' => '999999999.99', 'm5' => '0.01']],
]);
