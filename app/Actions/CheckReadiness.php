<?php

declare(strict_types=1);

namespace App\Actions;

use App\Company\Readiness;
use App\Company\ReadinessItem;
use App\Company\Severity;
use App\Models\Company;

/**
 * The Bereitschaftsprüfung of system design §8.1.
 *
 * A company created through registration carries only a name and a legal form.
 * Issuing from it would not satisfy §14 UStG, and until this existed nothing in
 * the application could tell. It runs before the issuing transaction opens, so
 * a missing Steuernummer is a list on screen rather than an exception halfway
 * through a PDF render.
 *
 * Its only caller in this wave is the dashboard. The gate inside IssueDocument
 * and the dialogue in front of it belong to the invoicing wave and call this
 * same object — which is why it is an object with a stable answer rather than a
 * method on the settings page.
 */
final class CheckReadiness
{
    public function __invoke(Company $company): Readiness
    {
        $items = [
            new ReadinessItem(
                'address',
                $this->allPresent($company, ['street', 'postal_code', 'city']),
                Severity::Blocking,
            ),
            new ReadinessItem(
                'tax_identifier',
                $this->anyPresent($company, ['tax_number', 'vat_id']),
                Severity::Blocking,
            ),
        ];

        // Only asked of a legal form that has a register entry. An
        // Einzelunternehmen has no Registergericht to state, so the item is
        // absent rather than present and permanently unsatisfiable.
        if ($company->legal_form->isRegistered()) {
            $items[] = new ReadinessItem(
                'register',
                $this->allPresent($company, ['register_court', 'register_number', 'managing_directors']),
                Severity::Blocking,
            );
        }

        $items[] = new ReadinessItem(
            'number_range',
            $company->numberRange()->exists(),
            Severity::Blocking,
        );

        $items[] = new ReadinessItem('bank', $this->allPresent($company, ['iban']), Severity::Warning);
        $items[] = new ReadinessItem('logo', $this->allPresent($company, ['logo_path']), Severity::Warning);

        return new Readiness($items);
    }

    /**
     * @param  list<string>  $columns
     */
    private function allPresent(Company $company, array $columns): bool
    {
        return array_all($columns, fn (string $column): bool => trim((string) $company->getAttribute($column)) !== '');
    }

    /**
     * @param  list<string>  $columns
     */
    private function anyPresent(Company $company, array $columns): bool
    {
        return array_any($columns, fn (string $column): bool => trim((string) $company->getAttribute($column)) !== '');
    }
}
