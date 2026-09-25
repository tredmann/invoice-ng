<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Livewire\GlobalSearch;
use Livewire\Livewire;
use Tests\TestCase;

it('renders /admin in the full panel layout', function (): void {
    /** @var TestCase $this */
    // The first attempt used the simple layout — the centred card login uses —
    // which has neither of these. The spec's success criterion is that /admin
    // looks like every other panel screen.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create());

    $this->actingAs($user)
        ->get('/admin')->assertOk()->assertSeeHtml('fi-sidebar')->assertSeeHtml('fi-topbar');
});

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
        ->assertOk()
        ->assertSee('Mine GmbH')
        ->assertDontSee('Archived GmbH')
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
        ->assertOk()
        ->assertSee('Noch keine Firma angelegt.')
        ->assertDontSee('Gone GmbH');
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

it('offers the way back to the picker from inside a company', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Alle Firmen');
});

it('answers a global search on /admin without a company', function (): void {
    // Review focus 4: the companies resource is globally searchable, and every
    // result needs a URL — which, inside a company, is built from that company.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    Livewire::actingAs($user)
        ->test(GlobalSearch::class)
        ->set('search', 'Acme')
        ->assertOk()
        ->assertSee('Acme GmbH');
});

it('shows the placeholder company menu on /admin, listing the companies', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $response = $this->actingAs($user)->get('/admin')->assertOk();

    // Scoped to the menu's own markup, so the tile's copy of the name and link
    // cannot satisfy these on its own.
    expect((string) $response->getContent())->toContain('data-company-picker-menu');

    $menu = str((string) $response->getContent())->after('data-company-picker-menu')->before('</nav>');

    expect((string) $menu)
        ->toContain('Firma wählen')
        ->toContain('Acme GmbH')
        ->toContain('href="'.url('/admin/acme-gmbh').'"')
        ->toContain('href="'.url('/admin/new').'"');
});

it('shows Filament company menu, not the placeholder, inside a company', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')->assertOk()->assertDontSeeHtml('data-company-picker-menu')->assertSeeHtml('fi-tenant-menu');
});

it('escapes company names in the placeholder menu', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Müller & Söhne <b>GmbH</b>']));

    $content = (string) $this->actingAs($user)->get('/admin')->assertOk()->getContent();
    expect($content)->toContain('data-company-picker-menu');

    $menu = (string) str($content)->after('data-company-picker-menu')->before('</nav>');

    expect($menu)->toContain('Müller &amp; Söhne &lt;b&gt;GmbH&lt;/b&gt;');
    expect($menu)->not->toContain('<b>GmbH</b>');
});

it('builds no navigation links on /admin, but does inside a company', function (): void {
    /** @var TestCase $this */
    // Both halves: without the second, an empty sidebar everywhere would pass.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSeeHtml('fi-sidebar-item');

    $this->actingAs($user)->get('/admin/acme-gmbh')->assertOk()->assertSeeHtml('fi-sidebar-item');
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

it('lets a long single-word company name wrap inside its tile', function (): void {
    /** @var TestCase $this */
    // Filament's section heading has no overflow-wrap, and a flex item will not
    // shrink below its longest word — so a German compound ran past the card.
    // Pure CSS, so this pins the rule; the screenshot is what shows it works.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Grundstücksverwaltungsgesellschaft mbH']));

    $this->actingAs($user)
        ->get('/admin')->assertOk()->assertSeeHtml('overflow-wrap: anywhere');
});
