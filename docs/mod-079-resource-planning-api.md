# Mod 079 — Resource Planning & Capacity Saturation (API contract)

Base path: `/api/v1/resource-planning`. Middleware: `auth:sanctum`, `tenant`, `locale`, `module.access:resource_planning`
(feature flag `resource_planning`, roles owner/admin/hr/project_manager/production write; read adds `member`, `accountant`).
Success envelope `{success, message, data, meta}`. Errors are RFC 7807 `application/problem+json`; domain violations answer
`422` with `invalid_params: [{code, params}]` (codes below). Dates are `Y-m-d`; hours are decimals (2 places); percentages
are plain numbers (`87.5` = 87.5 %).

Enums: `booking_type` = `SoftTentative` | `HardCommitted`. `band` = `none` | `green` | `yellow` | `red`.
Saturation bands: `none` (no net capacity and nothing booked), `green` < 80 %, `yellow` 80–100 %, `red` > 100 % (overload).
A load with no net capacity but bookings is capped at `999`.

## Skills (SkillTaxonomy)
- `GET /skills?category=` -> `[Skill]`; `POST /skills`; `PUT /skills/{id}`; `DELETE /skills/{id}`.
- `Skill`: `{id, category, skill_name, requires_certification, certification_code|null}`. `certification_code` is the Mod 099 certificate type
  (HSE `certificate_type` or HR certification `code`) that must be valid for the skill to count when `requires_certification`.
- Body: `{category, skill_name, requires_certification?, certification_code?}` (code required when `requires_certification`).

## Employee skill profiles (EmployeeSkillProfile)
- `GET /employee-skill-profiles?employee_id=&skill_id=` -> `[Profile]`; `POST` (upsert on employee+skill); `PUT /{id}`; `DELETE /{id}`.
- `Profile`: `{id, employee_id, employee_name, skill_id, skill_name, category, proficiency_level (1..5), valid_until_date|null, is_valid, requires_certification, certification_code|null, certification_status: not_required|valid|missing|expired, certification_valid_until|null}`.
- Body: `{employee_id, skill_id, proficiency_level, valid_until_date?}`.

## Resources (lookup)
- `GET /resources?department_id=` -> `[{employee_id, full_name, department_id, department_name, cost_center_id, cost_center_name, weekly_contract_hours, fte_ratio, hourly_cost, country, region|null}]` (employees with an active contract).

## Allocations (ResourceAllocation)
- `GET /allocations?from=&to=&employee_id=&project_id=&booking_type=` -> `[Allocation]` (overlapping the window).
- `Allocation`: `{id, project_id|null, project_name|null, wbs_node_id|null, wbs_node_title|null, employee_id, employee_name, role_id|null, booking_type, start_date, end_date, allocated_hours_per_day, total_allocated_hours, is_billable, crm_opportunity_id|null, crm_opportunity_name|null, is_overloaded, peak_load_percent, notes|null}`.
- `POST /allocations` body `{project_id?, wbs_node_id?, employee_id, role_id?, booking_type, start_date, end_date, allocated_hours_per_day, is_billable? (default true), crm_opportunity_id?, notes?, allow_overload? (default true)}`.
  `HardCommitted` needs `project_id`; `SoftTentative` needs `project_id` or `crm_opportunity_id` (an open opportunity). `wbs_node_id` belongs to the project.
- Mutation result (`201`/`200`): `{allocation: Allocation, allocations: [Allocation], overload: {is_overloaded, peak_load_percent, weeks: [{week_start, load_percent}]}, warnings: [{code, params}]}`.
- `POST /allocations/{id}/convert-to-hard` body `{project_id?, wbs_node_id?}` (project required when the soft booking only has an opportunity).
- `POST /allocations/{id}/rebalance` body `{strategy: shift|reassign|split|resize, employee_id?, start_date?, end_date?, split_date?, shift_days?, allocated_hours_per_day?}`:
  `shift` moves the allocation (`start_date` or `shift_days`, duration kept; optional `employee_id` = drag to a colleague), `reassign` changes `employee_id`,
  `split` cuts at `split_date` (second part starts there; optional `employee_id` / `shift_days` for the second part), `resize` changes `end_date`/`start_date`/`allocated_hours_per_day`.
  `allocations` in the result holds every affected row (two for a split).
- `DELETE /allocations/{id}` -> `204`.
- `POST /allocations` also accepts `required_skill_ids?: uuid[]`: skills the booking needs; a lapsed or missing skill/certificate comes back as a warning.
- Error codes (422 `invalid_params`): `employee_not_found`, `project_not_allocatable`, `wbs_node_not_in_project`, `opportunity_not_open`, `hard_booking_requires_project`,
  `soft_booking_requires_reference`, `overload` (when `allow_overload=false`), `already_hard_committed`, `invalid_period`, `no_working_days`, `certification_code_required`;
  404: `allocation_not_found`, `skill_not_found`, `skill_profile_not_found`, `local_holiday_not_found`.
- Warning codes (in `warnings`): `skill_missing`, `proficiency_too_low`, `skill_expired`, `certificate_missing`, `certificate_expired` (params carry `skill_id`, `skill_name`).

## Capacity heatmap
`GET /capacity/heatmap?from=&to=&granularity=week|day&department_id=&employee_id[]=` -> `{from, to, granularity, thresholds:{yellow:80, red:100}, periods:[Y-m-d], rows:[Row]}`;
`Row`: `{employee_id, employee_name, department_id|null, department_name|null, cost_center_id|null, weekly_contract_hours, cells:[Cell]}`;
`Cell`: `{period_start, contractual_hours, leave_and_holiday_hours, net_available_hours, hard_booked_hours, soft_booked_hours, billable_booked_hours, internal_booked_hours, load_percent, band, is_overloaded}`.
`load_percent = (hard + soft) / net * 100`. Weeks start on Monday.

## Candidate matching
`POST /candidates/search` body `{start_date, end_date, allocated_hours_per_day, requirements:[{skill_id, min_level}], project_id?, wbs_node_id?, department_id?, require_valid_certificates? (default true), include_ineligible? (default false), limit? (default 10, max 50)}`
-> `[{employee_id, employee_name, department_name|null, score (0..100), eligible, matched_skills:[{skill_id, skill_name, level, min_level, valid_until_date|null, certification_status}], blockers:[{code, skill_id|null, params}], available_hours, requested_hours, residual_hours_per_day, peak_load_after_percent, hourly_cost}]`.
Blocker codes: `skill_missing`, `proficiency_too_low`, `skill_expired`, `certificate_missing`, `certificate_expired`, `insufficient_availability`.

## Billable utilization
`GET /reports/billable-utilization?from=&to=&group_by=resource|department|cost_center` ->
`{from, to, group_by, default_target_percent, rows:[Row], totals: Row}`;
`Row`: `{key_id|null, key_name, net_available_hours, billable_committed_hours, billable_booked_hours, internal_booked_hours, tentative_booked_hours, actual_billable_percent, forecast_billable_percent, target_percent, gap_percent, status: on_target|below_target|above_target}`.
`actual` = committed (hard) billable / net; `forecast` = all billable (hard + soft) / net; `gap = actual - target`; status is `on_target` within ±5 points.
- `GET /utilization-targets` -> `[{id, department_id|null, cost_center_id|null, target_percent}]`; `PUT /utilization-targets` body `{department_id?, cost_center_id?, target_percent}` (upsert; both null = tenant default).

## Snapshots & holidays
- `POST /capacity/snapshots/recalculate` body `{from, to, employee_id?}` -> `{weeks, snapshots}`.
- `GET /capacity/snapshots?from=&to=&employee_id=` -> `[{id, employee_id, week_start_date, contractual_hours, leave_and_holiday_hours, net_available_hours, billable_booked_hours, internal_booked_hours, utilization_rate_percent, department_id, cost_center_id}]`.
- `GET /holidays?country=CH|IT&region=&municipality=&year=` -> `[{date, name, day_fraction}]` (national + cantonal/regional + municipal patron saint + tenant local holidays).
- `GET /local-holidays`, `POST /local-holidays` `{country, region_code?, municipality?, month, day, year?, name, day_fraction?}`, `DELETE /local-holidays/{id}`.

## Translation keys (API-served frontend dictionaries)
Prefix `resourcePlanning.` (it, de, fr, en seeders in `ResourcePlanningFrontendTranslations`).
