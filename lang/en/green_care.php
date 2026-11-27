<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Records retrieved successfully.',
        'loaded' => 'Record retrieved successfully.',
        'site_saved' => 'The site was saved.',
        'site_deleted' => 'The site was deleted.',
        'contract_saved' => 'The seasonal contract was saved.',
        'contract_deleted' => 'The seasonal contract was deleted.',
        'contract_activated' => 'The contract is active.',
        'contract_suspended' => 'The contract was suspended.',
        'billing_previewed' => 'Billing preview calculated.',
        'invoice_generated' => 'The draft invoice was generated.',
        'crew_saved' => 'The crew was saved.',
        'crew_deleted' => 'The crew was deleted.',
        'license_saved' => 'The operator license was saved.',
        'license_deleted' => 'The operator license was deleted.',
        'license_checked' => 'The license of the operator was checked.',
        'route_saved' => 'The route was saved.',
        'route_deleted' => 'The route was deleted.',
        'routes_optimized' => 'The weekly routes were planned.',
        'routes_rescheduled' => 'The stops hit by the weather alert were rescheduled.',
        'weather_refreshed' => 'The weather outlook was updated.',
        'intervention_logged' => 'The intervention was recorded.',
        'photos_saved' => 'The photos were saved.',
        'treatment_recorded' => 'The treatment was written in the phytosanitary diary.',
        'slip_saved' => 'The green waste slip was saved.',
        'slip_deleted' => 'The green waste slip was deleted.',
    ],

    'errors' => [
        'operator_license_missing' => 'The operator has no phytosanitary license accepted in :jurisdiction (PER / PNRP / OPF in Switzerland, patentino PAN in Italy).',
        'operator_license_expired' => 'The phytosanitary license of the operator is not valid on :date (expiry :expiry).',
        'registration_number_invalid' => 'The registration number of the product is not valid for :jurisdiction (example :hint).',
        'contract_not_active' => 'The contract is not active.',
        'contract_not_billable' => 'A draft contract cannot be billed: activate it first.',
        'contract_invalid_transition' => 'The contract cannot change status from :status.',
        'nothing_to_bill' => 'There is nothing to bill on this contract.',
        'stop_already_completed' => 'The stop was already closed.',
        'route_locked' => 'The route has already started or is closed and cannot be changed.',
        'week_start_invalid' => 'The week must start on a Monday.',
        'no_crews_available' => 'There is no active crew to plan.',
        'warehouse_required' => 'A warehouse is required to take the product out of stock.',
        'condominium_data_missing' => 'The fiscal code of the condominium is required to invoice with the withholding.',
        'weather_unavailable' => 'The weather outlook is not available now.',
        'file_not_found' => 'The file was not found.',
    ],

    'validation' => [
        'polygon' => 'The area must be a GeoJSON polygon with at least four positions.',
        'waste_code' => 'The waste code is not valid for :jurisdiction.',
    ],

    'waste' => [
        'default_description' => 'Green waste from garden maintenance',
    ],

    'billing' => [
        'installment' => 'Maintenance contract :contract - installment :number of :total',
        'snow_standby' => 'Contract :contract - winter snow clearing standby fee',
        'extra_passage' => 'Extra passage no. :number (:included included) of :date',
        'time_and_material' => 'Time and material :date - :site',
        'waste' => 'Green waste disposal :code - :kg kg (slip :slip)',
        'invoice_note' => 'Green maintenance contract :contract - :site',
    ],

    'register' => [
        'title_it' => 'Field notebook (Quaderno di Campagna) - register of phytosanitary treatments',
        'title_ch' => 'Register of phytosanitary treatments (FOAG / UFAG)',
        'company' => 'Company',
        'period' => 'Period',
        'generated' => 'Generated',
        'entries' => ':count entries',
        'columns' => [
            'date' => 'Date',
            'site' => 'Site',
            'crop' => 'Crop / surface',
            'area' => 'Area (m²)',
            'pest' => 'Pest / weed',
            'product' => 'Product',
            'registration' => 'Reg. no.',
            'ingredient' => 'Active ingredient',
            'dose' => 'Dose',
            'water' => 'Water (l)',
            'operator' => 'Operator',
            'license' => 'License',
            'reentry' => 'Re-entry',
        ],
    ],
];
