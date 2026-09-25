<?php

declare(strict_types=1);

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Company;
use App\Models\Customer;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Livewire\Livewire;
use Tests\TestCase;

it('lists only the customers of the company in the url', function (): void {
    /** @var TestCase $this */
    $alpha = Company::factory()->create(['name' => 'Alpha GmbH']);
    $beta = Company::factory()->create(['name' => 'Beta GmbH']);
    Customer::factory()->for($alpha)->create(['name' => 'Kunde von Alpha']);
    Customer::factory()->for($beta)->create(['name' => 'Kunde von Beta']);

    $this->actingAs(memberOf($alpha, $beta))
        ->get('/admin/alpha-gmbh/customers')
        ->assertOk()
        ->assertSee('Kunde von Alpha')
        ->assertDontSee('Kunde von Beta');
});

it('sorts by name, not by number', function (): void {
    // Created out of alphabetical order, so number order and name order differ.
    $company = Company::factory()->create();
    $weber = Customer::factory()->for($company)->create(['name' => 'Weber Haustechnik e.K.']);
    $bauer = Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);
    $lindner = Customer::factory()->for($company)->create(['name' => 'Lindner Consulting GmbH']);
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$bauer, $lindner, $weber], inOrder: true);
});

it('finds a customer by its number however it is typed, and only that one', function (string $search): void {
    // Customer 14 is there to catch a substring match on "4". Names, emails
    // and cities carry no digits, so only the number can match.
    $company = Company::factory()->create();
    $customers = Customer::factory()->for($company)->count(14)
        ->sequence(fn (Sequence $sequence): array => [
            'name' => 'Kunde '.str_repeat('x', $sequence->index + 1),
            'email' => null,
            'city' => 'Landshut',
        ])
        ->create();
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$customers[3]])
        ->assertCanNotSeeTableRecords([$customers[13]]);
})->with(['K-0004', 'k-0004', '0004', '4', ' K-0004 ']);

it('finds customers by name, email and city, case-insensitively', function (string $search): void {
    $company = Company::factory()->create();
    $bauer = Customer::factory()->for($company)->create([
        'name' => 'Bauer & Kollegen GmbH',
        'email' => 'rechnung@bauer-kollegen.de',
        'city' => 'München',
    ]);
    $other = Customer::factory()->for($company)->create([
        'name' => 'Lindner Consulting GmbH',
        'email' => 'info@lindner.de',
        'city' => 'Nürnberg',
    ]);
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$bauer])
        ->assertCanNotSeeTableRecords([$other]);
})->with(['bauer', 'RECHNUNG@', 'münchen']);

it('keeps a search inside the company', function (): void {
    // Beta's customer matches "bau" by city only. Fails if the search ever
    // runs on a query the tenant scope has been removed from — e.g. a
    // withoutGlobalScope(s) call or an unscoped subquery in the search path.
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    $mine = Customer::factory()->for($alpha)->create(['name' => 'Bauer GmbH', 'city' => 'Landshut']);
    $theirs = Customer::factory()->for($beta)->create(['name' => 'Weber GmbH', 'city' => 'Baunach']);
    actInCompany($alpha);

    Livewire::test(ListCustomers::class)
        ->searchTable('bau')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('treats like wildcards in a search literally', function (string $search): void {
    // Unescaped, "%" matches every customer. Emails are cleared because
    // faker's addresses may contain an underscore.
    $company = Company::factory()->create();
    Customer::factory()->for($company)->count(2)->create([
        'name' => 'Bauer GmbH',
        'email' => null,
        'city' => 'Landshut',
    ]);
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->searchTable($search)
        ->assertCountTableRecords(0);
})->with(['%', '_']);

it('marks a deactivated customer in the list and leaves the rest unmarked', function (): void {
    /** @var TestCase $this */
    // Both directions: a badge on every row or on none fails one half.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create(['name' => 'Bauer GmbH']);
    $user = memberOf($company);

    $this->actingAs($user)
        ->get('/admin/alpha-gmbh/customers')
        ->assertOk()
        ->assertDontSee('Deaktiviert');

    Customer::factory()->for($company)->archived()->create(['name' => 'Weber Haustechnik e.K.']);

    $this->actingAs($user)
        ->get('/admin/alpha-gmbh/customers')
        ->assertOk()
        ->assertSee('Weber Haustechnik e.K.')
        ->assertSee('Deaktiviert');
});

it('escapes a customer name exactly once in the list and in the heading', function (bool $archived): void {
    /** @var TestCase $this */
    // The name reaches the page through an HtmlString, so the escaping is
    // this code's job: raw would let "<Söhne>" through as markup, and escaping
    // twice would print "&amp;amp;".
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    $factory = Customer::factory()->for($company);
    ($archived ? $factory->archived() : $factory)->create(['name' => 'Bauer & <Söhne> GmbH']);
    $user = memberOf($company);

    foreach (['/admin/alpha-gmbh/customers', '/admin/alpha-gmbh/customers/K-0001'] as $url) {
        $this->actingAs($user)
            ->get($url)
            ->assertOk()->assertSee('Bauer & <Söhne> GmbH')->assertDontSeeHtml('<Söhne>')->assertDontSeeHtml('&amp;amp;');
    }
})->with(['active' => false, 'deactivated' => true]);

it('invites the first customer when the company has none', function (): void {
    /** @var TestCase $this */
    // The header action is hidden here because the empty state carries the
    // same button; both at once would show it twice.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);

    $this->actingAs(memberOf($company))
        ->get('/admin/alpha-gmbh/customers')
        ->assertOk()
        ->assertSee('Noch keine Kunden angelegt.')
        ->assertSee('Jede Rechnung geht an einen Kunden. Legen Sie den ersten an.');

    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->assertActionHidden('create')
        ->assertActionVisible(TestAction::make('createFirst')->table());
});

it('does not call a search without matches an empty company', function (): void {
    // Filament shows one empty state for "no rows" and "no matching rows".
    // A typo in the search box must not announce that no customers exist.
    $company = Company::factory()->create();
    Customer::factory()->for($company)->create(['name' => 'Bauer GmbH']);
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->assertActionVisible('create')
        ->searchTable('zzz')
        ->assertSee('Keine Kunden gefunden.')
        ->assertDontSee('Noch keine Kunden angelegt.')
        ->assertActionHidden(TestAction::make('createFirst')->table());
});

it('deactivates and reactivates a customer from its row menu', function (): void {
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->assertActionHidden(TestAction::make('unarchive')->table($customer))
        ->callAction(TestAction::make('archive')->table($customer));

    expect($customer->fresh()?->isArchived())->toBeTrue();

    Livewire::test(ListCustomers::class)
        ->assertActionHidden(TestAction::make('archive')->table($customer))
        ->callAction(TestAction::make('unarchive')->table($customer));

    expect($customer->fresh()?->isArchived())->toBeFalse();
});

it('deactivates and reactivates a customer from its page', function (): void {
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(ViewCustomer::class, ['record' => 'K-0001'])
        ->callAction('archive')
        ->assertActionHidden('archive')
        ->assertActionVisible('unarchive');

    expect($customer->fresh()?->isArchived())->toBeTrue();

    Livewire::test(ViewCustomer::class, ['record' => 'K-0001'])
        ->assertSee('Deaktiviert')
        ->callAction('unarchive');

    expect($customer->fresh()?->isArchived())->toBeFalse();
});
