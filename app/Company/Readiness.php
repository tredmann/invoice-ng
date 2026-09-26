<?php

declare(strict_types=1);

namespace App\Company;

/**
 * Whether a company may issue a Beleg, and what stands in the way.
 *
 * An object rather than a boolean because system design §8.1 requires the
 * missing prerequisites to be "reported as a list, not raised as an exception
 * halfway through issuing" — a caller needs to be able to name them.
 */
final readonly class Readiness
{
    /**
     * @param  list<ReadinessItem>  $items
     */
    public function __construct(public array $items) {}

    public function canIssue(): bool
    {
        return $this->blockers() === [];
    }

    /**
     * @return list<ReadinessItem>
     */
    public function blockers(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (ReadinessItem $item): bool => $item->blocks(),
        ));
    }

    /**
     * @return list<ReadinessItem>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (ReadinessItem $item): bool => ! $item->satisfied && $item->severity === Severity::Warning,
        ));
    }
}
