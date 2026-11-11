<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditActorKind;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'actor_kind' => AuditActorKind::User,
            'action' => 'fixture.created',
            'subject_type' => 'fixture',
            'subject_id' => '1',
            'metadata' => [],
        ];
    }
}
