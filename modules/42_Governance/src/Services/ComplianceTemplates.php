<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

/**
 * Seeded templates of the compliance calendar: the recurring duties of the Italian organizational model (D.Lgs.
 * 231/2001), the GDPR, the NIS2 directive as implemented in Italy (D.Lgs. 138/2024), the Swiss FADP (nDSG, in force
 * since 1 September 2023) and ISO/IEC 27001. A template becomes an obligation of the company with a due date, an owner
 * and a recurrence; the key makes the seeding idempotent. Titles are stored in English like the other seeded data.
 */
final class ComplianceTemplates
{
    /**
     * @return array<string, list<array{key: string, framework: string, jurisdiction: string, title: string, description: string, legal_reference: string, recurrence: string, reminder_days: int}>>
     */
    public static function byFramework(): array
    {
        $grouped = [];

        foreach (self::all() as $template) {
            $grouped[$template['framework']][] = $template;
        }

        return $grouped;
    }

    /**
     * @param  list<string>  $frameworks
     * @return list<array{key: string, framework: string, jurisdiction: string, title: string, description: string, legal_reference: string, recurrence: string, reminder_days: int}>
     */
    public static function forFrameworks(array $frameworks): array
    {
        return array_values(array_filter(self::all(), static fn (array $template): bool => in_array($template['framework'], $frameworks, true)));
    }

    /**
     * @return list<array{key: string, framework: string, jurisdiction: string, title: string, description: string, legal_reference: string, recurrence: string, reminder_days: int}>
     */
    public static function all(): array
    {
        $rows = [
            ['d_lgs_231', 'IT', '231.supervisory_body_report', 'Supervisory Body (OdV) report to the board', 'The Supervisory Body reports on the functioning and observance of the organizational model.', 'D.Lgs. 231/2001 art. 6 c. 1 lett. b', 'semiannual', 30],
            ['d_lgs_231', 'IT', '231.model_review', 'Review of the organizational model (MOG 231)', 'Check that the model and its protocols still fit the organization, the activities and the legislation, and update it.', 'D.Lgs. 231/2001 art. 6-7', 'annual', 60],
            ['d_lgs_231', 'IT', '231.risk_assessment', 'Update of the 231 risk assessment of sensitive activities', 'Map the activities exposed to the predicate offences and reassess the risk.', 'D.Lgs. 231/2001 art. 6 c. 2 lett. a', 'annual', 60],
            ['d_lgs_231', 'IT', '231.training', 'Training of personnel on the 231 model', 'Train managers and staff on the model, the code of ethics and the disciplinary system.', 'D.Lgs. 231/2001 art. 6-7', 'annual', 45],
            ['d_lgs_231', 'IT', '231.whistleblowing_review', 'Review of the whistleblowing channel', 'Verify the internal reporting channel, the handling times and the protection of the reporting person.', 'D.Lgs. 24/2023; D.Lgs. 231/2001 art. 6 c. 2-bis', 'annual', 30],
            ['gdpr', 'EU', 'gdpr.ropa_review', 'Review of the record of processing activities', 'Update the record of the processing activities of controller and processor.', 'Reg. (EU) 2016/679 art. 30', 'annual', 30],
            ['gdpr', 'EU', 'gdpr.dpia_review', 'Review of the data protection impact assessments', 'Reassess the DPIAs of processing with a high risk for the data subjects.', 'Reg. (EU) 2016/679 art. 35', 'annual', 30],
            ['gdpr', 'EU', 'gdpr.processors_review', 'Audit of data processors and processing agreements', 'Check the agreements and the guarantees of every processor and sub-processor.', 'Reg. (EU) 2016/679 art. 28', 'annual', 45],
            ['gdpr', 'EU', 'gdpr.breach_drill', 'Personal data breach response drill', 'Test the detection, the assessment and the notification within 72 hours to the supervisory authority.', 'Reg. (EU) 2016/679 art. 33-34', 'annual', 30],
            ['gdpr', 'EU', 'gdpr.data_subject_requests', 'Review of the register of data subject requests', 'Check that every request was answered within one month.', 'Reg. (EU) 2016/679 art. 12-22', 'quarterly', 14],
            ['gdpr', 'EU', 'gdpr.retention_review', 'Review of retention periods and erasure', 'Erase or anonymise the data kept beyond the retention period.', 'Reg. (EU) 2016/679 art. 5 c. 1 lett. e', 'semiannual', 30],
            ['nis2', 'EU', 'nis2.risk_measures_review', 'Review of the cybersecurity risk-management measures', 'Reassess the technical, operational and organizational measures.', 'Directive (EU) 2022/2555 art. 21; D.Lgs. 138/2024', 'annual', 60],
            ['nis2', 'EU', 'nis2.incident_process_test', 'Test of the incident notification process', 'Rehearse the early warning within 24 hours, the notification within 72 hours and the final report.', 'Directive (EU) 2022/2555 art. 23; D.Lgs. 138/2024', 'annual', 30],
            ['nis2', 'EU', 'nis2.management_training', 'Cybersecurity training of the management body', 'Train the members of the management body, who approve and oversee the measures.', 'Directive (EU) 2022/2555 art. 20; D.Lgs. 138/2024', 'annual', 45],
            ['nis2', 'EU', 'nis2.supply_chain_review', 'Review of the security of the supply chain', 'Assess the cybersecurity risk of direct suppliers and service providers.', 'Directive (EU) 2022/2555 art. 21 c. 2 lett. d', 'annual', 45],
            ['nis2', 'IT', 'nis2.registry_update', 'Update of the data on the national authority platform', 'Confirm and update the registration data of the entity on the platform of the national cybersecurity agency (ACN).', 'D.Lgs. 138/2024', 'annual', 60],
            ['fadp', 'CH', 'fadp.processing_register_review', 'Review of the register of processing activities', 'Update the register of the processing activities of controller and processor.', 'Swiss FADP (nDSG) art. 12', 'annual', 30],
            ['fadp', 'CH', 'fadp.dpia_review', 'Review of the data protection impact assessments', 'Reassess the processing that may entail a high risk for the personality or the fundamental rights.', 'Swiss FADP (nDSG) art. 22', 'annual', 30],
            ['fadp', 'CH', 'fadp.breach_process_test', 'Test of the data breach notification process', 'Rehearse the notification to the FDPIC as soon as possible and the information of the data subjects.', 'Swiss FADP (nDSG) art. 24', 'annual', 30],
            ['fadp', 'CH', 'fadp.cross_border_review', 'Review of cross-border data transfers', 'Check the adequacy of the destination countries and the safeguards of every transfer abroad.', 'Swiss FADP (nDSG) art. 16-17', 'annual', 30],
            ['fadp', 'CH', 'fadp.privacy_notice_review', 'Review of the privacy notice', 'Keep the information duty on the collection of personal data up to date.', 'Swiss FADP (nDSG) art. 19', 'annual', 30],
            ['iso_27001', 'INT', 'iso27001.internal_audit', 'ISMS internal audit', 'Audit the information security management system against the standard and the own requirements.', 'ISO/IEC 27001:2022 clause 9.2', 'annual', 60],
            ['iso_27001', 'INT', 'iso27001.management_review', 'ISMS management review', 'Top management reviews the suitability, adequacy and effectiveness of the system.', 'ISO/IEC 27001:2022 clause 9.3', 'annual', 45],
            ['iso_27001', 'INT', 'iso27001.soa_review', 'Review of the Statement of Applicability', 'Reconfirm the applicable controls of Annex A and the justification of the excluded ones.', 'ISO/IEC 27001:2022 clause 6.1.3', 'annual', 30],
            ['iso_27001', 'INT', 'iso27001.risk_assessment', 'Information security risk assessment', 'Repeat the risk assessment and update the risk treatment plan.', 'ISO/IEC 27001:2022 clause 6.1.2', 'annual', 60],
            ['iso_27001', 'INT', 'iso27001.access_review', 'Review of user access rights', 'Check that the access rights of employees and suppliers are still needed.', 'ISO/IEC 27001:2022 Annex A 5.18', 'quarterly', 14],
            ['iso_27001', 'INT', 'iso27001.continuity_test', 'Business continuity and disaster recovery test', 'Test the continuity of the ICT services and the restore of the backups.', 'ISO/IEC 27001:2022 Annex A 5.30', 'annual', 45],
        ];

        return array_map(static fn (array $row): array => [
            'framework' => $row[0],
            'jurisdiction' => $row[1],
            'key' => $row[2],
            'title' => $row[3],
            'description' => $row[4],
            'legal_reference' => $row[5],
            'recurrence' => $row[6],
            'reminder_days' => $row[7],
        ], $rows);
    }
}
