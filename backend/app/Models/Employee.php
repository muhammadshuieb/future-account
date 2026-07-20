<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'branch_id', 'employee_number', 'name', 'job_title', 'department',
        'hire_date', 'birth_date', 'phone', 'email', 'basic_salary', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'birth_date' => 'date',
            'basic_salary' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function attendances(): HasMany { return $this->hasMany(Attendance::class); }
    public function leaveRequests(): HasMany { return $this->hasMany(LeaveRequest::class); }
    public function salaryRecords(): HasMany { return $this->hasMany(SalaryRecord::class); }
}
