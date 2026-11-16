<?php

declare(strict_types=1);

namespace Modules\Configurator\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the configure-price-quote endpoints. Amounts are decimal strings.
 */
#[OA\Tag(name: 'Configurator', description: 'Configure-price-quote: configurable product models with option groups, options and rules, live validation and pricing, margin guard with discount approval, conversion to a quote')]
#[OA\Response(response: 'ConfiguratorUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ConfiguratorForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ConfiguratorNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ConfiguratorUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'ConfiguratorVolumeBreak', type: 'object', required: ['min_quantity', 'discount_percent'], properties: [
    new OA\Property(property: 'min_quantity', type: 'number', example: 10),
    new OA\Property(property: 'discount_percent', type: 'number', example: 5),
])]
#[OA\Schema(schema: 'ConfiguratorModelRequest', type: 'object', required: ['code', 'name'], properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 40, example: 'DESK-PRO'),
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'base_product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'base_price_from_product', description: 'Take the base price from the price lists of the company', type: 'boolean'),
    new OA\Property(property: 'base_price', type: 'number', example: 450),
    new OA\Property(property: 'base_cost', type: 'number', example: 280),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'tax_rate', type: 'number', example: 22),
    new OA\Property(property: 'min_margin_percent', description: 'Margin guard limit; the company default applies when empty', type: 'number', nullable: true),
    new OA\Property(property: 'volume_breaks', type: 'array', nullable: true, items: new OA\Items(ref: '#/components/schemas/ConfiguratorVolumeBreak')),
])]
#[OA\Schema(schema: 'ConfiguratorGroupRequest', type: 'object', required: ['code', 'name', 'type'], properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 40),
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'type', type: 'string', enum: ['single', 'multi', 'range', 'boolean']),
    new OA\Property(property: 'is_required', type: 'boolean'),
    new OA\Property(property: 'min_select', type: 'integer', nullable: true),
    new OA\Property(property: 'max_select', type: 'integer', nullable: true),
    new OA\Property(property: 'range_min', type: 'number', nullable: true),
    new OA\Property(property: 'range_max', type: 'number', nullable: true),
    new OA\Property(property: 'range_step', type: 'number', nullable: true),
    new OA\Property(property: 'default_value', type: 'number', nullable: true),
    new OA\Property(property: 'unit', type: 'string', nullable: true, example: 'cm'),
    new OA\Property(property: 'unit_price_delta', description: 'Price per unit of a range group', type: 'number'),
    new OA\Property(property: 'unit_cost', description: 'Cost per unit of a range group', type: 'number'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sort_order', type: 'integer'),
])]
#[OA\Schema(schema: 'ConfiguratorOptionRequest', type: 'object', required: ['code', 'name'], properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 40),
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'price_delta', type: 'number'),
    new OA\Property(property: 'price_multiplier', description: 'Multiplies the price of the whole configuration; 1 has no effect', type: 'number', example: 1),
    new OA\Property(property: 'cost', type: 'number'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'price_from_product', description: 'Take the price from the price lists of the company', type: 'boolean'),
    new OA\Property(property: 'is_default', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'sort_order', type: 'integer'),
])]
#[OA\Schema(schema: 'ConfiguratorRuleCondition', description: 'An option condition (type option, option, negate) or a numeric condition on a range group (type range, group, operator, value).', type: 'object', required: ['type'], properties: [
    new OA\Property(property: 'type', type: 'string', enum: ['option', 'range']),
    new OA\Property(property: 'option', type: 'string', nullable: true),
    new OA\Property(property: 'negate', type: 'boolean'),
    new OA\Property(property: 'group', type: 'string', nullable: true),
    new OA\Property(property: 'operator', type: 'string', enum: ['eq', 'gt', 'gte', 'lt', 'lte'], nullable: true),
    new OA\Property(property: 'value', type: 'number', nullable: true),
])]
#[OA\Schema(schema: 'ConfiguratorRuleRequest', type: 'object', required: ['name', 'type'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'type', type: 'string', enum: ['requires', 'excludes', 'default', 'price_rule']),
    new OA\Property(property: 'match', type: 'string', enum: ['all', 'any']),
    new OA\Property(property: 'conditions', type: 'array', nullable: true, items: new OA\Items(ref: '#/components/schemas/ConfiguratorRuleCondition')),
    new OA\Property(property: 'target_options', description: 'Option codes required, excluded or defaulted', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
    new OA\Property(property: 'price_effect_type', type: 'string', enum: ['amount', 'percent'], nullable: true),
    new OA\Property(property: 'price_effect_value', description: 'Amount, or percentage of the component subtotal; negative for a discount', type: 'number', nullable: true),
    new OA\Property(property: 'message', type: 'string', nullable: true),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'ConfiguratorSelection', description: 'The answer to one group: option codes for a single, multiple or boolean group, a number for a range group.', type: 'object', required: ['group'], properties: [
    new OA\Property(property: 'group', type: 'string'),
    new OA\Property(property: 'options', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'value', type: 'number', nullable: true),
])]
#[OA\Schema(schema: 'ConfiguratorConfigurationRequest', type: 'object', required: ['selections'], properties: [
    new OA\Property(property: 'model_id', description: 'Only when a configuration is created', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string', nullable: true),
    new OA\Property(property: 'selections', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorSelection')),
    new OA\Property(property: 'quantity', type: 'number', example: 1),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'discount_percent', description: 'Extra discount asked for the configuration, checked by the margin guard', type: 'number'),
    new OA\Property(property: 'apply_defaults', description: 'Complete the groups without an answer with the defaults', type: 'boolean'),
])]
#[OA\Schema(schema: 'ConfiguratorDecisionRequest', type: 'object', properties: [
    new OA\Property(property: 'decision_notes', description: 'Required to reject', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ConfiguratorQuoteRequest', type: 'object', properties: [
    new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ConfiguratorOption', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'group_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'price_delta', type: 'string', example: '120.0000'),
    new OA\Property(property: 'price_multiplier', type: 'string', example: '1.0000'),
    new OA\Property(property: 'cost', type: 'string'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'price_from_product', type: 'boolean'),
    new OA\Property(property: 'is_default', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'sort_order', type: 'integer'),
])]
#[OA\Schema(schema: 'ConfiguratorGroup', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'model_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'type', type: 'string', enum: ['single', 'multi', 'range', 'boolean']),
    new OA\Property(property: 'is_required', type: 'boolean'),
    new OA\Property(property: 'min_select', type: 'integer', nullable: true),
    new OA\Property(property: 'max_select', type: 'integer', nullable: true),
    new OA\Property(property: 'range_min', type: 'string', nullable: true),
    new OA\Property(property: 'range_max', type: 'string', nullable: true),
    new OA\Property(property: 'range_step', type: 'string', nullable: true),
    new OA\Property(property: 'default_value', type: 'string', nullable: true),
    new OA\Property(property: 'unit', type: 'string', nullable: true),
    new OA\Property(property: 'unit_price_delta', type: 'string'),
    new OA\Property(property: 'unit_cost', type: 'string'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sort_order', type: 'integer'),
    new OA\Property(property: 'options', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorOption')),
])]
#[OA\Schema(schema: 'ConfiguratorRule', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'model_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'type', type: 'string', enum: ['requires', 'excludes', 'default', 'price_rule']),
    new OA\Property(property: 'match', type: 'string', enum: ['all', 'any']),
    new OA\Property(property: 'conditions', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorRuleCondition')),
    new OA\Property(property: 'target_options', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'price_effect_type', type: 'string', nullable: true),
    new OA\Property(property: 'price_effect_value', type: 'string', nullable: true),
    new OA\Property(property: 'message', type: 'string', nullable: true),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'ConfiguratorModel', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'base_product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'base_price_from_product', type: 'boolean'),
    new OA\Property(property: 'base_price', type: 'string'),
    new OA\Property(property: 'base_cost', type: 'string'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'tax_rate', type: 'string'),
    new OA\Property(property: 'min_margin_percent', type: 'string', nullable: true),
    new OA\Property(property: 'volume_breaks', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorVolumeBreak')),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'archived']),
    new OA\Property(property: 'version', description: 'Raised by every change of a published model', type: 'integer'),
    new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'groups', description: 'Only on the detail', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorGroup')),
    new OA\Property(property: 'rules', description: 'Only on the detail', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorRule')),
])]
#[OA\Schema(schema: 'ConfiguratorViolation', description: 'A reason why a selection is not valid. The code identifies it, the params fill a translated message and the message is the English fallback.', type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string', enum: ['unknown_group', 'duplicate_group', 'unknown_option', 'option_not_in_group', 'inactive_option', 'single_choice_exceeded', 'range_not_numeric', 'range_out_of_bounds', 'range_step_mismatch', 'required_group_missing', 'multi_below_min', 'multi_above_max', 'requires', 'excludes']),
    new OA\Property(property: 'severity', type: 'string', example: 'error'),
    new OA\Property(property: 'group', type: 'string', nullable: true),
    new OA\Property(property: 'option', type: 'string', nullable: true),
    new OA\Property(property: 'rule_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'params', type: 'object'),
])]
#[OA\Schema(schema: 'ConfiguratorPricingLine', description: 'One component of the configuration, which becomes one quote line.', type: 'object', properties: [
    new OA\Property(property: 'kind', type: 'string', enum: ['base', 'option', 'range', 'adjustment']),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'string'),
    new OA\Property(property: 'unit_price', type: 'string'),
    new OA\Property(property: 'discount_percentage', type: 'string'),
    new OA\Property(property: 'tax_rate', type: 'string'),
    new OA\Property(property: 'line_total', type: 'string'),
    new OA\Property(property: 'price_source', type: 'string', enum: ['model', 'option', 'group', 'rule', 'price_list', 'default_price_list', 'product']),
])]
#[OA\Schema(schema: 'ConfiguratorPricing', type: 'object', properties: [
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'quantity', type: 'string'),
    new OA\Property(property: 'multiplier', type: 'string'),
    new OA\Property(property: 'unit_list_price', type: 'string'),
    new OA\Property(property: 'unit_net_price', type: 'string'),
    new OA\Property(property: 'unit_cost', type: 'string'),
    new OA\Property(property: 'subtotal', type: 'string'),
    new OA\Property(property: 'discount_total', type: 'string'),
    new OA\Property(property: 'total_net', type: 'string'),
    new OA\Property(property: 'total_tax', type: 'string'),
    new OA\Property(property: 'total_gross', type: 'string'),
    new OA\Property(property: 'total_cost', type: 'string'),
    new OA\Property(property: 'margin_amount', type: 'string'),
    new OA\Property(property: 'margin_percent', type: 'string', nullable: true),
    new OA\Property(property: 'min_margin_percent', type: 'string'),
    new OA\Property(property: 'below_minimum_margin', type: 'boolean'),
    new OA\Property(property: 'requires_approval', type: 'boolean'),
    new OA\Property(property: 'volume_discount_percent', type: 'string'),
    new OA\Property(property: 'extra_discount_percent', type: 'string'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorPricingLine')),
    new OA\Property(property: 'applied_rules', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'amount', type: 'string'),
    ])),
])]
#[OA\Schema(schema: 'ConfiguratorEvaluation', type: 'object', properties: [
    new OA\Property(property: 'valid', type: 'boolean'),
    new OA\Property(property: 'violations', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorViolation')),
    new OA\Property(property: 'selections', description: 'The selections completed with the defaults', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorSelection')),
    new OA\Property(property: 'applied_defaults', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'group', type: 'string'),
        new OA\Property(property: 'option', type: 'string', nullable: true),
        new OA\Property(property: 'source', type: 'string'),
    ])),
    new OA\Property(property: 'pricing', ref: '#/components/schemas/ConfiguratorPricing'),
])]
#[OA\Schema(schema: 'ConfiguratorConfiguration', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'model_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'model', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'version', type: 'integer'),
    ]),
    new OA\Property(property: 'number', type: 'string', example: 'CFG-2027-0001'),
    new OA\Property(property: 'name', type: 'string', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'contact', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string')]),
    new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'string'),
    new OA\Property(property: 'discount_percent', type: 'string'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'selections', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorSelection')),
    new OA\Property(property: 'pricing', ref: '#/components/schemas/ConfiguratorPricing', nullable: true),
    new OA\Property(property: 'total_net', type: 'string'),
    new OA\Property(property: 'total_cost', type: 'string'),
    new OA\Property(property: 'margin_percent', type: 'string', nullable: true),
    new OA\Property(property: 'min_margin_percent', type: 'string', nullable: true),
    new OA\Property(property: 'requires_approval', type: 'boolean'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'validated', 'pending_approval', 'approved', 'rejected', 'converted']),
    new OA\Property(property: 'model_version', type: 'integer', nullable: true),
    new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'validated_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'approval_requested_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'approval_requested_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decided_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decision_notes', type: 'string', nullable: true),
    new OA\Property(property: 'quote_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quote_number', type: 'string', nullable: true),
    new OA\Property(property: 'converted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'snapshot', description: 'Only on the detail of a converted configuration: model version, rules, options and prices behind the quote', type: 'object', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'ConfiguratorRuleProblem', type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'rule_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
])]
#[OA\Schema(schema: 'ConfiguratorModelResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ConfiguratorModel')])]
#[OA\Schema(schema: 'ConfiguratorModelCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorModel'))])]
#[OA\Schema(schema: 'ConfiguratorGroupResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ConfiguratorGroup')])]
#[OA\Schema(schema: 'ConfiguratorOptionResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ConfiguratorOption')])]
#[OA\Schema(schema: 'ConfiguratorRuleResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ConfiguratorRule')])]
#[OA\Schema(schema: 'ConfiguratorRuleCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorRule'))])]
#[OA\Schema(schema: 'ConfiguratorRuleCheckResponse', type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [
    new OA\Property(property: 'valid', type: 'boolean'),
    new OA\Property(property: 'problems', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorRuleProblem')),
])])]
#[OA\Schema(schema: 'ConfiguratorEvaluationResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ConfiguratorEvaluation')])]
#[OA\Schema(schema: 'ConfiguratorConfigurationResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ConfiguratorConfiguration')])]
#[OA\Schema(schema: 'ConfiguratorConfigurationCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConfiguratorConfiguration'))])]
final class ConfiguratorOpenApi {}
