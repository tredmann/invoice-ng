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

it('leads Firmen verwalten to the picker, not to the first company', function (): void {
    /** @var TestCase $this */
    // Filament's default home URL resolves to the user's default tenant — the
    // first company by name — so from inside Zeta it would lead into Alpha.
    // Firmen verwalten is built from the home URL, and since the brand logo
    // left the top bar it is the way back to the picker.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Zeta GmbH']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin/zeta-gmbh')->assertOk()->getContent());

    expect($switcher)->toContain('Firmen verwalten');

    // The opening tag of the entry that carries the label.
    $manageLink = (string) str($switcher)->before('Firmen verwalten')->afterLast('<a');

    expect($manageLink)->toContain('href="'.url('/admin').'"');
    expect($manageLink)->not->toContain('alpha-gmbh');
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
    // Top-bar trail spec §2.5: the label, then the companies, then Neue Firma.
    // Firmen verwalten would link to this very page, so it is left out here.
    expect($switcher)->toContain('Firma wechseln')
        ->toContain('href="'.url('/admin/new').'"')
        ->toContain('Neue Firma');
    expect($switcher)->not->toContain('Firmen verwalten');
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

/**
 * How often the page's content links to company registration: the header
 * action and the empty state's action must never both be there. Counted in
 * <main> only — the switcher's dropdown in the top bar carries its own Neue
 * Firma (top-bar trail spec §2.5), which is not the page offering it twice.
 */
function registrationLinks(string $html): int
{
    expect($html)->toContain('id="fi-main-content"');

    return substr_count((string) str($html)->after('id="fi-main-content"'), 'href="'.url('/admin/new').'"');
}

it('reloads the picker after archiving and restoring, so nothing on it is stale', function (): void {
    /** @var TestCase $this */
    // Filament caches the page's schema and header actions, and the switcher
    // is a separate top-bar component: patching one cache left another stale.
    // A reload of /admin rebuilds all of them.
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach([$company->getKey(), Company::factory()->create(['name' => 'Beta GmbH'])->getKey()]);

    Livewire::actingAs($user)->test(SelectCompany::class)
        ->callAction(TestAction::make('archive')->schemaComponent("company-{$company->getKey()}"))
        ->assertRedirect(url('/admin'));

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSee('Deaktiviert (1)')
        ->assertDontSeeHtml('href="'.url('/admin/acme-gmbh').'"');

    Livewire::actingAs($user)->test(SelectCompany::class)
        ->callAction(TestAction::make('unarchive')->schemaComponent("archived-company-{$company->getKey()}"))
        ->assertRedirect(url('/admin'));

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertDontSee('Deaktiviert (')
        ->assertSeeHtml('href="'.url('/admin/acme-gmbh').'"');
});

it('offers Neue Firma exactly once after the last active company is archived', function (): void {
    /** @var TestCase $this */
    $only = Company::factory()->create(['name' => 'Only GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach($only);

    Livewire::actingAs($user)->test(SelectCompany::class)
        ->callAction(TestAction::make('archive')->schemaComponent("company-{$only->getKey()}"))
        ->assertRedirect(url('/admin'));

    $html = (string) $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSee('Noch keine Firma angelegt.')
        ->assertSee('Deaktiviert (1)')
        ->getContent();

    expect(registrationLinks($html))->toBe(1);
});

it('offers Neue Firma again after restoring from an empty picker', function (): void {
    /** @var TestCase $this */
    $gone = Company::factory()->archived()->create(['name' => 'Gone GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach($gone);

    Livewire::actingAs($user)->test(SelectCompany::class)
        ->callAction(TestAction::make('unarchive')->schemaComponent("archived-company-{$gone->getKey()}"))
        ->assertRedirect(url('/admin'));

    $html = (string) $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSeeHtml('href="'.url('/admin/gone-gmbh').'"')
        ->getContent();

    expect(registrationLinks($html))->toBe(1);
});

it('escapes company names in the Deaktiviert section', function (): void {
    /** @var TestCase $this */
    // With no active company the switcher is hidden, so only the archived row
    // can print the name.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Müller & Söhne <b>GmbH</b>']));

    $html = (string) $this->actingAs($user)->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('Müller &amp; Söhne &lt;b&gt;GmbH&lt;/b&gt;');
    expect($html)->not->toContain('<b>GmbH</b>');
});

it('shows the switcher inside an archived company even with no active company', function (): void {
    /** @var TestCase $this */
    // Review focus 4. Top-bar trail spec §2.5: the dropdown always has
    // Firmen verwalten and Neue Firma now, so it is never empty.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin/gone-gmbh')->assertOk()->getContent());

    expect($switcher)->toContain('Gone GmbH')
        ->toContain('href="'.url('/admin').'"')
        ->toContain('Firmen verwalten')
        ->toContain('href="'.url('/admin/new').'"');
    expect($switcher)->not->toContain('data-current-company');
    expect($switcher)->not->toContain('Firma wechseln');
});

it('offers Firmen verwalten and Neue Firma inside a company, and marks the current one', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Kranz Ingenieurbüro GmbH']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Hofgarten Immobilien GmbH']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin/kranz-ingenieurburo-gmbh')->assertOk()->getContent());

    expect($switcher)->toContain('Firma wechseln')
        ->toContain('href="'.url('/admin').'"')
        ->toContain('Firmen verwalten')
        ->toContain('href="'.url('/admin/new').'"')
        ->toContain('>KI<')
        ->toContain('>HI<');
    // Exactly one company is marked: the one in the URL. Counted in the
    // desktop copy; the phone copy repeats the list.
    expect(substr_count((string) str($switcher)->before('data-company-switcher="phone"'), 'data-current-company='))->toBe(1);
});

it('draws company avatars locally, never from ui-avatars.com', function (): void {
    /** @var TestCase $this */
    // The switcher only: the user menu's avatar is Filament's and out of
    // scope (top-bar trail spec §8).
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    // The desktop copy, which ends where the phone copy starts — before the
    // user menu.
    $switcher = (string) str(switcherMarkup((string) $this->actingAs(memberOf($company))->get('/admin/acme-gmbh')->assertOk()->getContent()))
        ->before('data-company-switcher="phone"');

    expect($switcher)->toContain('>AG<');
    expect($switcher)->not->toContain('ui-avatars.com');
});
