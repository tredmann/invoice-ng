<?php

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

it('does arithmetic on money without floats', function () {
    $total = Money::of('19.99', 'EUR')->multipliedBy(3);

    expect($total->getAmount()->__toString())->toBe('59.97');
});

it('resolves the documents disk from configuration', function () {
    expect(config('invoice.documents_disk'))->toBe('local');

    Storage::disk(config('invoice.documents_disk'))->put('probe.txt', 'ok');

    expect(Storage::disk(config('invoice.documents_disk'))->get('probe.txt'))->toBe('ok');

    Storage::disk(config('invoice.documents_disk'))->delete('probe.txt');
});
