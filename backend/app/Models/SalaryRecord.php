<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRecord extends Model
{
    protected $fillable = [
        'employee_id', 'period', 'basic_salary', 'allowances', 'deductions',
        'net_salary', 'status', 'journal_entry_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
