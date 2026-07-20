<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class ErpDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->updateOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'فيوتشر أكونت',
                'name_en' => 'Future Account',
                'tax_number' => '300000000000003',
                'currency' => 'SYP',
                'is_active' => true,
            ]
        );

        $branch = Branch::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'DAM'],
            [
                'name' => 'الفرع الرئيسي — دمشق',
                'city' => 'دمشق',
                'address' => 'شارع الثورة',
                'is_main' => true,
                'is_active' => true,
            ]
        );

        Branch::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'ALP'],
            [
                'name' => 'فرع حلب',
                'city' => 'حلب',
                'is_main' => false,
                'is_active' => true,
            ]
        );

        $whMain = Warehouse::query()->updateOrCreate(
            ['code' => 'WH-01'],
            ['name' => 'المخزن الرئيسي', 'branch_id' => $branch->id, 'location' => 'دمشق', 'is_active' => true]
        );
        Warehouse::query()->updateOrCreate(
            ['code' => 'WH-02'],
            ['name' => 'مخزن حلب', 'branch_id' => Branch::query()->where('code', 'ALP')->value('id'), 'location' => 'حلب', 'is_active' => true]
        );

        $cat = Category::query()->updateOrCreate(['name' => 'إلكترونيات'], ['parent_id' => null]);
        Category::query()->updateOrCreate(['name' => 'مستلزمات مكتبية'], ['parent_id' => null]);

        $pcs = Unit::query()->updateOrCreate(['name' => 'قطعة'], ['symbol' => 'pcs']);
        Unit::query()->updateOrCreate(['name' => 'علبة'], ['symbol' => 'box']);
        Unit::query()->updateOrCreate(['name' => 'كيلو'], ['symbol' => 'kg']);

        Product::query()->updateOrCreate(
            ['sku' => 'PRD-001'],
            [
                'barcode' => '6281000000001',
                'name' => 'طابعة ليزر',
                'category_id' => $cat->id,
                'unit_id' => $pcs->id,
                'cost_price' => 800,
                'sale_price' => 1200,
                'reorder_level' => 5,
                'is_active' => true,
            ]
        );
        Product::query()->updateOrCreate(
            ['sku' => 'PRD-002'],
            [
                'barcode' => '6281000000002',
                'name' => 'حبر طابعة',
                'category_id' => $cat->id,
                'unit_id' => $pcs->id,
                'cost_price' => 80,
                'sale_price' => 140,
                'reorder_level' => 20,
                'is_active' => true,
            ]
        );
        Product::query()->updateOrCreate(
            ['sku' => 'PRD-003'],
            [
                'barcode' => '6281000000003',
                'name' => 'ورق A4',
                'category_id' => Category::query()->where('name', 'مستلزمات مكتبية')->value('id'),
                'unit_id' => Unit::query()->where('symbol', 'box')->value('id'),
                'cost_price' => 25,
                'sale_price' => 45,
                'reorder_level' => 10,
                'is_active' => true,
            ]
        );

        $ar = Account::query()->where('code', '1103')->value('id');
        $ap = Account::query()->where('code', '2101')->value('id');
        $cashAcc = Account::query()->where('code', '1101')->value('id');
        $bankAcc = Account::query()->where('code', '1102')->value('id');

        Customer::query()->updateOrCreate(
            ['code' => 'CUS-001'],
            [
                'name' => 'شركة النور للتجارة',
                'branch_id' => $branch->id,
                'phone' => '0500000001',
                'email' => 'noor@example.com',
                'credit_limit' => 50000,
                'account_id' => $ar,
                'is_active' => true,
            ]
        );
        Customer::query()->updateOrCreate(
            ['code' => 'CUS-002'],
            [
                'name' => 'مؤسسة الأفق',
                'branch_id' => $branch->id,
                'phone' => '0500000002',
                'credit_limit' => 20000,
                'account_id' => $ar,
                'is_active' => true,
            ]
        );

        Supplier::query()->updateOrCreate(
            ['code' => 'SUP-001'],
            [
                'name' => 'مورد التقنية',
                'branch_id' => $branch->id,
                'phone' => '0110000001',
                'credit_limit' => 100000,
                'account_id' => $ap,
                'is_active' => true,
            ]
        );
        Supplier::query()->updateOrCreate(
            ['code' => 'SUP-002'],
            [
                'name' => 'مستودع القرطاسية',
                'branch_id' => $branch->id,
                'phone' => '0110000002',
                'account_id' => $ap,
                'is_active' => true,
            ]
        );

        CashBox::query()->updateOrCreate(
            ['code' => 'CASH-01'],
            ['name' => 'الصندوق الرئيسي', 'branch_id' => $branch->id, 'account_id' => $cashAcc, 'opening_balance' => 10000, 'is_active' => true]
        );

        Bank::query()->updateOrCreate(
            ['code' => 'BNK-01'],
            [
                'name' => 'البنك التجاري السوري',
                'branch_id' => $branch->id,
                'account_number' => '100200300',
                'iban' => null,
                'account_id' => $bankAcc,
                'opening_balance' => 50000,
                'currency' => 'SYP',
                'is_active' => true,
            ]
        );

        Employee::query()->updateOrCreate(
            ['employee_number' => 'EMP-001'],
            [
                'name' => 'أحمد محمد',
                'branch_id' => $branch->id,
                'job_title' => 'محاسب',
                'department' => 'المالية',
                'hire_date' => '2024-01-15',
                'basic_salary' => 7000,
                'is_active' => true,
            ]
        );
        Employee::query()->updateOrCreate(
            ['employee_number' => 'EMP-002'],
            [
                'name' => 'سارة علي',
                'branch_id' => $branch->id,
                'job_title' => 'أمينة مستودع',
                'department' => 'المخازن',
                'hire_date' => '2024-03-01',
                'basic_salary' => 5500,
                'is_active' => true,
            ]
        );

        Setting::setValue('multi_currency', '1', 'finance', 'boolean', 'تفعيل تعدد العملات');
        Setting::setValue('multi_language', '0', 'general', 'boolean', 'تفعيل تعدد اللغات');
        Setting::setValue('default_branch_id', (string) $branch->id, 'company', 'string', 'الفرع الافتراضي');
        Setting::setValue('default_warehouse_id', (string) $whMain->id, 'warehouse', 'string', 'المخزن الافتراضي');
        Setting::setValue('currency', 'SYP', 'finance', 'string', 'العملة الأساسية');
    }
}
