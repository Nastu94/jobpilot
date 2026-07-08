<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use Illuminate\Support\Collection;

class ProfileApplicationHealthReportBuilder
{
    public const INTEGRITY_STATUSES = [
        'healthy',
        'warning',
        'invalid',
    ];

    private const INTEGRITY_PRIORITY = [
        'invalid' => 0,
        'warning' => 1,
        'healthy' => 2,
    ];

    public function __construct(
        private readonly JobApplicationIntegrityAuditor $auditor,
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
    ) {
    }

    public function build(
        Profile $profile,
        Collection $applications,
        array $integrityStatuses,
        array $applicationStatuses,
        int $limit,
    ): array {
        $items = $applications
            ->map(fn (JobApplication $application): array => $this->item(
                $application,
                $this->auditor->audit($application),
            ))
            ->values();

        $matching = $items
            ->filter(fn (array $item): bool => in_array(
                $item['integrity_status'],
                $integrityStatuses,
                true,
            ) && in_array(
                $item['application_status'],
                $applicationStatuses,
                true,
            ))
            ->sort(fn (array $left, array $right): int => $this->compareItems(
                $left,
                $right,
            ))
            ->values();
        $returned = $matching->take($limit)->values();
        $byIntegrityStatus = array_fill_keys(self::INTEGRITY_STATUSES, 0);
        $byApplicationStatus = array_fill_keys(
            $this->statusWorkflow->statuses(),
            0,
        );
        $errorsTotal = 0;
        $warningsTotal = 0;

        foreach ($items as $item) {
            $byIntegrityStatus[$item['integrity_status']]++;

            if (! array_key_exists($item['application_status'], $byApplicationStatus)) {
                $byApplicationStatus[$item['application_status']] = 0;
            }

            $byApplicationStatus[$item['application_status']]++;
            $errorsTotal += $item['summary']['errors'];
            $warningsTotal += $item['summary']['warnings'];
        }

        $unknownStatuses = array_diff(
            array_keys($byApplicationStatus),
            $this->statusWorkflow->statuses(),
        );
        sort($unknownStatuses);
        $orderedByApplicationStatus = [];

        foreach ($this->statusWorkflow->statuses() as $status) {
            $orderedByApplicationStatus[$status] = $byApplicationStatus[$status];
        }

        foreach ($unknownStatuses as $status) {
            $orderedByApplicationStatus[$status] = $byApplicationStatus[$status];
        }

        return [
            'profile_id' => $profile->getKey(),
            'filters' => [
                'integrity_statuses' => array_values($integrityStatuses),
                'application_statuses' => array_values($applicationStatuses),
                'limit' => $limit,
            ],
            'summary' => [
                'applications_total' => $items->count(),
                'attention_total' => $byIntegrityStatus['warning']
                    + $byIntegrityStatus['invalid'],
                'healthy_total' => $byIntegrityStatus['healthy'],
                'warning_total' => $byIntegrityStatus['warning'],
                'invalid_total' => $byIntegrityStatus['invalid'],
                'errors_total' => $errorsTotal,
                'warnings_total' => $warningsTotal,
                'issues_total' => $errorsTotal + $warningsTotal,
                'matching_total' => $matching->count(),
                'returned_total' => $returned->count(),
                'by_integrity_status' => $byIntegrityStatus,
                'by_application_status' => $orderedByApplicationStatus,
            ],
            'issues_by_code' => $this->issuesByCode($items),
            'applications' => $returned->all(),
        ];
    }

    private function item(
        JobApplication $application,
        array $audit,
    ): array {
        return [
            'application_id' => $application->getKey(),
            'application_status' => $application->status,
            'job_title' => $application->job_title,
            'company_name' => $application->company_name,
            'applied_at' => $application->applied_at?->toISOString(),
            'next_action_at' => $application->next_action_at?->toISOString(),
            'updated_at' => $application->updated_at?->toISOString(),
            'integrity_status' => $audit['integrity_status'],
            'healthy' => $audit['healthy'],
            'summary' => $audit['summary'],
            'issue_codes' => array_values(array_unique(array_column(
                $audit['issues'],
                'code',
            ))),
            'issues' => $audit['issues'],
        ];
    }

    private function compareItems(array $left, array $right): int
    {
        $comparison = self::INTEGRITY_PRIORITY[$left['integrity_status']]
            <=> self::INTEGRITY_PRIORITY[$right['integrity_status']];

        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = $right['summary']['errors']
            <=> $left['summary']['errors'];

        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = $right['summary']['warnings']
            <=> $left['summary']['warnings'];

        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp(
            (string) $right['updated_at'],
            (string) $left['updated_at'],
        );

        return $comparison !== 0
            ? $comparison
            : $right['application_id'] <=> $left['application_id'];
    }

    private function issuesByCode(Collection $items): array
    {
        $byCode = [];

        foreach ($items as $item) {
            foreach ($item['issues'] as $issue) {
                $code = $issue['code'];

                if (! isset($byCode[$code])) {
                    $byCode[$code] = [
                        'code' => $code,
                        'severity' => $issue['severity'],
                        'total' => 0,
                        'application_ids' => [],
                    ];
                }

                $byCode[$code]['total']++;
                $byCode[$code]['application_ids'][] = $item['application_id'];
            }
        }

        foreach ($byCode as &$entry) {
            $entry['application_ids'] = array_values(array_unique(
                $entry['application_ids'],
            ));
            sort($entry['application_ids']);
        }
        unset($entry);

        usort($byCode, function (array $left, array $right): int {
            $comparison = $right['total'] <=> $left['total'];

            return $comparison !== 0
                ? $comparison
                : strcmp($left['code'], $right['code']);
        });

        return array_values($byCode);
    }
}
