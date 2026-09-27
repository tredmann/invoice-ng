<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Invoice;
use App\Models\NumberRange;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

it('shows neither default widget on the company dashboard', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')->assertOk()->assertDontSeeHtml('fi-account-widget')->assertDontSeeHtml('fi-filament-info-widget');
});

it('points an empty dashboard at the company settings', function (): void {
    /** @var TestCase $this */
    // The empty state became the mockup's „Erste Schritte" card when the
    // Bereitschaftsprüfung landed. What it must still do is say what to do next
    // and link there.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Erste Schritte')
        ->assertSee('Firmendaten vervollständigen')
        ->assertSeeHtml('href="'.url('/admin/acme-gmbh/settings').'"');
});

it('names on the dashboard what stops a company issuing', function (): void {
    /** @var TestCase $this */
    // A company from the factory has its identity block but no Nummernkreis,
    // so exactly one blocker should be named — and the two warnings must not
    // be presented as blockers.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Ohne diese Angaben lässt sich nichts ausstellen: Nummernkreis')
        ->assertSee('Empfohlen, aber kein Hindernis: Logo');
});

it('does not call a company blocked over a missing logo alone', function (): void {
    /** @var TestCase $this */
    // The load-bearing half of the severity split: a company with everything
    // the law asks for, an IBAN and no logo, is ready. A check that treated
    // §8.1's four items as equals would fail here.
    $company = Company::factory()->create(['name' => 'Acme GmbH', 'logo_path' => null]);
    NumberRange::factory()->for($company)->create();
    $user = User::factory()->create();
    $user->companies()->attach($company);

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Vollständig – aus dieser Firma lässt sich ausstellen.')
        ->assertDontSee('Ohne diese Angaben');
});

/**
 * A company with figures, reachable at /admin/acme-gmbh, and the user to see
 * them. Issued by force-fill rather than by IssueDocument: these tests are
 * about what the screen prints, not about the Ausstellvorgang.
 */
function dashboardCompany(TestCase $test): Company
{
    $company = issuableCompany(['name' => 'Acme GmbH']);

    $user = User::factory()->create();
    $user->companies()->attach($company);
    $test->actingAs($user);

    return $company;
}

function issuedForDashboard(Company $company, string $net, string $gross, string $dueOn, ?string $customer = null): Invoice
{
    $invoice = Invoice::factory()->for($company)->issued()->create([
        'issued_on' => today()->toDateString(),
        'due_on' => $dueOn,
        'net_total' => Money::of($net, 'EUR'),
        'tax_total' => Money::zero('EUR'),
        'gross_total' => Money::of($gross, 'EUR'),
    ]);

    if ($customer !== null) {
        $invoice->customer?->forceFill(['name' => $customer])->save();
    }

    return $invoice;
}

it('prints the Umsatz of the month and the year once something is issued', function (): void {
    /** @var TestCase $this */
    $company = dashboardCompany($this);
    issuedForDashboard($company, '1140.00', '1356.60', today()->addDays(14)->toDateString());

    $this->get('/admin/acme-gmbh')
        ->assertOk()->assertSee('Übersicht')->assertSeeHtml('1.140,00')
        ->assertSee('Umsatzverlauf')
        // And the onboarding card is gone.
        ->assertDontSee('Erste Schritte');
});

it('keeps Erste Schritte for a company that has only Entwürfe', function (): void {
    /** @var TestCase $this */
    $company = dashboardCompany($this);
    Invoice::factory()->for($company)->create();

    $this->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Erste Schritte')
        ->assertDontSee('Umsatzverlauf');
});

it('prints the Überfällig figure with the number of Rechnungen behind it', function (): void {
    /** @var TestCase $this */
    $company = dashboardCompany($this);
    issuedForDashboard($company, '200.00', '238.00', today()->subDays(20)->toDateString(), 'Bauer & Kollegen GmbH');
    issuedForDashboard($company, '300.00', '357.00', today()->subDays(7)->toDateString());

    $this->get('/admin/acme-gmbh')
        ->assertOk()->assertSee('Überfällige Rechnungen')->assertSeeHtml('595,00')
        // trans_choice, not a hardcoded plural.
        ->assertSee('2 Rechnungen')->assertSee('seit 20 Tagen')->assertSeeHtml('Bauer &amp; Kollegen GmbH');
});

it('uses the singular for a single überfällige Rechnung', function (): void {
    /** @var TestCase $this */
    $company = dashboardCompany($this);
    issuedForDashboard($company, '200.00', '238.00', today()->subDay()->toDateString());

    $this->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('1 Rechnung')
        ->assertDontSee('1 Rechnungen')
        ->assertSee('seit einem Tag');
});

it('shows nothing of another company\'s figures', function (): void {
    /** @var TestCase $this */
    $theirs = issuableCompany(['name' => 'Fremd GmbH']);
    issuedForDashboard($theirs, '7777.00', '9254.63', today()->subDays(30)->toDateString());

    $company = dashboardCompany($this);
    issuedForDashboard($company, '100.00', '119.00', today()->addDays(14)->toDateString());

    $this->get('/admin/acme-gmbh')->assertOk()->assertSeeHtml('100,00')->assertDontSeeHtml('9.254,63')->assertDontSeeHtml('7.777,00');
});

it('does not ask the database once per Entwurf or per überfälliger Rechnung', function (): void {
    /** @var TestCase $this */
    $small = issuableCompany(['name' => 'Klein GmbH']);
    $large = issuableCompany(['name' => 'Gross GmbH']);

    foreach ([[$small, 1], [$large, 3]] as [$company, $rows]) {
        for ($i = 0; $i < $rows; $i++) {
            issuedForDashboard($company, '100.00', '119.00', today()->subDays(10 + $i)->toDateString());
            draftInvoice($company);
        }
    }

    $user = User::factory()->create();
    $user->companies()->attach([$small->getKey(), $large->getKey()]);
    $this->actingAs($user);

    // One row each against three each. Not one against six: the worklists cap
    // at three, so the larger comparison would stay green against an N+1.
    $one = dashboardQueries($this, '/admin/klein-gmbh');
    $three = dashboardQueries($this, '/admin/gross-gmbh');

    expect($three)->toBe($one);
});

function dashboardQueries(TestCase $test, string $url): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $test->get($url)->assertOk();

    $count = count(DB::getRawQueryLog());

    DB::disableQueryLog();

    return $count;
}
