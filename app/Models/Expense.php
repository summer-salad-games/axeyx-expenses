<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExpenseFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['expense_date', 'category_id', 'description'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expense_date' => 'immutable_date',
            'amount_millimes' => 'integer',
        ];
    }

    protected function expenseDate(): Attribute
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
