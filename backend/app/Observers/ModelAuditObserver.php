<?php

namespace App\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class ModelAuditObserver
{
    public function __construct(protected AuditLogger $audit) {}

    public function created(Model $model): void
    {
        $this->audit->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->audit->record($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->audit->record($model, 'deleted');
    }
}
