<?php

use App\Domain\Cajas\Calculators\CashBoxClosingCounterPreview;

it('keeps independent deltas and does not invent activity for the first reference', function () {
    $preview = new CashBoxClosingCounterPreview;
    $result = $preview->calculate(['general' => '190', 'fixed' => '80'], ['counters' => ['general' => ['value' => 100], 'fixed' => ['value' => 50]]]);
    expect($result['valid'])->toBeTrue()->and($result['counters']['general']['delta'])->toBe(90)->and($result['counters']['fixed']['delta'])->toBe(30);
    $first = $preview->calculate(['general' => '190', 'fixed' => '80'], ['counters' => ['general' => ['value' => null], 'fixed' => ['value' => null]]]);
    expect($first['valid'])->toBeTrue()->and($first['counters']['general']['delta'])->toBeNull()->and($first['counters']['fixed']['delta'])->toBeNull();
});

it('rejects counter rollback and invalid accumulated quantities', function ($reading) {
    $result = (new CashBoxClosingCounterPreview)->calculate(['general' => $reading, 'fixed' => '50'], ['counters' => ['general' => ['value' => 100], 'fixed' => ['value' => 50]]]);
    expect($result['valid'])->toBeFalse()->and($result['counters']['general']['error'])->not->toBeNull()->and($result['counters']['general']['delta'])->toBeNull();
})->with(['rollback' => '99', 'negative' => '-1', 'fractional' => '100.5', 'empty' => '', 'exponent' => '1e3', 'oversize' => '1000000000', 'array' => [[]]]);
