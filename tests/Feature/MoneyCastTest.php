<?php

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Models\Company;
use Brick\Money\Money;

it('reads a bigint of cents as euro', function (): void {
    $money = (new MoneyCast)->get(new Company, 'total', 199900, []);

    // Nullsafe because get() is contractually nullable; a null would fail every
    // line below rather than slip through, since (string) null is not '1999.00'.
    expect($money)->toBeInstanceOf(Money::class)
        ->and((string) $money?->getAmount())->toBe('1999.00')
        ->and($money?->getCurrency()->getCurrencyCode())->toBe('EUR');
});

it('writes euro back as the same bigint of cents', function (): void {
    $cents = (new MoneyCast)->set(new Company, 'total', Money::of('1999.00', 'EUR'), []);

    expect($cents)->toBe(199900);
});

it('keeps null as null in both directions', function (): void {
    expect((new MoneyCast)->get(new Company, 'total', null, []))->toBeNull()
        ->and((new MoneyCast)->set(new Company, 'total', null, []))->toBeNull();
});

it('refuses anything that is not money', function (mixed $value): void {
    // A float is the one that matters — 19.99 assigned to a money attribute is
    // exactly the mistake the cast exists to make impossible. An int is refused
    // too, because "199900" and "1999" are both plausible readings of it and a
    // cast that guesses is worse than one that asks.
    expect(fn (): ?int => (new MoneyCast)->set(new Company, 'total', $value, []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'float' => [19.99],
    'int' => [1999],
    'string' => ['19.99'],
]);
