<?php

declare(strict_types=1);

use App\Actions\DrawNextNumber;
use App\Actions\IssueDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\NumberRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * Draws one Belegnummer in each of $children forked processes, all released at
 * the same instant, each holding its transaction open for $hold seconds to
 * stand in for the PDF render the real issue operation holds the lock through.
 *
 * The parent's connection is closed before forking. A child that inherits an
 * open PDO shares one server-side connection with its parent, and whichever
 * closes it first takes the other down — which looks like a flaky test rather
 * than like the mistake it is.
 *
 * @return list<string> what each child produced, or `ERR: …` if it threw
 */
function drawInForkedChildren(Company $company, int $children, float $hold): array
{
    // A missing pcntl must fail this suite, never skip it: a concurrency test
    // that quietly does not run is exactly the decoration CLAUDE.md forbids.
    // docker/verify-image.sh asserts the extension for the same reason.
    expect(function_exists('pcntl_fork'))->toBeTrue()
        ->and(function_exists('posix_kill'))->toBeTrue();

    $files = [];

    for ($i = 0; $i < $children; $i++) {
        $files[$i] = (string) tempnam(sys_get_temp_dir(), 'belegnummer');
    }

    DB::disconnect();

    // A common release time, so the children genuinely overlap. Without it the
    // first child can finish before the second starts, and the test passes
    // against no lock at all.
    $startAt = microtime(true) + 0.5;
    $pids = [];

    for ($i = 0; $i < $children; $i++) {
        $pid = pcntl_fork();

        throw_if($pid === -1, RuntimeException::class, 'Could not fork a child for the concurrency test.');

        if ($pid === 0) {
            $result = 'ERR: the child produced nothing';

            try {
                $wait = (int) round(($startAt - microtime(true)) * 1_000_000);

                if ($wait > 0) {
                    Sleep::usleep($wait);
                }

                $result = DB::transaction(function () use ($company, $hold): string {
                    $number = (new DrawNextNumber)($company);
                    Sleep::usleep((int) round($hold * 1_000_000));

                    return $number;
                });
            } catch (Throwable $e) {
                $result = 'ERR: '.$e::class.': '.$e->getMessage();
            }

            file_put_contents($files[$i], $result);

            // SIGKILL rather than exit(): a forked child running PHPUnit's
            // shutdown handlers reports itself as a second test run and
            // corrupts the output of the real one.
            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = array_map(fn (string $file): string => (string) file_get_contents($file), $files);
    array_map(unlink(...), $files);

    return array_values($results);
}

it('runs without a wrapping transaction, so the rest of this file means something', function (): void {
    // If RefreshDatabase ever leaks into this suite, every test below turns
    // into theatre — the children would see no range and the transaction guard
    // could not be exercised. Asserted rather than assumed.
    expect(DB::transactionLevel())->toBe(0);
});

it('gives two concurrent draws distinct, consecutive numbers', function (): void {
    // The test tech-stack spec §11.3 calls the most awkward in the suite and
    // the one that actually protects the guarantee. Remove lockForUpdate() from
    // DrawNextNumber and both children read next_value = 1 and return RE-0001;
    // nothing else in the suite notices.
    $company = Company::factory()->create();
    NumberRange::factory()->for($company)->create([
        'prefix' => 'RE-',
        'padding' => 4,
        'next_value' => 1,
        'include_year' => false,
    ]);

    $numbers = drawInForkedChildren($company, 2, 0.3);

    foreach ($numbers as $number) {
        expect($number)->not->toStartWith('ERR:');
    }

    sort($numbers);

    expect($numbers)->toBe(['RE-0001', 'RE-0002']);

    $range = NumberRange::query()->where('company_id', $company->getKey())->sole();

    expect($range->next_value)->toBe(3)
        ->and($range->drawn_count)->toBe(2);
});

it('leaves no gap when four draws collide', function (): void {
    // Two children can pass by luck of scheduling. Four that all come back
    // distinct and consecutive is the shape §5 promises: no gap, no repeat.
    $company = Company::factory()->create();
    NumberRange::factory()->for($company)->create([
        'prefix' => null,
        'padding' => 3,
        'next_value' => 7,
        'include_year' => false,
    ]);

    $numbers = drawInForkedChildren($company, 4, 0.2);

    foreach ($numbers as $number) {
        expect($number)->not->toStartWith('ERR:');
    }

    sort($numbers);

    expect($numbers)->toBe(['007', '008', '009', '010']);
});

it('refuses to draw outside a transaction', function (): void {
    // Only possible in this suite: under RefreshDatabase the level is already
    // 1, so the guard could never be reached and the test would pass green
    // against a DrawNextNumber that had no guard at all.
    $company = Company::factory()->create();
    NumberRange::factory()->for($company)->create();

    expect(DB::transactionLevel())->toBe(0)
        ->and(fn (): string => (new DrawNextNumber)($company))->toThrow(LogicException::class);
});

/**
 * Runs the real Ausstellvorgang in $children forked processes, one draft each,
 * all released at the same instant.
 *
 * Unlike drawInForkedChildren() this holds the lock for as long as the work
 * genuinely takes — a WeasyPrint render and a ZUGFeRD merge — rather than for a
 * `usleep` standing in for it. That is the point: the lock is held across a
 * subprocess and a PDF rebuild, and this is the only test that says so.
 *
 * @param  list<string>  $documentIds
 * @return list<string> the Belegnummer each child produced, or `ERR: …`
 */
function issueInForkedChildren(array $documentIds): array
{
    expect(function_exists('pcntl_fork'))->toBeTrue();

    $files = [];

    foreach (array_keys($documentIds) as $i) {
        $files[$i] = (string) tempnam(sys_get_temp_dir(), 'ausstellen');
    }

    DB::disconnect();

    $startAt = microtime(true) + 0.5;
    $pids = [];

    foreach (array_values($documentIds) as $i => $documentId) {
        $pid = pcntl_fork();

        throw_if($pid === -1, RuntimeException::class, 'Could not fork a child for the concurrency test.');

        if ($pid === 0) {
            $result = 'ERR: the child produced nothing';

            try {
                $wait = (int) round(($startAt - microtime(true)) * 1_000_000);

                if ($wait > 0) {
                    Sleep::usleep($wait);
                }

                $document = Document::query()->findOrFail($documentId);
                $result = (string) (new IssueDocument)($document)->number;
            } catch (Throwable $e) {
                $result = 'ERR: '.$e::class.': '.$e->getMessage();
            }

            file_put_contents($files[$i], $result);

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = array_map(fn (string $file): string => (string) file_get_contents($file), $files);
    array_map(unlink(...), $files);

    return array_values($results);
}

it('gives two Belege issued at the same instant distinct, consecutive Belegnummern', function (): void {
    // Faked before the fork, so the children inherit it: this suite truncates
    // rather than rolling back, and without a fake disk it writes real invoice
    // PDFs into the development storage directory, where they look like
    // documents of companies that no longer exist.
    Storage::fake(config()->string('invoice.documents_disk'));

    // The guarantee §5 makes, exercised through the operation that actually
    // consumes it rather than through DrawNextNumber alone. Remove
    // lockForUpdate() and both children draw RE-…-0001, the unique index
    // refuses the second, and one of them comes back as ERR — while all the
    // Feature tests stay green.
    $company = issuableCompany();

    $drafts = [
        draftInvoice($company)->getKey(),
        draftInvoice($company)->getKey(),
    ];

    $numbers = issueInForkedChildren($drafts);

    foreach ($numbers as $number) {
        expect($number)->not->toStartWith('ERR:');
    }

    sort($numbers);

    $year = today()->year;

    expect($numbers)->toBe(["RE-{$year}-0001", "RE-{$year}-0002"]);

    $range = NumberRange::query()->where('company_id', $company->getKey())->sole();

    expect($range->next_value)->toBe(3)
        ->and($range->drawn_count)->toBe(2);

    // Both files exist and differ: one render did not overwrite the other.
    $paths = Document::query()
        ->where('company_id', $company->getKey())
        ->pluck('pdf_path')
        ->all();

    expect($paths)->toHaveCount(2)
        ->and($paths[0])->not->toBe($paths[1]);

    foreach ($paths as $path) {
        expect(Storage::disk(config()->string('invoice.documents_disk'))->exists($path))->toBeTrue();
    }
});
