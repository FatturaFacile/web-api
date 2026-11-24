<?php

declare(strict_types=1);

return [
    'pipeline_has_no_stage' => 'The selected pipeline has no active open stage.',
    'stage_in_other_pipeline' => 'The target stage belongs to a different pipeline than the opportunity.',
    'loss_reason_required' => 'A loss reason is required to close the opportunity as lost.',
    'loss_reason_invalid' => 'The selected loss reason does not exist or is not active.',
    'winning_competitor_required' => 'The winning competitor is required to close the opportunity as lost.',
    'pipeline_in_use' => 'The pipeline is used by opportunities: deactivate it instead.',
    'pipeline_default_required' => 'At least one active default pipeline is required.',
    'pipeline_default_not_deletable' => 'The default pipeline cannot be deleted or deactivated.',
    'loss_reason_in_use' => 'The loss reason is used by opportunities: deactivate it instead.',
    'stage_pipeline_invalid' => 'The pipeline does not belong to the active tenant.',
    'quota_period_invalid' => 'The quota period end cannot precede its start.',
];
