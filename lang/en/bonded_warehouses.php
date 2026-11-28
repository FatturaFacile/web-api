<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'List retrieved successfully.',
        'loaded' => 'Data retrieved successfully.',
        'authorization_saved' => 'The warehouse authorization was saved.',
        'authorization_deleted' => 'The warehouse authorization was deleted.',
        'mrn_registered' => 'The entry into the warehouse under the MRN was registered and the CGU guarantee was engaged.',
        'discharge_simulated' => 'Discharge simulation calculated: nothing was recorded.',
        'discharge_executed' => 'The discharge from the warehouse was recorded and the guarantee share was released.',
        'vat_inbound_registered' => 'The load was recorded in the IVA warehouse register.',
        'extraction_previewed' => 'Extraction preview calculated: nothing was recorded.',
        'extraction_emitted' => 'The extraction was recorded and the electronic self-invoice was created.',
        'reconciliation_done' => 'The AEO reconciliation was run.',
    ],

    'errors' => [
        'record_immutable' => 'This record is part of the audit trail and can be neither changed nor deleted.',
        'authorization_not_found' => 'Warehouse authorization not found.',
        'authorization_inactive' => 'Authorization :code is not active or not valid on the date of the operation.',
        'regime_not_customs_warehouse' => 'This authorization is an IVA warehouse: use the Art. 50-bis register.',
        'regime_not_vat_depot' => 'This authorization is not an Art. 50-bis IVA warehouse.',
        'declaration_type_not_allowed' => 'Declaration :declaration is not allowed for regime :regime.',
        'invalid_mrn' => 'The MRN must have 18 characters: year (2 digits), country (2 letters) and 14 alphanumerics.',
        'invalid_discharge_mrn' => 'Enter the valid MRN of the discharge declaration.',
        'invalid_taric' => 'The TARIC code must have 8 or 10 digits.',
        'mrn_already_registered' => 'MRN :mrn item :item is already registered.',
        'cgu_ceiling_exceeded' => 'The CGU guarantee ceiling would be exceeded: available :available :currency, requested :requested :currency.',
        'cgu_release_exceeds_utilized' => 'The release exceeds the guarantee in use.',
        'ceiling_below_utilized' => 'The ceiling cannot be lower than the guarantee already in use (:utilized).',
        'authorization_in_use' => 'The authorization has lots or an engaged guarantee and cannot be deleted.',
        'quantity_must_be_positive' => 'The quantity must be greater than zero.',
        'insufficient_remaining_quantity' => 'The remaining quantity in the warehouse is insufficient: available :available, requested :requested.',
        'insufficient_remaining_weight' => 'The remaining net weight is insufficient for MRN :mrn.',
        'lot_not_found' => 'MRN lot not found.',
        'discharge_before_entry' => 'The discharge date precedes the entry of MRN :mrn.',
        'usual_handling_code_required' => 'Enter the usual handling operation (Annex 71-03 CDU).',
        'fifo_needs_product_and_quantity' => 'A FIFO discharge needs a product and a quantity.',
        'movement_not_inbound' => 'The movement is not a load of the IVA warehouse.',
        'movement_not_extraction' => 'The movement is not an extraction from the IVA warehouse.',
        'negative_value' => 'Values cannot be negative.',
        'f24_required' => 'A TD23 extraction needs the F24 payment reference and date.',
        'f24_not_allowed' => 'The F24 payment applies to TD23 extractions only.',
        'insufficient_depot_stock' => 'The stock of the IVA warehouse is insufficient: available :available, requested :requested.',
        'self_contact_missing' => 'Your own company contact (same VAT number) is missing, so the self-invoice cannot be issued.',
        'unknown_dataset' => 'Export not available.',
    ],

    'invoice' => [
        'goods_line' => 'Extraction from IVA warehouse, lot :lot, quantity :quantity',
        'services_line' => 'Storage and processing services rendered in the IVA warehouse',
        'note' => 'Extraction from IVA warehouse (Art. 50-bis DL 331/1993), taxable base :base, document :type.',
    ],
];
