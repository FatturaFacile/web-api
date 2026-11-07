<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the association endpoints, derived from their form requests.
 */
#[OA\Schema(
    schema: 'AssociationCatalogEntryRequest',
    description: 'Fields of the catalog entry. They depend on the catalog: code and name for most, the rules are in the catalog definition.',
    type: 'object',
    additionalProperties: true,
)]
#[OA\Schema(
    schema: 'AssociationSettingsRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'card_prefix', type: 'string', maxLength: 20),
        new OA\Property(property: 'card_padding', type: 'integer', maximum: 12, minimum: 1),
        new OA\Property(property: 'receipt_prefix', type: 'string', maxLength: 20),
        new OA\Property(property: 'default_receipt_kind', type: 'string', enum: ['simple', 'full']),
        new OA\Property(property: 'fee_reminder_days', type: 'array', items: new OA\Items(type: 'integer')),
        new OA\Property(property: 'renewal_reminder_days', type: 'array', items: new OA\Items(type: 'integer')),
        new OA\Property(property: 'certificate_alert_days', type: 'integer', example: 30),
        new OA\Property(property: 'presence_max_hours', type: 'integer', example: 12),
        new OA\Property(property: 'ledger_enabled', type: 'boolean'),
        new OA\Property(property: 'default_revenue_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'max_proxies_per_holder', description: 'From the statute; empty refuses proxies', type: 'integer', nullable: true),
        new OA\Property(property: 'reimbursement_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'shift_alert_days', type: 'integer', example: 3),
        new OA\Property(property: 'volunteer_expiry_alert_days', type: 'integer', example: 30),
        new OA\Property(property: 'donation_receipt_prefix', type: 'string', example: 'DON-'),
        new OA\Property(property: 'donation_revenue_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'five_per_mille_revenue_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lex398_enabled', description: 'The company opted for the regime of Law 398/1991', type: 'boolean'),
        new OA\Property(property: 'lex398_since', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationMemberRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', description: 'Existing contact; a new one is created otherwise', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string'),
        new OA\Property(property: 'company_name', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'birth_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'birth_place', type: 'string', nullable: true),
        new OA\Property(property: 'gender', type: 'string', nullable: true),
        new OA\Property(property: 'tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'identity_document', type: 'string', nullable: true),
        new OA\Property(property: 'health_notes', description: 'Needs the health capability', type: 'string', nullable: true),
        new OA\Property(property: 'allergies', description: 'Needs the health capability', type: 'string', nullable: true),
        new OA\Property(property: 'emergency_contact', type: 'string', nullable: true),
        new OA\Property(property: 'internal_notes', type: 'string', nullable: true),
        new OA\Property(property: 'family_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'custom_fields', type: 'object', additionalProperties: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationRegistryTypeAssignmentRequest',
    type: 'object',
    required: ['registry_type_id'],
    properties: [
        new OA\Property(property: 'registry_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationSocialRoleAssignmentRequest',
    type: 'object',
    required: ['social_role_id', 'valid_from'],
    properties: [
        new OA\Property(property: 'social_role_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationDocumentUploadRequest',
    type: 'object',
    required: ['file', 'title'],
    properties: [
        new OA\Property(property: 'file', type: 'string', format: 'binary'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'category', type: 'string', nullable: true),
        new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'visible_to_member', type: 'boolean'),
    ]
)]
#[OA\Schema(schema: 'AssociationImportFileRequest', type: 'object', required: ['file'], properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')])]
#[OA\Schema(schema: 'AssociationImportCommitRequest', type: 'object', properties: [new OA\Property(property: 'on_duplicate', type: 'string', enum: ['skip', 'update'])])]
#[OA\Schema(
    schema: 'AssociationGuardianRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'relationship', type: 'string', example: 'parent'),
        new OA\Property(property: 'is_primary', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'AssociationAuthorizationRequest',
    type: 'object',
    required: ['title', 'body'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'body', type: 'string'),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(schema: 'AssociationAuthorizationSignRequest', type: 'object', required: ['signed_name'], properties: [new OA\Property(property: 'signed_name', type: 'string')])]
#[OA\Schema(
    schema: 'AssociationMembershipRequest',
    type: 'object',
    required: ['member_id', 'year_id', 'membership_type_id'],
    properties: [
        new OA\Property(property: 'member_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'membership_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'activate', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'AssociationMembershipRenewRequest',
    type: 'object',
    required: ['year_id'],
    properties: [
        new OA\Property(property: 'year_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'membership_type_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'activate', type: 'boolean'),
    ]
)]
#[OA\Schema(schema: 'AssociationReasonRequest', type: 'object', properties: [new OA\Property(property: 'reason', type: 'string', nullable: true)])]
#[OA\Schema(
    schema: 'AssociationBadgeRequest',
    type: 'object',
    required: ['uid'],
    properties: [
        new OA\Property(property: 'uid', type: 'string'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationEnrolmentFormRequest',
    type: 'object',
    required: ['title', 'year_id', 'membership_type_id'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'year_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'membership_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'approval_mode', type: 'string', enum: ['automatic', 'board']),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'opens_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'closes_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'fields', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'type', type: 'string', enum: ['text', 'number', 'date', 'boolean', 'select']),
            new OA\Property(property: 'required', type: 'boolean'),
            new OA\Property(property: 'options', type: 'array', items: new OA\Items(type: 'string')),
        ])),
    ]
)]
#[OA\Schema(
    schema: 'AssociationFormDocumentRequest',
    type: 'object',
    required: ['title', 'mode'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'mode', type: 'string', enum: ['required', 'optional', 'download_only']),
        new OA\Property(property: 'file', description: 'Template offered for download', type: 'string', format: 'binary'),
    ]
)]
#[OA\Schema(
    schema: 'AssociationDecisionRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'reference', description: 'Reference of the decision of the board', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationEnrolmentSubmission',
    description: 'The fields of the form, the configured extra fields and one file per requested document.',
    type: 'object',
    required: ['first_name', 'last_name', 'email', 'birth_date', 'tax_code', 'privacy_accepted'],
    properties: [
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'birth_date', type: 'string', format: 'date'),
        new OA\Property(property: 'birth_place', type: 'string', nullable: true),
        new OA\Property(property: 'gender', type: 'string', nullable: true),
        new OA\Property(property: 'tax_code', type: 'string'),
        new OA\Property(property: 'privacy_accepted', type: 'boolean'),
        new OA\Property(property: 'extra', type: 'object', additionalProperties: true),
        new OA\Property(property: 'guardian_first_name', description: 'Required for a minor', type: 'string', nullable: true),
        new OA\Property(property: 'guardian_last_name', description: 'Required for a minor', type: 'string', nullable: true),
        new OA\Property(property: 'guardian_email', description: 'Required for a minor', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'guardian_phone', type: 'string', nullable: true),
        new OA\Property(property: 'guardian_relationship', type: 'string', nullable: true),
        new OA\Property(property: 'documents', description: 'Files by form document id', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string', format: 'binary')),
    ]
)]
#[OA\Schema(
    schema: 'AssociationFeeAssignmentRequest',
    type: 'object',
    required: ['member_id', 'fee_plan_id'],
    properties: [
        new OA\Property(property: 'member_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'fee_plan_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'starts_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'ends_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'course_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'membership_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'payer_contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'apply_family_discount', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'AssociationPaymentRequest',
    type: 'object',
    required: ['amount', 'paid_on', 'method'],
    properties: [
        new OA\Property(property: 'amount', type: 'string', example: '50.00'),
        new OA\Property(property: 'paid_on', type: 'string', format: 'date'),
        new OA\Property(property: 'method', type: 'string', enum: ['cash', 'bank_transfer', 'card', 'online', 'cheque']),
        new OA\Property(property: 'reference', type: 'string', nullable: true),
        new OA\Property(property: 'receipt_kind', type: 'string', enum: ['simple', 'full'], nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationCourseRequest',
    type: 'object',
    required: ['title', 'starts_on'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['recurring', 'event', 'big_event']),
        new OA\Property(property: 'code', type: 'string', nullable: true),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'year_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'discipline_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'resource_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'access_area_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'fee_plan_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
        new OA\Property(property: 'ends_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'capacity', type: 'integer', nullable: true),
        new OA\Property(property: 'waitlist_enabled', type: 'boolean'),
        new OA\Property(property: 'auto_close_when_full', type: 'boolean'),
        new OA\Property(property: 'registrations_open', type: 'boolean'),
        new OA\Property(property: 'required_certificate', type: 'string', enum: ['non_competitive', 'competitive'], nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationScheduleRequest',
    type: 'object',
    required: ['pattern', 'starts_at', 'ends_at'],
    properties: [
        new OA\Property(property: 'pattern', type: 'string', enum: ['daily', 'weekday', 'date']),
        new OA\Property(property: 'weekday', description: 'ISO weekday, 1 is Monday', type: 'integer', nullable: true),
        new OA\Property(property: 'on_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'starts_at', type: 'string', example: '18:00'),
        new OA\Property(property: 'ends_at', type: 'string', example: '19:00'),
        new OA\Property(property: 'resource_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationSuspensionRequest',
    type: 'object',
    required: ['starts_on', 'ends_on'],
    properties: [
        new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
        new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(schema: 'AssociationMemberReferenceRequest', type: 'object', required: ['member_id'], properties: [new OA\Property(property: 'member_id', type: 'string', format: 'uuid')])]
#[OA\Schema(
    schema: 'AssociationAttendanceRequest',
    type: 'object',
    required: ['date', 'entries'],
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'entries', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'member_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'status', type: 'string', enum: ['present', 'absent']),
        ])),
    ]
)]
#[OA\Schema(
    schema: 'AssociationTicketTypeRequest',
    type: 'object',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'price', type: 'string', example: '25.00'),
        new OA\Property(property: 'quantity', type: 'integer', nullable: true),
    ]
)]
#[OA\Schema(schema: 'AssociationCheckInRequest', type: 'object', required: ['ticket_code'], properties: [new OA\Property(property: 'ticket_code', type: 'string')])]
#[OA\Schema(
    schema: 'AssociationPeriodRequest',
    type: 'object',
    required: ['from', 'to'],
    properties: [
        new OA\Property(property: 'from', type: 'string', format: 'date'),
        new OA\Property(property: 'to', type: 'string', format: 'date'),
    ]
)]
#[OA\Schema(
    schema: 'AssociationBookingRequest',
    type: 'object',
    required: ['resource_id', 'starts_at', 'ends_at'],
    properties: [
        new OA\Property(property: 'resource_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'member_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'course_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'title', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationCertificateRequest',
    type: 'object',
    required: ['kind', 'expires_on'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['non_competitive', 'competitive']),
        new OA\Property(property: 'discipline_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'issued_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'expires_on', type: 'string', format: 'date'),
        new OA\Property(property: 'physician', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'file', type: 'string', format: 'binary'),
    ]
)]
#[OA\Schema(
    schema: 'AssociationDeviceRequest',
    type: 'object',
    required: ['area_id', 'name'],
    properties: [
        new OA\Property(property: 'area_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out', 'both']),
    ]
)]
#[OA\Schema(
    schema: 'AssociationAccessVerifyRequest',
    type: 'object',
    required: ['credential'],
    properties: [
        new OA\Property(property: 'credential', description: 'Text of the QR code or badge id', type: 'string'),
        new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out'], nullable: true),
    ]
)]
#[OA\Schema(schema: 'AssociationAffiliationRequest', type: 'object', required: ['affiliation_code'], properties: [new OA\Property(property: 'affiliation_code', type: 'string')])]
#[OA\Schema(
    schema: 'AssociationAreaContactRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'address', type: 'object', properties: [
            new OA\Property(property: 'street', type: 'string'),
            new OA\Property(property: 'house_number', type: 'string', nullable: true),
            new OA\Property(property: 'postal_code', type: 'string'),
            new OA\Property(property: 'city', type: 'string'),
            new OA\Property(property: 'state_canton', type: 'string', nullable: true),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'AssociationPaymentCallbackRequest',
    type: 'object',
    required: ['reference', 'amount', 'status'],
    properties: [
        new OA\Property(property: 'reference', type: 'string'),
        new OA\Property(property: 'amount', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['paid', 'failed']),
    ]
)]
final class AssociationsRequestSchemas {}
