<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Normalizer;

#[Fillable(['name'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $category->name = self::trimName($category->name);
            $category->name_key = self::nameKey($category->name);
        });
    }

    public static function trimName(string $name): string
    {
        return preg_replace('/\A\s+|\s+\z/u', '', $name);
    }

    public static function nameKey(string $name): string
    {
        return Normalizer::normalize(mb_convert_case(self::trimName($name), MB_CASE_FOLD, 'UTF-8'), Normalizer::FORM_C);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
