<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FtthDailyReport extends Model
{
    protected $fillable = [
        'report_number',
        'report_date',
        'project_name',
        'phase',
        'weather',
        'area_sector',
        'supervisor',
        'contractor_team',
        'workers_count',
        'start_time',
        'end_time',
        'works',
        'materials',
        'next_day_plan',
        'next_day_resources',
        'obstacles',
        'remediation',
        'quality_notes',
        'safety_notes',
        'attachments_notes',
        'next_day_obstacles',
        'next_day_coordination',
        'next_day_safety',
        'next_day_inspection',
        'next_day_goal',
        'prepared_by',
        'approved_by',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'workers_count' => 'integer',
            'works' => 'array',
            'materials' => 'array',
            'next_day_plan' => 'array',
            'next_day_resources' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
