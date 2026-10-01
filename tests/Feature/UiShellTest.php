<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * The page shell shared by every screen (M09): role-based navigation, the
 * skip link and the off-canvas menu for narrow screens, and the error pages
 * in the application's own words.
 */
class UiShellTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    /** Route => navigation label. */
    private const LINKS = [
        'dashboard' => 'Dashboard',
        'station.home' => 'Station',
        'transactions.index' => 'Transactions',
        'reports.consumption' => 'Reports',
        'deliveries.index' => 'Deliveries',
        'companies.index' => 'Companies',
        'vehicles.index' => 'Vehicles',
        'drivers.index' => 'Drivers',
        'cards.index' => 'Cards',
        'stations.index' => 'Stations',
        'products.index' => 'Products and prices',
        'integrations.exchange-rates' => 'Exchange rates',
        'audit.index' => 'Audit log',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_navigation_lists_exactly_the_pages_each_role_may_open(): void
    {
        $expected = [
            'admin' => ['dashboard', 'transactions.index', 'reports.consumption', 'deliveries.index', 'companies.index', 'vehicles.index',
                'drivers.index', 'cards.index', 'stations.index', 'products.index', 'integrations.exchange-rates', 'audit.index'],
            'manager' => ['dashboard', 'transactions.index', 'reports.consumption', 'deliveries.index', 'vehicles.index', 'drivers.index',
                'cards.index', 'stations.index', 'products.index'],
            'operator' => ['station.home', 'transactions.index', 'stations.index', 'products.index'],
        ];
        $users = ['admin' => $this->admin(), 'manager' => $this->atlasManager(), 'operator' => $this->operator()];

        foreach ($users as $role => $user) {
            // Every listed page opens for that role; every other page has no link.
            $nav = $this->actingAs($user)->get('/stations')->assertOk();
            foreach (self::LINKS as $routeName => $label) {
                $link = 'href="'.route($routeName).'">'.$label.'</a>';
                if (in_array($routeName, $expected[$role], true)) {
                    $nav->assertSee($link, false);
                    $this->get(route($routeName))->assertOk();
                } else {
                    $nav->assertDontSee($link, false);
                }
            }
        }
    }

    public function test_the_shell_has_a_skip_link_a_current_page_marker_and_a_menu_button(): void
    {
        $page = $this->actingAs($this->atlasManager())->get('/transactions')->assertOk()
            ->assertSee('<a class="visually-hidden-focusable skip-link" href="#main">Skip to main content</a>', false)
            ->assertSee('<main id="main"', false)
            ->assertSee('data-bs-toggle="offcanvas" data-bs-target="#app-sidebar"', false)
            ->assertSee('aria-label="Close menu"', false)
            ->assertSee('<nav aria-label="Main">', false);

        // Exactly one link is the current page, and it is Transactions.
        $this->assertSame(1, substr_count($page->getContent(), 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/class="nav-link active"\s+aria-current="page"\s+href="'.preg_quote(route('transactions.index'), '/').'">Transactions/', $page->getContent());
    }

    public function test_not_found_reads_the_same_for_a_missing_record_and_another_companys_record(): void
    {
        $this->actingAs($this->atlasManager());

        $missing = $this->get('/cards/999999')->assertNotFound();
        $otherCompany = $this->get('/cards/'.$this->card('FF-CEDAR-001')->id)->assertNotFound();

        foreach ([$missing, $otherCompany, $this->get('/no-such-page')->assertNotFound()] as $response) {
            $response->assertSee('Page not found')->assertSee('does not exist, or you do not have access to it')
                ->assertDontSee('FF-CEDAR');
        }
    }

    public function test_server_errors_show_a_plain_message_without_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_test/fail', fn () => throw new RuntimeException('SQLSTATE secret detail'));
        Route::middleware('web')->get('/_test/unavailable', fn () => abort(503));
        Route::middleware('web')->get('/_test/throttled', fn () => abort(429));

        $this->get('/_test/fail')->assertStatus(500)->assertSee('Something went wrong')->assertDontSee('SQLSTATE');
        $this->get('/_test/unavailable')->assertStatus(503)->assertSee('Temporarily unavailable');
        $this->get('/_test/throttled')->assertStatus(429)->assertSee('Too many requests');

        // The API keeps its JSON envelope.
        $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertJsonStructure(['error' => ['code', 'message']]);
    }
}
