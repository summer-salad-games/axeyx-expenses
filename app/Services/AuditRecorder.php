<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActorKind;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final class AuditRecorder
{
    public function record(string $action, Model $subject, array $metadata = [], ?User $actor = null): AuditLog
    {
        $metadata = Arr::only($metadata, ['role', 'from_role', 'to_role']);
        if ($actor === null && function_exists('posix_geteuid')) {
            $metadata['operator_uid'] = posix_geteuid();
        }

        $audit = new AuditLog;
        $audit->forceFill([
            'actor_id' => $actor?->getKey(),
            'actor_kind' => $actor === null ? AuditActorKind::Operator : AuditActorKind::User,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'metadata' => $metadata,
            'created_at' => now(),
        ])->save();

        return $audit;
    }
}
