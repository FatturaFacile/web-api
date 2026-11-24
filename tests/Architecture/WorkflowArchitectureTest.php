<?php

declare(strict_types=1);

arch('workflow module enforces strict types across all classes')
    ->expect('Modules\Workflow')
    ->toUseStrictTypes();

arch('all workflow contracts are interfaces')
    ->expect('Modules\Workflow\Contracts')
    ->toBeInterfaces();

arch('all workflow domain events implement DomainEventInterface')
    ->expect('Modules\Workflow\Events')
    ->toImplement('Enterprise\EventBus\Contracts\DomainEventInterface');

arch('all workflow models extend BaseEnterpriseModel')
    ->expect('Modules\Workflow\Models')
    ->toExtend('Enterprise\Foundation\Models\BaseEnterpriseModel');

arch('all workflow jobs implement ShouldQueue and configure afterCommit')
    ->expect('Modules\Workflow\Jobs')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');

arch('workflow controllers extend base controller')
    ->expect('Modules\Workflow\Http\Controllers')
    ->toExtend('App\Http\Controllers\Controller');
