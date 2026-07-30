<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_create_and_list_branch(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $company = Company::query()->create([
            'code' => 'CO1',
            'name' => 'شركة تجريبية',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $create = $this->postJson('/api/branches', [
            'company_id' => $company->id,
            'code' => 'BR-NEW',
            'name' => 'فرع حمص',
            'city' => 'حمص',
            'address' => 'شارع الثورة',
            'is_main' => false,
            'is_active' => true,
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.code', 'BR-NEW')
            ->assertJsonPath('data.name', 'فرع حمص')
            ->assertJsonPath('data.address', 'شارع الثورة')
            ->assertJsonPath('data.is_active', true);

        $list = $this->getJson('/api/branches');
        $list->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains(fn ($b) => $b['code'] === 'BR-NEW'));
    }

    public function test_sales_role_can_list_branches_but_not_create(): void
    {
        $company = Company::query()->create([
            'code' => 'CO2',
            'name' => 'شركة مبيعات',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'SALES-BR',
            'name' => 'فرع المبيعات',
            'is_main' => true,
            'is_active' => true,
        ]);

        $sales = User::factory()->create(['is_active' => true]);
        $sales->assignRole('sales');
        Sanctum::actingAs($sales);

        $this->getJson('/api/branches')->assertOk()
            ->assertJsonFragment(['code' => 'SALES-BR']);

        $this->postJson('/api/branches', [
            'company_id' => $company->id,
            'code' => 'DENIED',
            'name' => 'ممنوع',
            'is_active' => true,
        ])->assertForbidden();
    }

    public function test_duplicate_code_per_company_is_rejected(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $company = Company::query()->create([
            'code' => 'CO3',
            'name' => 'شركة رموز',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'DUP',
            'name' => 'الأول',
            'is_active' => true,
        ]);

        $this->postJson('/api/branches', [
            'company_id' => $company->id,
            'code' => 'DUP',
            'name' => 'الثاني',
            'is_active' => true,
        ])->assertStatus(422);
    }

    public function test_branch_can_be_updated_including_active_flag(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $company = Company::query()->create([
            'code' => 'CO4',
            'name' => 'شركة تحديث',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'UPD',
            'name' => 'قديم',
            'city' => 'دمشق',
            'is_active' => true,
        ]);

        $this->putJson("/api/branches/{$branch->id}", [
            'company_id' => $company->id,
            'code' => 'UPD',
            'name' => 'محدث',
            'city' => 'حلب',
            'address' => 'المدينة الجديدة',
            'is_main' => true,
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'محدث')
            ->assertJsonPath('data.city', 'حلب')
            ->assertJsonPath('data.address', 'المدينة الجديدة')
            ->assertJsonPath('data.is_main', true)
            ->assertJsonPath('data.is_active', false);
    }
}
