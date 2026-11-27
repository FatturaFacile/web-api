<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Distribution\Http\Controllers\ChannelController;
use Modules\Distribution\Http\Controllers\ConsignmentController;
use Modules\Distribution\Http\Controllers\DrpController;
use Modules\Distribution\Http\Controllers\LookupController;
use Modules\Distribution\Http\Controllers\NetworkController;
use Modules\Distribution\Http\Controllers\ReplenishmentController;
use Modules\Distribution\Http\Controllers\TransferController;
use Modules\Distribution\Http\Middleware\RenderRuleViolations;

/*
|--------------------------------------------------------------------------
| Distribution, multi-warehouse and transfers: DRP (Issue #271)
|--------------------------------------------------------------------------
| Network topology and lanes, transfers with goods in transit, replenishment rules and balancing proposals, time-phased DRP,
| consignment and 3PL warehouses, sales channels and available-to-promise.
*/
Route::prefix('api/v1/distribution')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:distribution', RenderRuleViolations::class])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'lookups'])->name('distribution.lookups');
        Route::get('dashboard', [LookupController::class, 'dashboard'])->name('distribution.dashboard');

        Route::get('network/warehouses', [NetworkController::class, 'warehouses'])->name('distribution.network.warehouses');
        Route::put('network/warehouses/{id}/profile', [NetworkController::class, 'saveProfile'])->whereUuid('id')->name('distribution.network.profile');
        Route::get('lanes', [NetworkController::class, 'lanes'])->name('distribution.lanes.index');
        Route::post('lanes', [NetworkController::class, 'storeLane'])->name('distribution.lanes.store');
        Route::put('lanes/{id}', [NetworkController::class, 'updateLane'])->whereUuid('id')->name('distribution.lanes.update');
        Route::delete('lanes/{id}', [NetworkController::class, 'destroyLane'])->whereUuid('id')->name('distribution.lanes.destroy');

        Route::get('transfers', [TransferController::class, 'index'])->name('distribution.transfers.index');
        Route::post('transfers', [TransferController::class, 'store'])->name('distribution.transfers.store');
        Route::get('transfers/{id}', [TransferController::class, 'show'])->whereUuid('id')->name('distribution.transfers.show');
        Route::post('transfers/{id}/approve', [TransferController::class, 'approve'])->whereUuid('id')->name('distribution.transfers.approve');
        Route::post('transfers/{id}/dispatch', [TransferController::class, 'dispatch'])->whereUuid('id')->name('distribution.transfers.dispatch');
        Route::post('transfers/{id}/receive', [TransferController::class, 'receive'])->whereUuid('id')->name('distribution.transfers.receive');
        Route::post('transfers/{id}/cancel', [TransferController::class, 'cancel'])->whereUuid('id')->name('distribution.transfers.cancel');
        Route::get('availability', [TransferController::class, 'availability'])->name('distribution.availability');
        Route::get('in-transit', [TransferController::class, 'inTransit'])->name('distribution.in-transit');

        Route::get('replenishment-rules', [ReplenishmentController::class, 'rules'])->name('distribution.rules.index');
        Route::post('replenishment-rules', [ReplenishmentController::class, 'storeRule'])->name('distribution.rules.store');
        Route::get('replenishment-rules/{id}', [ReplenishmentController::class, 'showRule'])->whereUuid('id')->name('distribution.rules.show');
        Route::put('replenishment-rules/{id}', [ReplenishmentController::class, 'updateRule'])->whereUuid('id')->name('distribution.rules.update');
        Route::delete('replenishment-rules/{id}', [ReplenishmentController::class, 'destroyRule'])->whereUuid('id')->name('distribution.rules.destroy');

        Route::get('proposals', [ReplenishmentController::class, 'proposals'])->name('distribution.proposals.index');
        Route::post('proposals/generate', [ReplenishmentController::class, 'generate'])->name('distribution.proposals.generate');
        Route::post('proposals/approve', [ReplenishmentController::class, 'approve'])->name('distribution.proposals.approve');
        Route::get('proposals/{id}', [ReplenishmentController::class, 'showProposal'])->whereUuid('id')->name('distribution.proposals.show');
        Route::put('proposals/{id}', [ReplenishmentController::class, 'adjust'])->whereUuid('id')->name('distribution.proposals.adjust');
        Route::post('proposals/{id}/reject', [ReplenishmentController::class, 'reject'])->whereUuid('id')->name('distribution.proposals.reject');

        Route::post('drp/runs', [DrpController::class, 'run'])->name('distribution.drp.run');
        Route::get('drp/runs', [DrpController::class, 'runs'])->name('distribution.drp.runs');
        Route::get('drp/runs/{id}', [DrpController::class, 'show'])->whereUuid('id')->name('distribution.drp.show');
        Route::get('drp/runs/{id}/grid', [DrpController::class, 'grid'])->whereUuid('id')->name('distribution.drp.grid');
        Route::get('drp/alerts', [DrpController::class, 'alerts'])->name('distribution.drp.alerts');

        Route::get('channels', [ChannelController::class, 'index'])->name('distribution.channels.index');
        Route::post('channels', [ChannelController::class, 'store'])->name('distribution.channels.store');
        Route::put('channels/{id}', [ChannelController::class, 'update'])->whereUuid('id')->name('distribution.channels.update');
        Route::delete('channels/{id}', [ChannelController::class, 'destroy'])->whereUuid('id')->name('distribution.channels.destroy');
        Route::get('channels/{id}/warehouses', [ChannelController::class, 'rules'])->whereUuid('id')->name('distribution.channels.rules');
        Route::post('channels/{id}/warehouses', [ChannelController::class, 'storeRule'])->whereUuid('id')->name('distribution.channels.rules.store');
        Route::put('channel-warehouses/{id}', [ChannelController::class, 'updateRule'])->whereUuid('id')->name('distribution.channel-warehouses.update');
        Route::delete('channel-warehouses/{id}', [ChannelController::class, 'destroyRule'])->whereUuid('id')->name('distribution.channel-warehouses.destroy');
        Route::get('atp', [ChannelController::class, 'atp'])->name('distribution.atp');
        Route::get('atp/matrix', [ChannelController::class, 'matrix'])->name('distribution.atp.matrix');

        Route::get('consignment/stock', [ConsignmentController::class, 'stock'])->name('distribution.consignment.stock');
        Route::get('consignment/settlements', [ConsignmentController::class, 'settlements'])->name('distribution.consignment.settlements');
        Route::post('consignment/settlements', [ConsignmentController::class, 'settle'])->name('distribution.consignment.settle');
        Route::post('consignment/returns', [ConsignmentController::class, 'returnStock'])->name('distribution.consignment.returns');
        Route::get('threepl/reconciliations', [ConsignmentController::class, 'reconciliations'])->name('distribution.threepl.index');
        Route::post('threepl/reconciliations', [ConsignmentController::class, 'reconcile'])->name('distribution.threepl.reconcile');
    });
