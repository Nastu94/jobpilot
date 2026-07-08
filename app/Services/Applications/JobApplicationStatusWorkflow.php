<?php

namespace App\Services\Applications;

class JobApplicationStatusWorkflow
{
    private const TRANSITIONS = [
        'draft' => ['applied', 'withdrawn'],
        'applied' => ['screening', 'assessment', 'interview', 'offer', 'rejected', 'withdrawn'],
        'screening' => ['assessment', 'interview', 'offer', 'rejected', 'withdrawn'],
        'assessment' => ['interview', 'offer', 'rejected', 'withdrawn'],
        'interview' => ['assessment', 'offer', 'rejected', 'withdrawn'],
        'offer' => ['hired', 'rejected', 'withdrawn'],
        'hired' => [],
        'rejected' => [],
        'withdrawn' => [],
    ];

    private const TERMINAL_STATUSES = [
        'hired',
        'rejected',
        'withdrawn',
    ];

    public function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public function terminalStatuses(): array
    {
        return self::TERMINAL_STATUSES;
    }

    public function supports(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS);
    }

    public function allowedTargets(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        return in_array(
            $toStatus,
            $this->allowedTargets($fromStatus),
            true,
        );
    }

    public function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }
}
