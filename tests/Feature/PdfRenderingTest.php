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

    // Extract the text back out. This catches INPUT-ENCODING bugs: a %PDF- header
    // and a plausible file size are both present even when every German character
    // is mojibake.
    //
    // It does NOT catch font-coverage bugs. pdftotext reads through the PDF's
    // ToUnicode CMap, which is populated whether or not the font contains a
    // renderable glyph — so if the fonts were dropped from the image, this would
    // still extract "Größe" while the page rendered empty boxes. That failure is
    // still only visible by looking at the rendered page. Do that by hand after
    // any change to fonts, to the base image, or to the WeasyPrint version.
    $text = Process::run(['pdftotext', $path, '-'])->output();

    expect($text)->toContain('Rechnung')
        ->and($text)->toContain('Größe')
        ->and($text)->toContain('Übertrag')
        ->and($text)->toContain('Änderung')
        ->and($text)->toContain('Weiß');

    // Not a substitute for looking, but it does catch the case where no font was
    // embedded at all.
    //
    // pdffonts' columns are fixed-width (verified against the installed
    // poppler-utils 22.12.0: name=36, type=17, encoding=16, emb=3, sub=3, uni=3,
    // then "object ID"), so slicing the "emb" column directly is what makes this
    // sound. A naive toContain('yes') would not be: the "type" column itself
    // contains a space for CID fonts (e.g. "CID TrueType"), and "sub"/"uni" are
    // also yes/no columns that are frequently "yes" independent of embedding —
    // so a plain substring search would pass even when every font's "emb" column
    // reads "no".
    $fontsOutput = Process::run(['pdffonts', $path])->output();
    $fontLines = array_filter(
        array_slice(preg_split('/\r?\n/', $fontsOutput) ?: [], 2),
        fn (string $line): bool => trim($line) !== ''
    );

    expect($fontLines)->not->toBeEmpty();

    foreach ($fontLines as $line) {
        expect(trim(substr($line, 72, 3)))->toBe('yes');
    }

    unlink($path);
});
