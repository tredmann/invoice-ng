<?php

declare(strict_types=1);

namespace App\Actions;

use App\Company\Readiness;
use App\Company\ReadinessItem;
use App\Company\Severity;
use App\Models\Company;
use App\Rules\TaxNumber;
use App\Rules\VatId;

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
            $this->taxIdentifier($company),
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
     * §14 Abs. 4 Nr. 2 UStG wants a Steuernummer **or** a USt-IdNr on every
     * invoice. This asks for one that is actually usable, not merely for a
     * field that is not blank.
     *
     * That distinction was learned from a real invoice. A company whose
     * USt-IdNr read `iuoiuoi` passed this check, issued, froze the value into
     * its Festschreibung and produced a ZUGFeRD file that fails BR-CO-09 —
     * which nothing here could detect and nothing can now correct, because an
     * issued Beleg is unveränderlich.
     *
     * **Every identifier that is present must be valid**, not just one of
     * them: a valid Steuernummer does not rescue a document that also carries
     * a malformed USt-IdNr, because both are printed and both go into the XML.
     */
    private function taxIdentifier(Company $company): ReadinessItem
    {
        $taxNumber = $this->trimmed($company, 'tax_number');
        $vatId = $this->trimmed($company, 'vat_id');

        if ($taxNumber === null && $vatId === null) {
            return new ReadinessItem('tax_identifier', false, Severity::Blocking);
        }

        $valid = ($taxNumber === null || TaxNumber::isValid($taxNumber))
            && ($vatId === null || VatId::isValid($vatId));

        return new ReadinessItem('tax_identifier', $valid, Severity::Blocking, 'invalid');
    }

    private function trimmed(Company $company, string $column): ?string
    {
        $value = trim((string) $company->getAttribute($column));

        return $value === '' ? null : $value;
    }
}
