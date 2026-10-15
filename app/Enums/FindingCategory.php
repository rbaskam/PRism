<?php

namespace App\Enums;

enum FindingCategory: string
{
    case Bug = 'bug';
    case Security = 'security';
    case Duplication = 'duplication';
    case Complexity = 'complexity';
    case Tests = 'tests';
    case Performance = 'performance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::Security => 'Security',
            self::Duplication => 'Duplication',
            self::Complexity => 'Complexity',
            self::Tests => 'Missing tests',
            self::Performance => 'Performance',
            self::Other => 'Other',
        };
    }
}
