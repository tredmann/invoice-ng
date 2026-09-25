<?php

declare(strict_types=1);

use App\Filament\Pages\Tenancy\SelectCompany;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The markup of the first switcher instance, so assertions about the dropdown
 * cannot be satisfied by a tile or a heading elsewhere on the page.
 */
function switcherMarkup(string $html): string
{
    // The element, not the CSS rule in <head> that selects it.
    expect($html)->toContain('<div data-company-switcher="desktop"');

    return (string) str($html)->after('<div data-company-switcher="desktop"')->before('</nav>');
}

it('shows the companies of the user as tiles, in name order, with the legal form', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->soleProprietorship()->create(['name' => 'Zeta Handel']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['Alpha GmbH', 'Zeta Handel'])
        ->assertSee('Einzelunternehmen')
        ->assertDontSee('sole_proprietorship');
});

it('links each tile to its company by slug', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSeeHtml('href="'.url('/admin/acme-gmbh').'"');
});

it('leaves archived companies and other users companies off the picker', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Mine GmbH']));
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Archived GmbH']));
    Company::factory()->create(['name' => 'Theirs GmbH']);

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()->assertSee('Mine GmbH')->assertDontSeeHtml('href="'.url('/admin/archived-gmbh').'"')
        ->assertDontSee('Theirs GmbH');
});

it('escapes company names on tiles', function (): void {
    /** @var TestCase $this */
    // Review focus 1: a name is user input and prints into HTML.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Müller & Söhne <b>GmbH</b>']));

    $this->actingAs($user)
        ->get('/admin')->assertOk()->assertSeeHtml('Müller &amp; Söhne &lt;b&gt;GmbH&lt;/b&gt;')->assertDontSeeHtml('<b>GmbH</b>');
});

it('offers a new company from the page header', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create());

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSeeHtml('href="'.url('/admin/new').'"');
});

it('shows an empty state, not registration, to a user with no company', function (): void {
    /** @var TestCase $this */
    // Filament's own /admin redirected here to /admin/new. The user now
    // chooses to create a company; nothing forces it.
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Noch keine Firma angelegt.')
        ->assertSeeHtml('href="'.url('/admin/new').'"');
});

it('shows the empty state to a user whose companies are all archived', function (): void {
    /** @var TestCase $this */
    // Review focus 2: archived companies leave getTenants(), so this user has
    // nothing to pick — and must not get a tile for the archived one.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()->assertSee('Noch keine Firma angelegt.')->assertDontSeeHtml('href="'.url('/admin/gone-gmbh').'"');
});

it('lands on the picker after login, not inside a company', function (): void {
    $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'secret-password']);
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    Livewire::test(Login::class)
        ->fillForm(['email' => 'user@example.com', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(url('/admin'));
});

it('still sends a login to the page the user was on the way to', function (): void {
    /** @var TestCase $this */
    // Review focus 3: a bookmarked company link must survive the login detour.
    $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'secret-password']);
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->get('/admin/acme-gmbh/settings')->assertRedirect('/admin/login');

    Livewire::test(Login::class)
        ->fillForm(['email' => 'user@example.com', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertRedirect(url('/admin/acme-gmbh/settings'));
});

it('points the brand logo at the picker, not at the first company', function (): void {
    /** @var TestCase $this */
    // Filament's default home URL resolves to the user's default tenant — the
    // first company by name — so from inside Zeta the logo led into Alpha, and
    // on the picker it made the choice the page exists to offer.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Zeta GmbH']));

    foreach (['/admin', '/admin/zeta-gmbh'] as $path) {
        $content = (string) $this->actingAs($user)->get($path)->assertOk()->getContent();

        expect($content)->toContain('fi-topbar-start');

        $logoLink = (string) str($content)->after('fi-topbar-start')->before('fi-logo');

        expect($logoLink)->toContain('href="'.url('/admin').'"');
        expect($logoLink)->not->toContain('alpha-gmbh');
    }
});

it('sends an already signed-in visit to the login page to the picker', function (): void {
    /** @var TestCase $this */
    // Filament's Login::mount() redirects a signed-in user to Filament::getUrl(),
    // the default company — a separate path from the login response. Through
    // the real route, so the test exercises whichever login page the panel uses.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));

    $this->actingAs($user)
        ->get('/admin/login')
        ->assertRedirect(url('/admin'));
});

it('shows the switcher in the top bar on /admin, listing the active companies', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Zeta GmbH']));
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));
    Company::factory()->create(['name' => 'Theirs GmbH']);

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin')->assertOk()->getContent());

    expect($switcher)->toContain('Firma wählen')
        ->toContain('href="'.url('/admin/alpha-gmbh').'"')
        ->toContain('href="'.url('/admin/zeta-gmbh').'"');
    expect($switcher)->not->toContain('Gone GmbH');
    expect($switcher)->not->toContain('Theirs GmbH');
    // Only switches: none of the old menu entries.
    expect($switcher)->not->toContain('Neue Firma');
    expect($switcher)->not->toContain('Firmendaten');
});

it('shows the current company in the switcher inside a company, and lists it marked', function (): void {
    /** @var TestCase $this */
    // Review focus 1: with a single company the dropdown still lists it.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin/acme-gmbh')->assertOk()->getContent());

    expect($switcher)->toContain('Acme GmbH')
        ->toContain('href="'.url('/admin/acme-gmbh').'"')
        ->toContain('data-current-company');
});

it('names an archived company opened by URL in the trigger but does not list it', function (): void {
    /** @var TestCase $this */
    // Review focus 2.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Active GmbH']));
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin/gone-gmbh')->assertOk()->getContent());

    expect($switcher)->toContain('Gone GmbH');
    expect($switcher)->not->toContain('href="'.url('/admin/gone-gmbh').'"');
});

it('hides the switcher from a user with no active company', function (): void {
    /** @var TestCase $this */
    $this->actingAs(User::factory()->create())
        ->get('/admin')->assertOk()
        // The element's opening tag: the CSS rule in <head> names the
        // attribute too.
        ->assertDontSeeHtml('<div data-company-switcher=');
});

it('escapes company names in the switcher', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Müller & Söhne <b>GmbH</b>']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin')->assertOk()->getContent());

    expect($switcher)->toContain('Müller &amp; Söhne &lt;b&gt;GmbH&lt;/b&gt;');
    expect($switcher)->not->toContain('<b>GmbH</b>');
});

it('never renders Filament company menu', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    foreach (['/admin', '/admin/acme-gmbh'] as $path) {
        $this->actingAs($user)->get($path)->assertOk()->assertDontSeeHtml('fi-sidebar-header-controls');
    }
});

it('has no sidebar on /admin, and Dashboard and Firmendaten inside a company', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSeeHtml('id="fi-main-sidebar"');

    $inside = (string) $this->actingAs($user)->get('/admin/acme-gmbh')->assertOk()->getContent();
    $sidebar = (string) str($inside)->after('id="fi-main-sidebar"')->before('</aside>');

    expect($inside)->toContain('id="fi-main-sidebar"');
    expect($sidebar)->toContain('Dashboard')
        ->toContain('Firmendaten')
        ->toContain('href="'.url('/admin/acme-gmbh/settings').'"');
});

it('archives a company from its tile', function (): void {
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach($company);

    Livewire::actingAs($user)
        ->test(SelectCompany::class)
        ->callAction(TestAction::make('archive')->schemaComponent("company-{$company->getKey()}"));

    expect($company->fresh()?->isArchived())->toBeTrue();
});

it('restores an archived company from the Deaktiviert section', function (): void {
    $company = Company::factory()->archived()->create(['name' => 'Gone GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach($company);

    Livewire::actingAs($user)
        ->test(SelectCompany::class)
        ->callAction(TestAction::make('unarchive')->schemaComponent("archived-company-{$company->getKey()}"));

    expect($company->fresh()?->isArchived())->toBeFalse();
});

it('cannot archive or restore another users company', function (): void {
    // Review focus 3: the actions exist only on components built from the
    // acting user's own companies, so a forged key finds nothing to act on.
    $theirs = Company::factory()->create(['name' => 'Theirs GmbH']);
    $theirsArchived = Company::factory()->archived()->create(['name' => 'Theirs Old GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create());

    $page = Livewire::actingAs($user)->test(SelectCompany::class);

    expect(fn () => $page->callAction(TestAction::make('archive')->schemaComponent("company-{$theirs->getKey()}")))
        ->toThrow(ActionNotResolvableException::class);
    expect(fn () => $page->callAction(TestAction::make('unarchive')->schemaComponent("archived-company-{$theirsArchived->getKey()}")))
        ->toThrow(ActionNotResolvableException::class);

    expect($theirs->fresh()?->isArchived())->toBeFalse()
        ->and($theirsArchived->fresh()?->isArchived())->toBeTrue();
});

it('shows the Deaktiviert section only when the user has an archived company', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee('Deaktiviert (');

    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSee('Deaktiviert (1)')
        ->assertSee('Gone GmbH');
});

it('shows the Deaktiviert section beside the empty state', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSee('Noch keine Firma angelegt.')
        ->assertSee('Deaktiviert (1)');
});

it('lets a long single-word company name wrap inside its tile', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Grundstücksverwaltungsgesellschaft mbH']));

    $this->actingAs($user)->get('/admin')->assertOk()->assertSeeHtml('overflow-wrap: anywhere');
});

it('no longer serves the companies list', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin/acme-gmbh/companies')->assertNotFound();
});

it('renders no search box while nothing is searchable', function (): void {
    /** @var TestCase $this */
    // Filament shows the box only while some resource is globally searchable;
    // the companies list was the only one. It returns with customers.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin/acme-gmbh')->assertOk()->assertDontSeeHtml('fi-global-search');
});

it('redraws the picker after archiving and restoring', function (): void {
    // Asserting the database alone missed this: the actions worked, but the
    // page rendered the schema it had built — to find the action — before the
    // action ran, so the tile stayed and the Deaktiviert count lagged.
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach([$company->getKey(), Company::factory()->create(['name' => 'Beta GmbH'])->getKey()]);

    $page = Livewire::actingAs($user)->test(SelectCompany::class)
        ->assertDontSee('Deaktiviert (')
        ->callAction(TestAction::make('archive')->schemaComponent("company-{$company->getKey()}"))
        ->assertSee('Deaktiviert (1)')
        ->assertDontSeeHtml('href="'.url('/admin/acme-gmbh').'"')
        // The switcher lives in Filament's separate top-bar component, which
        // only re-renders on this event.
        ->assertDispatched('refresh-topbar');

    $page->callAction(TestAction::make('unarchive')->schemaComponent("archived-company-{$company->getKey()}"))
        ->assertDontSee('Deaktiviert (')
        ->assertSeeHtml('href="'.url('/admin/acme-gmbh').'"');
});

it('turns to the empty state when the last active company is archived', function (): void {
    $only = Company::factory()->create(['name' => 'Only GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach($only);

    Livewire::actingAs($user)->test(SelectCompany::class)
        ->callAction(TestAction::make('archive')->schemaComponent("company-{$only->getKey()}"))
        ->assertSee('Noch keine Firma angelegt.')
        ->assertSee('Deaktiviert (1)');
});
