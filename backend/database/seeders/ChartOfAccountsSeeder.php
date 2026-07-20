<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            ['code' => '1', 'name' => 'الأصول', 'name_en' => 'Assets', 'type' => 'asset', 'nature' => 'debit', 'is_group' => true, 'children' => [
                ['code' => '11', 'name' => 'الأصول المتداولة', 'name_en' => 'Current Assets', 'type' => 'asset', 'nature' => 'debit', 'is_group' => true, 'children' => [
                    ['code' => '1101', 'name' => 'الصندوق', 'name_en' => 'Cash on Hand', 'type' => 'asset', 'nature' => 'debit'],
                    ['code' => '1102', 'name' => 'البنك', 'name_en' => 'Bank', 'type' => 'asset', 'nature' => 'debit'],
                    ['code' => '1103', 'name' => 'العملاء', 'name_en' => 'Accounts Receivable', 'type' => 'asset', 'nature' => 'debit'],
                    ['code' => '1104', 'name' => 'المخزون', 'name_en' => 'Inventory', 'type' => 'asset', 'nature' => 'debit'],
                ]],
                ['code' => '12', 'name' => 'الأصول الثابتة', 'name_en' => 'Fixed Assets', 'type' => 'asset', 'nature' => 'debit', 'is_group' => true, 'children' => [
                    ['code' => '1201', 'name' => 'الأثاث والمعدات', 'name_en' => 'Furniture & Equipment', 'type' => 'asset', 'nature' => 'debit'],
                ]],
            ]],
            ['code' => '2', 'name' => 'الخصوم', 'name_en' => 'Liabilities', 'type' => 'liability', 'nature' => 'credit', 'is_group' => true, 'children' => [
                ['code' => '21', 'name' => 'الخصوم المتداولة', 'name_en' => 'Current Liabilities', 'type' => 'liability', 'nature' => 'credit', 'is_group' => true, 'children' => [
                    ['code' => '2101', 'name' => 'الموردون', 'name_en' => 'Accounts Payable', 'type' => 'liability', 'nature' => 'credit'],
                    ['code' => '2102', 'name' => 'ضريبة القيمة المضافة', 'name_en' => 'VAT Payable', 'type' => 'liability', 'nature' => 'credit'],
                ]],
            ]],
            ['code' => '3', 'name' => 'حقوق الملكية', 'name_en' => 'Equity', 'type' => 'equity', 'nature' => 'credit', 'is_group' => true, 'children' => [
                ['code' => '3101', 'name' => 'رأس المال', 'name_en' => 'Capital', 'type' => 'equity', 'nature' => 'credit'],
                ['code' => '3102', 'name' => 'الأرباح المحتجزة', 'name_en' => 'Retained Earnings', 'type' => 'equity', 'nature' => 'credit'],
            ]],
            ['code' => '4', 'name' => 'الإيرادات', 'name_en' => 'Revenue', 'type' => 'revenue', 'nature' => 'credit', 'is_group' => true, 'children' => [
                ['code' => '4101', 'name' => 'إيرادات المبيعات', 'name_en' => 'Sales Revenue', 'type' => 'revenue', 'nature' => 'credit'],
                ['code' => '4102', 'name' => 'إيرادات أخرى', 'name_en' => 'Other Income', 'type' => 'revenue', 'nature' => 'credit'],
            ]],
            ['code' => '5', 'name' => 'المصروفات', 'name_en' => 'Expenses', 'type' => 'expense', 'nature' => 'debit', 'is_group' => true, 'children' => [
                ['code' => '5101', 'name' => 'تكلفة البضاعة المباعة', 'name_en' => 'Cost of Goods Sold', 'type' => 'expense', 'nature' => 'debit'],
                ['code' => '5102', 'name' => 'مصروف الرواتب', 'name_en' => 'Salaries Expense', 'type' => 'expense', 'nature' => 'debit'],
                ['code' => '5103', 'name' => 'مصروف الإيجار', 'name_en' => 'Rent Expense', 'type' => 'expense', 'nature' => 'debit'],
                ['code' => '5104', 'name' => 'مصروفات عمومية', 'name_en' => 'General Expenses', 'type' => 'expense', 'nature' => 'debit'],
            ]],
        ];

        foreach ($tree as $node) {
            $this->createNode($node, null, 1);
        }
    }

    protected function createNode(array $node, ?int $parentId, int $level): void
    {
        $account = Account::query()->updateOrCreate(
            ['code' => $node['code']],
            [
                'name' => $node['name'],
                'name_en' => $node['name_en'] ?? null,
                'parent_id' => $parentId,
                'type' => $node['type'],
                'nature' => $node['nature'],
                'level' => $level,
                'is_group' => $node['is_group'] ?? false,
                'is_active' => true,
            ]
        );

        foreach ($node['children'] ?? [] as $child) {
            $this->createNode($child, $account->id, $level + 1);
        }
    }
}
