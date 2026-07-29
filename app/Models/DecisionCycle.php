<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DecisionCycle extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SIMULATED = 'simulated';
    public const STATUS_RUNNING = 'running';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'company_id', 'created_by', 'objective', 'scope', 'limits', 'duration_days',
        'estimated_gain', 'status', 'treatment_product_ids', 'control_product_ids',
        'applied_product_ids', 'baseline_snapshot', 'simulation_result', 'roi_result',
        'abort_reason', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'limits' => 'array',
        'treatment_product_ids' => 'array',
        'control_product_ids' => 'array',
        'applied_product_ids' => 'array',
        'baseline_snapshot' => 'array',
        'simulation_result' => 'array',
        'roi_result' => 'array',
        'estimated_gain' => 'float',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function logs()
    {
        return $this->hasMany(DecisionCycleLog::class);
    }
}
