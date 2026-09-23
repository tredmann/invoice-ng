<?php

use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use Illuminate\Support\Facades\Storage;
use Parental\HasChildren;
use Pontedilana\PhpWeasyPrint\Pdf;

it('has the runtime packages the stack spec allows', function () {
    // Parental\HasChildren is a trait, not a class, so class_exists() would
    // always report false regardless of whether the package is installed.
    expect(trait_exists(HasChildren::class))->toBeTrue()
        ->and(class_exists(Money::class))->toBeTrue()
        ->and(class_exists(Spatie\LaravelPdf\Facades\Pdf::class))->toBeTrue()
        ->and(class_exists(Pdf::class))->toBeTrue()
        ->and(class_exists(ZugferdDocumentBuilder::class))->toBeTrue();
});

it('refuses money arithmetic it cannot represent exactly', function () {
    // This is the discriminator. 19.99 * 3 in floats prints "59.97" too,
    // because PHP's float-to-string cast rounds at 14 significant digits —
    // so asserting that value proves nothing about float vs decimal.
    // brick/money instead REFUSES an operation whose exact result it cannot
    // represent, unless given an explicit rounding mode. A float
    // implementation would silently return 3.3333…
    expect(fn () => Money::of('10.00', 'EUR')->dividedBy(3))
        ->toThrow(RoundingNecessaryException::class);

    // And with a rounding mode stated, the result is exact to the cent.
    expect(Money::of('10.00', 'EUR')->dividedBy(3, RoundingMode::HalfUp)->getAmount()->__toString())
        ->toBe('3.33');
});

it('resolves the documents disk from configuration', function () {
    expect(config('invoice.documents_disk'))->toBe('local');

    Storage::disk(config('invoice.documents_disk'))->put('probe.txt', 'ok');

    expect(Storage::disk(config('invoice.documents_disk'))->get('probe.txt'))->toBe('ok');

    Storage::disk(config('invoice.documents_disk'))->delete('probe.txt');
});

it('actually reads the documents disk from the environment, not a hardcoded default', function () {
    // The test above would still pass even if config/invoice.php hardcoded
    // 'local' instead of reading env('INVOICE_DOCUMENTS_DISK'), because
    // 'local' is also the default. This proves the value genuinely comes
    // from the environment by overriding it and re-evaluating the config
    // file directly. putenv() does not work here — Laravel's Env repository
    // gives $_SERVER precedence — so $_SERVER is set and used instead.
    $_SERVER['INVOICE_DOCUMENTS_DISK'] = 's3';

    try {
        expect((require base_path('config/invoice.php'))['documents_disk'])->toBe('s3');
    } finally {
        unset($_SERVER['INVOICE_DOCUMENTS_DISK']);
    }
});
