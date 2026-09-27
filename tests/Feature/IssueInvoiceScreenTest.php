<?php

declare(strict_types=1);

use App\Actions\IssueDocument;
use App\Enums\DocumentStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Document;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ausstellen from the screen the owner actually uses, and what an issued
 * Rechnung looks like afterwards.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

it('issues from the detail page and shows the drawn Belegnummer', function (): void {
    $company = issuableCompany();
    $invoice = draftInvoice($company);
    actInCompany($company);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->callAction('issue')
        ->assertHasNoActionErrors()
        ->assertNotified();

    $issued = $invoice->fresh();

    expect($issued?->status)->toBe(DocumentStatus::Issued)
        ->and($issued?->number)->toBe('RE-'.today()->year.'-0001');

    Storage::disk('local')->assertExists(pdfPathOf($issued ?? $invoice));
});

/**
 * Filament hands back either a plain string or something renderable, depending
 * on how the modal text was configured; both read the same on screen.
 */
function toText(Htmlable|string|null $value): string
{
    return $value instanceof Htmlable
        ? $value->toHtml()
        : (string) $value;
}

/**
 * Mounts the Ausstellen action and hands back what its dialog would say.
 *
 * Read off the mounted action rather than out of the page HTML: Filament 5
 * renders a modal in the browser, not into the component's markup, so an
 * assertSee() against the page would pass whatever the dialog said — including
 * nothing at all.
 *
 * @return array{heading: string, body: string, submit: bool}
 */
function issueDialog(Document $invoice): array
{
    $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->mountAction('issue')
        ->instance();

    assert($page instanceof ViewInvoice);

    $action = $page->getMountedAction();

    return [
        'heading' => strip_tags(toText($action?->getModalHeading())),
        'body' => strip_tags(toText($action?->getModalDescription()), '<a>'),
        'submit' => $action?->getModalSubmitAction() instanceof Action,
    ];
}

it('names the missing Angaben instead of offering to issue', function (): void {
    // The Bereitschaftsprüfung the mockup draws. What makes this fail is an
    // action that opens a generic confirm dialog and then errors — the owner
    // would learn that something went wrong rather than what to fix.
    $company = issuableCompany(['tax_number' => null, 'vat_id' => null]);
    $invoice = draftInvoice($company);
    actInCompany($company);

    $dialog = issueDialog($invoice);

    expect($dialog['heading'])->toBe('Noch nicht bereit')
        ->and($dialog['body'])->toContain('Steuernummer oder USt-IdNr.')
        ->and($dialog['body'])->toContain('Zu den Einstellungen')
        // No way to confirm. A button that leads to an error is worse than no
        // button.
        ->and($dialog['submit'])->toBeFalse();

    expect($invoice->fresh()?->status)->toBe(DocumentStatus::Draft);
});

it('says what issuing makes irreversible before it does it', function (): void {
    $company = issuableCompany();
    $invoice = draftInvoice($company);
    actInCompany($company);

    $dialog = issueDialog($invoice);

    expect($dialog['heading'])->toBe('Rechnung ausstellen?')
        ->and($dialog['body'])->toContain('Storno')
        ->and($dialog['submit'])->toBeTrue();
});

it('lists a missing logo as recommended rather than as a blocker', function (): void {
    // A missing logo never stops an invoice. It appears under the quiet
    // heading, and the dialog still offers to issue.
    $company = issuableCompany(['logo_path' => null]);
    $invoice = draftInvoice($company);
    actInCompany($company);

    $dialog = issueDialog($invoice);

    expect($dialog['heading'])->toBe('Rechnung ausstellen?')
        ->and($dialog['body'])->toContain('Empfohlen, aber kein Hindernis')
        ->and($dialog['body'])->toContain('Logo')
        ->and($dialog['submit'])->toBeTrue();
});

it('hides Ausstellen, Bearbeiten and Löschen once a Beleg is issued', function (): void {
    $company = issuableCompany();
    $issued = (new IssueDocument)(draftInvoice($company));
    actInCompany($company);

    Livewire::test(ViewInvoice::class, ['record' => $issued->getKey()])
        ->assertActionHidden('issue')
        ->assertActionHidden('edit')
        ->assertActionHidden('delete')
        ->assertActionVisible('download');
});

it('refuses the edit page of an issued Beleg', function (): void {
    /** @var TestCase $this */
    $company = issuableCompany();
    $issued = (new IssueDocument)(draftInvoice($company));
    $user = actInCompany($company);

    // A bookmarked edit URL must not open a form that cannot be saved — and
    // must never reach writeLineItems(), which would try to rewrite Positionen
    // the model forbids touching.
    $this->actingAs($user)
        ->get(InvoiceResource::getUrl('edit', ['record' => $issued]))
        ->assertNotFound();
});

it('serves the frozen file rather than rendering a new one', function (): void {
    $company = issuableCompany();
    $issued = (new IssueDocument)(draftInvoice($company));
    $stored = (string) Storage::disk('local')->get(pdfPathOf($issued));
    actInCompany($company);

    Livewire::test(ViewInvoice::class, ['record' => $issued->getKey()])
        ->callAction('download')
        // The stored bytes, not a fresh render: §4 says the file is served from
        // storage forever after and never regenerated.
        ->assertFileDownloaded($issued->number.'.pdf', $stored);
});

it('shows the frozen address on the detail page, not the customer of today', function (): void {
    /** @var TestCase $this */
    $company = issuableCompany();
    $issued = (new IssueDocument)(draftInvoice($company));
    $user = actInCompany($company);

    customerOf($issued)->forceFill(['name' => 'Umbenannt GmbH', 'city' => 'Köln'])->save();

    $this->actingAs($user)
        ->get(InvoiceResource::getUrl('view', ['record' => $issued]))->assertOk()->assertSeeHtml('Bauer &amp; Kollegen GmbH')
        ->assertSee('Hamburg')
        ->assertDontSee('Umbenannt GmbH')
        ->assertDontSee('Köln');
});

it('shows the Fälligkeitsdatum and the Verlauf entry once issued', function (): void {
    /** @var TestCase $this */
    $company = issuableCompany();
    $issued = (new IssueDocument)(draftInvoice($company));
    $user = actInCompany($company);

    $this->actingAs($user)
        ->get(InvoiceResource::getUrl('view', ['record' => $issued]))
        ->assertOk()
        // 25.09. plus the 14-day Zahlungsziel, not the „14 Tage ab Ausstellung"
        // hint a draft shows.
        ->assertSee('Fällig am 09.10.2026')
        ->assertSee('Ausgestellt')
        ->assertSee('Nummer '.$issued->number)
        ->assertSee('Erstellt');
});

it('badges an overdue Rechnung and leaves the others alone', function (): void {
    /** @var TestCase $this */
    $company = issuableCompany();
    $late = (new IssueDocument)(draftInvoice($company));
    $onTime = (new IssueDocument)(draftInvoice($company));

    $late->forceFill(['due_on' => today()->subDay()])->saveQuietly();
    $onTime->forceFill(['due_on' => today()->addDay()])->saveQuietly();

    $user = actInCompany($company);

    $this->actingAs($user)
        ->get(InvoiceResource::getUrl('view', ['record' => $late]))
        ->assertOk()
        ->assertSee('Überfällig');

    $this->actingAs($user)
        ->get(InvoiceResource::getUrl('view', ['record' => $onTime]))
        ->assertOk()
        ->assertDontSee('Überfällig');
});

it('filters the list by status', function (): void {
    $company = issuableCompany();
    $issued = (new IssueDocument)(draftInvoice($company));
    $draft = draftInvoice($company);
    actInCompany($company);

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$issued, $draft])
        ->filterTable('status', DocumentStatus::Issued->value)
        ->assertCanSeeTableRecords([$issued])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('finds an issued Rechnung by its Belegnummer', function (): void {
    $company = issuableCompany();
    $issued = (new IssueDocument)(draftInvoice($company));
    $other = draftInvoice($company);
    actInCompany($company);

    Livewire::test(ListInvoices::class)
        ->searchTable((string) $issued->number)
        ->assertCanSeeTableRecords([$issued])
        ->assertCanNotSeeTableRecords([$other]);
});

it('keeps the issue action inside its own company', function (): void {
    $mine = issuableCompany();
    $theirs = issuableCompany(['name' => 'Andere Firma']);
    $theirDraft = draftInvoice($theirs);

    actInCompany($mine);

    expect(fn () => Livewire::test(ListInvoices::class)
        ->callAction(TestAction::make('issue')->table($theirDraft)))
        ->toThrow(ActionNotResolvableException::class);

    expect($theirDraft->fresh()?->status)->toBe(DocumentStatus::Draft);
});

it('leaves the draft alone when issuing fails', function (): void {
    // The owner sees a failure notice, and the Entwurf is exactly as it was —
    // no number consumed, nothing half-written.
    config()->set('laravel-pdf.weasyprint.binary', '/nonexistent/weasyprint');

    $company = issuableCompany();
    $invoice = draftInvoice($company);
    actInCompany($company);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->callAction('issue')
        ->assertNotified('Die Rechnung konnte nicht ausgestellt werden.');

    expect($invoice->fresh()?->status)->toBe(DocumentStatus::Draft)
        ->and($invoice->fresh()?->number)->toBeNull()
        ->and((int) $company->numberRange()->first()?->next_value)->toBe(1);
});

it('offers the first invoice from the dashboard now that there are invoices', function (): void {
    /** @var TestCase $this */
    $company = issuableCompany();
    $user = actInCompany($company);

    // The card said „Rechnungen gibt es noch nicht" for a wave after they did.
    $this->actingAs($user)
        ->get(route('filament.admin.pages.dashboard', ['tenant' => $company]))
        ->assertOk()
        ->assertSee(InvoiceResource::getUrl('create'))
        ->assertDontSee('Rechnungen gibt es noch nicht');
});
