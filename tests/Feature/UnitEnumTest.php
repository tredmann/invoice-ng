<?php

declare(strict_types=1);

use App\Enums\Unit;

it('is backed by the UN/ECE Rec 20 code itself', function (): void {
    // The code is the backing value so a Position stores it and the ZUGFeRD XML
    // gets it without a lookup. Spelling one of them wrong produces XML a
    // recipient's software rejects, and nothing else in the suite would notice.
    $codes = array_combine(
        array_map(fn (Unit $unit): string => $unit->name, Unit::cases()),
        array_map(fn (Unit $unit): string => $unit->value, Unit::cases()),
    );

    expect($codes)->toBe([
        'Piece' => 'H87',
        'Hour' => 'HUR',
        'Day' => 'DAY',
        'LumpSum' => 'LS',
        'Kilometre' => 'KMT',
    ]);
});

it('labels every unit in German', function (): void {
    foreach (Unit::cases() as $unit) {
        expect($unit->getLabel())
            ->not->toContain('unit.')
            ->and($unit->getLabel())->not->toBe('');
    }

    expect(Unit::Hour->getLabel())->toBe('Stunde')
        ->and(Unit::LumpSum->getLabel())->toBe('Pauschal');
});
