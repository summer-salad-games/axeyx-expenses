<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionType;
use Database\Factories\TransactionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['transaction_date', 'category_id', 'description'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'transaction_date' => 'immutable_date',
            'type' => TransactionType::class,
            'amount_millimes' => 'integer',
        ];
    }

    protected function transactionDate(): Attribute
    {
        return Attribute::make(
            set: fn (DateTimeInterface|string $value): string => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : $value,
        );
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
