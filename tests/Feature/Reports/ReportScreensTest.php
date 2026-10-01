<?php

namespace Tests\Feature\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CallsApi;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * The report screens and the browser CSV download on real routes, with the
 * demo seed (current Beirut month: September 2026). Covers the role and
 * tenant matrix (T04/T05) on every report, the figures shown, escaping, and
 * that the browser download is the API's file.
 */
class ReportScreensTest extends TestCase
{
    use BuildsLedgerFixtures;
    use CallsApi;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    private const PAGES = ['consumption', 'top-stations', 'quota-exceptions', 'anomalies', 'efficiency', 'delivery-sla'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_every_report_opens_for_admins_and_managers_and_never_for_operators(): void
    {
        foreach ([$this->admin(), $this->atlasManager()] as $user) {
            $this->actingAs($user);
            $this->get('/reports')->assertRedirect('/reports/consumption');

            foreach (self::PAGES as $page) {
                $this->get("/reports/{$page}")->assertOk()->assertSee('Reports');
            }
        }

        $this->get('/dashboard')->assertSee('href="'.route('reports.consumption').'"', false);

        $this->actingAs($this->operator());
        $this->get('/station')->assertDontSee('href="'.route('reports.consumption').'"', false);
        foreach (self::PAGES as $page) {
            $this->get("/reports/{$page}")->assertForbidden();
        }
        $this->get('/exports/transactions.csv')->assertForbidden();
    }

    /** T04: a manager's pages show their company only; a company_id in the URL changes nothing. */
    public function test_a_manager_sees_only_their_company_whatever_the_url_says(): void
    {
        $this->actingAs($this->atlasManager());
        $cedar = '?company_id='.$this->cedar()->id;

        $this->get('/reports/consumption'.$cedar)->assertOk()
            ->assertSee('Atlas Logistics')->assertDontSee('Cedar Catering')
            ->assertSeeInOrder(['Total', '9', '515.00', '41,675,000.00']);
        $this->get('/reports/consumption?group_by=vehicle'.str_replace('?', '&', $cedar))->assertOk()
            ->assertSee('ATL-103')->assertDontSee('CED-203')->assertDontSee('No vehicle (card only)');
        $this->get('/reports/top-stations'.$cedar)->assertOk()->assertSeeInOrder(['Harbor Demo Station', '290.00', 'North Demo Station', '225.00']);

        // A blocked Cedar card is an exception for the admin, invisible here.
        $this->card('FF-CEDAR-FLEX')->forceFill(['status' => 'blocked'])->save();
        $this->get('/reports/quota-exceptions'.$cedar)->assertOk()->assertSee('••••CKED')->assertDontSee('••••FLEX');
        $this->actingAs($this->admin())->get('/reports/quota-exceptions')->assertSee('••••FLEX');
        $this->actingAs($this->atlasManager());

        $this->get('/reports/delivery-sla'.$cedar)->assertOk()->assertSee('Beirut')->assertDontSee('Mount Lebanon');
        $this->get('/reports/efficiency'.$cedar)->assertOk()->assertSee('ATL-102')->assertDontSee('CED-203');
    }

    public function test_the_admin_sees_every_company_and_can_narrow_to_one(): void
    {
        $this->actingAs($this->admin());

        $this->get('/reports/consumption')->assertOk()->assertSeeInOrder(['Atlas Logistics', 'Cedar Catering', 'Total', '840.00']);
        $this->get('/reports/consumption?company_id='.$this->cedar()->id)->assertOk()
            ->assertSee('325.00')->assertDontSee('515.00');
        $this->get('/reports/consumption?group_by=vehicle')->assertOk()->assertSee('No vehicle (card only)');
        $this->get('/reports/quota-exceptions')->assertOk()
            // Card numbers are masked to their last four characters.
            ->assertSee('••••CKED')
            ->assertDontSee('FF-ATLAS-BLOCKED')
            ->assertSee('Over the liter limit: 250.00 L used of 200.00 L (the limit was lowered below this month&#039;s usage).', false);
        $this->get('/reports/anomalies')->assertOk()->assertSeeInOrder(['More than the tank holds', 'ATL-102', '75.00', '60.00', '15.00', 'Rapid fills', 'ATL-102', '20']);
        $this->get('/reports/delivery-sla')->assertOk()->assertSeeInOrder(['Beirut', '74.00', 'Mount Lebanon', '51.00', 'All governorates', '2', '62.50']);
    }

    public function test_dates_are_validated_and_shown_back(): void
    {
        $this->actingAs($this->admin());

        $this->get('/reports/consumption?from=2026-08-01&to=2026-09-01')->assertOk()
            ->assertSee('2026-08-01 to 2026-08-31, Beirut time')
            ->assertSee('value="2026-09-01"', false);
        $this->from('/reports/consumption')->get('/reports/consumption?from=2026-09-10&to=2026-09-01')
            ->assertRedirect('/reports/consumption')->assertSessionHasErrors('to');
        $this->get('/reports/consumption?group_by=station')->assertSessionHasErrors('group_by');
    }

    public function test_names_are_escaped(): void
    {
        $this->cedar()->forceFill(['name' => '<script>alert(1)</script> Co'])->save();
        $this->actingAs($this->admin());

        foreach (self::PAGES as $page) {
            $this->get("/reports/{$page}")->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        }
        $this->get('/reports/consumption')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; Co', false);
    }

    /** The browser download is the same file the API serves for the same filters. */
    public function test_the_browser_csv_is_the_api_file(): void
    {
        $query = '?from=2026-08-01&to=2026-10-01&product_code=DIESEL';
        $api = $this->api('GET', '/api/v1/exports/transactions.csv'.$query, $this->apiToken($this->atlasManager()))->assertOk()->streamedContent();

        $this->startNewRequestCycle();
        $browser = $this->actingAs($this->atlasManager())->get('/exports/transactions.csv'.$query);

        $browser->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertDownload('fleetfuel-transactions-2026-08-01-to-2026-09-30.csv');
        $this->assertSame($api, $browser->streamedContent());
        $this->assertStringNotContainsString('Cedar', $browser->streamedContent());

        $this->get('/exports/transactions.csv?company_id='.$this->cedar()->id)->assertSessionHasErrors('company_id');
    }
}
