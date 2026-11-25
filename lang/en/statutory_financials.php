<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Statutory financial statements data loaded.',
        'saved' => 'Saved.',
        'deleted' => 'Deleted.',
        'transitioned' => 'Status updated.',
        'generated' => 'The statements were generated with the rounding quadrature.',
        'cash_flow_generated' => 'The cash flow statement was generated.',
        'notes_populated' => 'The notes were populated from the subledgers.',
        'xbrl_exported' => 'The XBRL instance was exported and validated.',
        'pdfa_generated' => 'The PDF/A documents were generated and verified.',
        'pdfa_verified' => 'The PDF/A document was verified.',
        'indicators_computed' => 'The ratios and crisis indicators were computed.',
    ],
    'errors' => [
        'unsupported_standard' => 'The accounting standard :standard is not supported.',
        'fiscal_year_not_found' => 'The fiscal year was not found.',
        'comparative_year_same' => 'The comparative year must differ from the fiscal year.',
        'income_statement_method_not_allowed' => 'The income statement method :method is not allowed for :standard.',
        'transition_not_allowed' => 'A dossier in status :from cannot move to :to.',
        'filing_requires_valid_xbrl' => 'Filing requires an exported XBRL instance without validation errors.',
        'dossier_locked' => 'The dossier is locked and can no longer be changed.',
        'statements_not_generated' => 'Generate the statements first.',
        'notes_not_populated' => 'Populate the notes first.',
        'artifact_missing' => 'The stored file is no longer available.',
        'artifact_not_pdfa' => 'The file is not a PDF/A document.',
        'pdfa_kind_unknown' => 'The document kind :kind is unknown.',
        'pdfa_not_compliant' => 'The generated :kind document did not pass the PDF/A verification and was not stored.',
        'pdfa_font_missing' => 'The font file :path for the PDF/A documents is missing.',
        'pdfa_font_invalid' => 'The font file for the PDF/A documents is invalid (:table).',
        'pdfa_profile_missing' => 'The ICC colour profile for the PDF/A documents is missing.',
        'pdfa_profile_unsupported' => 'The PDF/A profile :profile is not supported.',
    ],
];
