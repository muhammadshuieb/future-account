<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Models\Employee;
use App\Models\SalaryRecord;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryRecordController extends ApiController
{
    public function __construct(protected JournalEntryService $journals) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('hr.view');

        return $this->ok(SalaryRecord::query()->with('employee')->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('hr.manage');
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'allowances' => ['numeric', 'min:0'],
            'deductions' => ['numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $basic = (float) ($data['basic_salary'] ?? $employee->basic_salary);
        $allowances = (float) ($data['allowances'] ?? 0);
        $deductions = (float) ($data['deductions'] ?? 0);
        $net = round($basic + $allowances - $deductions, 2);

        $record = SalaryRecord::query()->create([
            'employee_id' => $employee->id,
            'period' => $data['period'],
            'basic_salary' => $basic,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'net_salary' => $net,
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
        ]);

        if (($data['status'] ?? 'draft') === 'posted') {
            return $this->post($record);
        }

        return $this->ok($record->load('employee'), 201);
    }

    public function post(SalaryRecord $salaryRecord): JsonResponse
    {
        $this->authorizePermission('hr.manage');

        if ($salaryRecord->status === 'posted') {
            return response()->json(['message' => 'مسجّل مسبقاً.'], 422);
        }

        $salaryRecord->load('employee');

        $expense = Account::query()->where('code', '5102')->firstOrFail();
        $cash = Account::query()->where('code', '1101')->firstOrFail();

        $entry = $this->journals->create([
            'entry_date' => now()->toDateString(),
            'description' => 'راتب '.($salaryRecord->employee?->name ?? '').' — '.$salaryRecord->period,
            'reference' => $salaryRecord->period,
            'status' => 'posted',
        ], [
            ['account_id' => $expense->id, 'debit' => (float) $salaryRecord->net_salary, 'credit' => 0],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => (float) $salaryRecord->net_salary],
        ], request()->user());

        $salaryRecord->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

        return $this->ok($salaryRecord->fresh('employee'));
    }
}
