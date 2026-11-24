# Area 09 — CRM, Marketing and After-Sales / Ticketing

Epic: FatturaFacile/FatturaFacile#192. Sub-issues: #193 (CRM/customer 360), #194 (leads), #195 (helpdesk/SLA, includes #43), #196 (field service), #197 (contracts/installed base), #202 (CMMS).

## Backend map

| Capability | Module |
| --- | --- |
| Leads, pipeline, customer 360, lifecycle | `modules/02_CRM` |
| Helpdesk, SLA, CSAT, service contracts, service reports | `modules/17_ServiceManagement` |
| Asset hierarchy, meter/time maintenance plans, work orders, MTBF/MTTR | `modules/16_AssetManagement` |
| Recurring maintenance billing | `modules/19_SubscriptionBilling` |

## Roadmap

- **MVP**: lead capture and dedup, pipeline, ticket lifecycle with SLA timers, service contracts, work orders, digital service report with signature.
- **v1.1**: customer 360 timeline, ticket-to-field-service escalation, complete-and-bill, preventive plans (time and meter), CSAT surveys.
- **Advanced**: IoT/MES meter ingestion, spare-part auto-reorder, reliability analytics (MTBF/MTTR per asset class), campaign ROI attribution.

## Success KPIs

| KPI | Definition | Source |
| --- | --- | --- |
| Sales conversion rate | converted leads / qualified leads | CRM |
| Pipeline value | sum of open deal value x probability | CRM |
| Campaign ROI | (attributed won revenue - spend) / spend | Marketing |
| First response time | ticket created to first public reply | Helpdesk |
| SLA compliance % | tickets within response and resolution targets | Helpdesk |
| CSAT | mean score of answered surveys | Helpdesk |
| Contract MRR / renewal rate | recurring maintenance billing, renewed / due | Contracts |
| MTTR | mean hours from start to completion of corrective work orders (`mean_time_to_repair_hours`) | Assets |
| MTBF | mean days between completed corrective work orders (`mean_days_between_failures`) | Assets |
| First-time fix rate | field visits resolved without a follow-up visit | Field service |
