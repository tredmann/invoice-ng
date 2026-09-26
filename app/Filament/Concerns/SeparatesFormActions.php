<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Support\Enums\Alignment;

/**
 * Cancel at the far left, the saving action at the far right.
 *
 * The rule is written down in `.ai/guidelines/ui/core.blade.php`. It lives in
 * a trait rather than in each page so that it is applied rather than
 * remembered: Filament groups both buttons at one end and emits the submit
 * first, so following the rule by hand means getting two things right on every
 * form page.
 */
trait SeparatesFormActions
{
    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::Between;
    }

    /**
     * @return array<mixed>
     */
    protected function getFormActions(): array
    {
        $cancel = [];
        $rest = [];

        // Only cancel moves. Anything Filament puts between the two — "create
        // another", for instance — keeps the order it came in.
        foreach (parent::getFormActions() as $action) {
            if ($action instanceof Action && $action->getName() === 'cancel') {
                $cancel[] = $action;

                continue;
            }

            $rest[] = $action;
        }

        return [...$cancel, ...$rest];
    }
}
