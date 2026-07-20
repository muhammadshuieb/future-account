<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('hr.view');
        $query = Attendance::query()->with('employee')->latest('attendance_date');
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        return $this->ok($query->limit(200)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('hr.manage');
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'attendance_date' => ['required', 'date'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'status' => ['required', 'in:present,absent,leave'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = Attendance::query()->updateOrCreate(
            ['employee_id' => $data['employee_id'], 'attendance_date' => $data['attendance_date']],
            $data
        );

        return $this->ok($row->load('employee'), 201);
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $this->authorizePermission('hr.manage');
        $attendance->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
