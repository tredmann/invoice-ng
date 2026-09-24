<?php

use Illuminate\Support\Facades\Process;
use Spatie\LaravelPdf\Facades\Pdf;

it('has the weasyprint binary available', function (): void {
    expect(Process::run('weasyprint --version')->successful())->toBeTrue();
});

it('renders a pdf containing german characters', function (): void {
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
    // Anchored on the end of the line ("emb sub uni objectID gen") instead of a
    // fixed byte offset: pdffonts pads its name/type/encoding columns to fit
    // their content rather than truncating, so a long subset name or font type
    // would silently shift every later column right and make a byte-offset read
    // wrong without the assertion failing. A naive toContain('yes') isn't sound
    // either — "yes" can appear in any of "emb"/"sub"/"uni" on any row of the
    // whole multi-line blob — so this matches each row's trailing
    // "emb sub uni objectID gen" shape directly and reads the first captured
    // group as the "emb" column.
    $fontsOutput = Process::run(['pdffonts', $path])->output();
    $fontLines = array_filter(
        array_slice(preg_split('/\r?\n/', $fontsOutput) ?: [], 2),
        fn (string $line): bool => trim($line) !== ''
    );

    expect($fontLines)->not->toBeEmpty();

    foreach ($fontLines as $line) {
        $matched = preg_match('/(yes|no)\s+(yes|no)\s+(yes|no)\s+\d+\s+\d+$/', trim($line), $matches);

        expect($matched)->toBe(1);
        expect($matches[1] ?? null)->toBe('yes'); // the emb column
    }

    unlink($path);
});
