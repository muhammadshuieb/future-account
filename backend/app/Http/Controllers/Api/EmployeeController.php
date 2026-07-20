<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\SalaryRecord;
use App\Models\Account;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('hr.view');
        $query = Employee::query()->with('branch')->orderBy('employee_number');
        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('employee_number', 'like', "%{$s}%"));
        }

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('hr.manage');
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:32', 'unique:employees,employee_number'],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'basic_salary' => ['numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        return $this->ok(Employee::query()->create($data)->load('branch'), 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizePermission('hr.manage');
        $employee->update($request->validate([
            'employee_number' => ['required', 'string', 'max:32', 'unique:employees,employee_number,'.$employee->id],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'basic_salary' => ['numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]));

        return $this->ok($employee->fresh('branch'));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorizePermission('hr.manage');
        $employee->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
