<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditActorKind: string
{
    case User = 'user';
    case Operator = 'operator';
    case System = 'system';
}
