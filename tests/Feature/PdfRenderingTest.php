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
    Pdf::html('<!doctype html><html><head><meta charset="utf-8"></head><body><h1>Rechnung</h1><p>Größe · Übertrag · Änderung · Weiß</p></body></html>')
        ->format('a4')
        ->save($path);

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path, false, null, 0, 5))->toBe('%PDF-')
        ->and(filesize($path))->toBeGreaterThan(1000);

    // Deliberately NOT grepping the raw PDF bytes for font markers: font
    // dictionaries live inside compressed object streams, so that would
    // fail for reasons unrelated to fonts. Instead, extract the text back
    // out with pdftotext. This is what makes the test able to fail for the
    // reason it exists: a %PDF- header and a plausible file size are both
    // present even when every German character in the document is mojibake
    // (e.g. UTF-8 input read back as Latin-1/windows-1252).
    $text = Process::run("pdftotext {$path} -")->output();

    expect($text)->toContain('Rechnung')
        ->and($text)->toContain('Größe')
        ->and($text)->toContain('Übertrag')
        ->and($text)->toContain('Änderung')
        ->and($text)->toContain('Weiß');

    unlink($path);
});
