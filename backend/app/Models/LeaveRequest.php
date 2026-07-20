<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = ['employee_id', 'from_date', 'to_date', 'leave_type', 'status', 'reason'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
