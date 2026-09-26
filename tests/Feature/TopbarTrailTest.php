<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Livewire\RenderedPage;
use App\Livewire\Topbar;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The crumbs after the switcher, as [label, url|null] pairs, read from the
 * top bar's <ol data-topbar-trail> — so a crumb elsewhere on the page (the
 * heading, a table cell) cannot satisfy an assertion.
 *
 * @return list<array{0: string, 1: string|null}>
 */
function trailOf(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $trail = $document->querySelector('ol[data-topbar-trail]');

    if (! $trail instanceof Element) {
        return [];
    }

    $crumbs = [];

    foreach ($trail->querySelectorAll('li') as $item) {
        $link = $item->querySelector('a');
        $crumbs[] = [trim((string) $item->textContent), $link?->getAttribute('href')];
    }

    return $crumbs;
}

/**
 * Acme GmbH and a member of it. A function rather than beforeEach properties:
 * Larastan analyses the tests too, and dynamic properties on TestCase fail it.
 *
 * @return array{0: Company, 1: User}
 */
function acme(): array
{
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    return [$company, memberOf($company)];
}

it('shows the page title alone on pages outside a resource', function (string $path, string $label): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $html = (string) $this->actingAs($user)->get($path)->assertOk()->getContent();

    expect(trailOf($html))->toBe([[$label, null]]);
})->with([
    'dashboard' => ['/admin/acme-gmbh', 'Dashboard'],
    'settings' => ['/admin/acme-gmbh/settings', 'Einstellungen'],
]);

it('shows the section alone on the customer list, without Filament\'s Übersicht', function (): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers')->assertOk()->getContent();

    expect(trailOf($html))->toBe([['Kunden', null]]);
});

it('names a new customer after the page title, not Erstellen', function (): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/create')->assertOk()->getContent();

    expect(trailOf($html))->toBe([
        ['Kunden', url('/admin/acme-gmbh/customers')],
        ['Neuer Kunde', null],
    ]);
});

it('ends on the customer on its page, without Ansehen', function (): void {
    /** @var TestCase $this */
    [$company, $user] = acme();
    Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/K-0001')->assertOk()->getContent();

    expect(trailOf($html))->toBe([
        ['Kunden', url('/admin/acme-gmbh/customers')],
        ['Bauer & Kollegen GmbH', null],
    ]);
});

it('links the customer from its edit page and ends on Bearbeiten', function (): void {
    /** @var TestCase $this */
    [$company, $user] = acme();
    Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/K-0001/edit')->assertOk()->getContent();

    expect(trailOf($html))->toBe([
        ['Kunden', url('/admin/acme-gmbh/customers')],
        ['Bauer & Kollegen GmbH', url('/admin/acme-gmbh/customers/K-0001')],
        ['Bearbeiten', null],
    ]);
});

it('shows a deactivated customer by its plain name, without the badge', function (): void {
    /** @var TestCase $this */
    // Review focus 1: the heading is an HtmlString carrying a badge; the
    // trail takes the record title, which must stay plain text.
    [$company, $user] = acme();
    Customer::factory()->for($company)->deactivated()->create(['name' => 'Weber Haustechnik e.K.']);

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/K-0001')->assertOk()->getContent();

    expect(trailOf($html))->toBe([
        ['Kunden', url('/admin/acme-gmbh/customers')],
        ['Weber Haustechnik e.K.', null],
    ]);
});

it('escapes a customer name in the trail exactly once', function (): void {
    /** @var TestCase $this */
    // Review focus 2.
    [$company, $user] = acme();
    Customer::factory()->for($company)->create(['name' => 'Bauer & <Söhne> GmbH']);

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/K-0001')->assertOk()->getContent();
    // Without the trail, after() would return the whole page, and the
    // heading would satisfy the assertions below.
    expect($html)->toContain('<ol data-topbar-trail');
    $trail = (string) str($html)->after('<ol data-topbar-trail')->before('</ol>');

    expect($trail)->toContain('Bauer &amp; &lt;Söhne&gt; GmbH');
    expect($trail)->not->toContain('<Söhne>');
    expect($trail)->not->toContain('&amp;amp;');
});

it('draws no breadcrumbs above the page heading', function (): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $this->actingAs($user)->get('/admin/acme-gmbh/customers')->assertOk()
        ->assertDontSeeHtml('fi-breadcrumbs');
});

it('draws no trail on the company picker', function (): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertDontSeeHtml('<ol data-topbar-trail');
});

it('keeps its trail when the top bar re-renders on its own', function (): void {
    // Fails if the trail were read from the current page at render time: on
    // a refresh-topbar request the only component rendering is the top bar.
    [$company] = acme();
    actInCompany($company);

    // The page this request rendered, as AppServiceProvider records it.
    resolve(RenderedPage::class)->page = new Dashboard;

    $topbar = Livewire::test(Topbar::class)
        ->assertSeeHtml('<span aria-current="page">Dashboard</span>');

    resolve(RenderedPage::class)->page = null;

    $topbar->dispatch('refresh-topbar')
        ->assertSeeHtml('<span aria-current="page">Dashboard</span>');
});

it('refreshes the top bar after the company data is saved, so a new name shows', function (): void {
    [$company] = acme();
    actInCompany($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['name' => 'Acme Neu GmbH'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDispatched('refresh-topbar');
});

it('draws the switcher in the logo\'s place, outside the trail column', function (): void {
    /** @var TestCase $this */
    // breadcrumb2: the switcher sits where the logo was, as wide as the
    // sidebar; only the crumbs sit over the content column.
    [, $user] = acme();

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    expect($document->querySelector('.fi-topbar-start [data-company-switcher="desktop"]'))->not->toBeNull();
    expect($document->querySelector('[data-topbar-column] [data-company-switcher]'))->toBeNull();
    expect($document->querySelector('[data-topbar-column] ol[data-topbar-trail]'))->not->toBeNull();
});

it('separates crumbs only between them, not before the first', function (): void {
    /** @var TestCase $this */
    [$company, $user] = acme();
    Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/K-0001/edit')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    $separators = array_map(
        fn (Element $item): int => $item->querySelectorAll('.app-trail-separator')->length,
        iterator_to_array($document->querySelectorAll('ol[data-topbar-trail] > li')),
    );

    expect($separators)->toBe([0, 1, 1]);
});

it('exposes the trail as its own navigation landmark', function (): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    expect($document->querySelector('nav[aria-label="Navigationspfad"] > ol[data-topbar-trail]'))->not->toBeNull();
});
