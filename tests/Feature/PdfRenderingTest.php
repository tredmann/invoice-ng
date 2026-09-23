<?php

use Illuminate\Support\Facades\Process;
use Spatie\LaravelPdf\Facades\Pdf;

it('has the weasyprint binary available', function () {
    expect(Process::run('weasyprint --version')->successful())->toBeTrue();
});

it('renders a pdf containing german characters', function () {
    $path = storage_path('app/testing/render-smoke.pdf');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    // The umlauts are the point. A missing font stack produces a valid PDF
    // full of empty boxes, which no smoke test on file size would catch.
    Pdf::html('<h1>Rechnung</h1><p>Größe · Übertrag · Änderung · Weiß</p>')
        ->format('a4')
        ->save($path);

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path, false, null, 0, 5))->toBe('%PDF-')
        ->and(filesize($path))->toBeGreaterThan(1000);

    // Deliberately NOT asserting on the PDF's internals here. Font
    // dictionaries live inside compressed object streams, so grepping the
    // bytes would fail for reasons unrelated to fonts. Whether the umlauts
    // are letters or empty boxes is checked by eye in the next step.
    unlink($path);
});
