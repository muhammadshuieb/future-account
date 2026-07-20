<?php

namespace App\Http\Controllers\Api;

use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveRequestController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('hr.view');

        return $this->ok(LeaveRequest::query()->with('employee')->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('hr.manage');
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'leave_type' => ['required', 'string', 'max:40'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'reason' => ['nullable', 'string'],
        ]);

        return $this->ok(LeaveRequest::query()->create($data)->load('employee'), 201);
    }

    public function update(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizePermission('hr.manage');
        $leaveRequest->update($request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'leave_type' => ['required', 'string', 'max:40'],
            'status' => ['required', 'in:pending,approved,rejected'],
            'reason' => ['nullable', 'string'],
        ]));

        return $this->ok($leaveRequest->fresh('employee'));
    }

    public function destroy(LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizePermission('hr.manage');
        $leaveRequest->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
