<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CurrencyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            ErpDemoSeeder::class,
        ]);
        app(CurrencyService::class)->ensureSeeded();
        app(CurrencyService::class)->seedDemoRates();
    }

    public function test_converts_usd_to_syp_via_service(): void
    {
        $svc = app(CurrencyService::class);
        $this->assertSame('SYP', $svc->baseCurrency());
        $converted = $svc->convert(2, 'USD', 'SYP');
        $this->assertEquals(30000.0, $converted);
    }

    public function test_convert_endpoint(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/currencies/convert', [
            'amount' => 10,
            'from_currency' => 'TRY',
            'to_currency' => 'SYP',
        ]);

        $res->assertOk()->assertJsonPath('data.converted', 4500);
    }
}
