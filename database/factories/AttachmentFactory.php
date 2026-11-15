<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Metadata fixture only; tests needing actual bytes must create the private file.
 *
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'path' => 'attachments/'.Str::uuid().'.pdf',
            'original_name' => 'justificatif.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 13,
            'sha256' => hash('sha256', 'fixture-bytes'),
            'uploaded_by' => User::factory(),
        ];
    }
}
