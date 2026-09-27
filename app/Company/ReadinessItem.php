<?php

declare(strict_types=1);

namespace App\Company;

/**
 * One line of the Bereitschaftsprüfung: a prerequisite, whether the company
 * meets it, and what it costs to be missing.
 */
final readonly class ReadinessItem
{
    /**
     * @param  string  $reason  Which unsatisfied wording applies. „missing" is
     *                          the usual one; an item that is present but wrong
     *                          says so instead, because „fill this in" is bad
     *                          advice for a field that is already filled in.
     */
    public function __construct(
        public string $key,
        public bool $satisfied,
        public Severity $severity,
        public string $reason = 'missing',
    ) {}

    public function label(): string
    {
        return (string) __("company.readiness.{$this->key}.label");
    }

    /**
     * The line beneath the label — what is missing, or what was found. Both
     * come from the same key prefix so a new item cannot be added with only
     * half its wording.
     */
    public function hint(): string
    {
        return (string) __("company.readiness.{$this->key}.".($this->satisfied ? 'ok' : $this->reason));
    }

    public function blocks(): bool
    {
        return ! $this->satisfied && $this->severity === Severity::Blocking;
    }
}
