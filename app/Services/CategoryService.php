<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CategoryService
{
    public function create(User $actor, array $data): Category
    {
        return $this->save($actor, $data);
    }

    public function update(User $actor, Category $category, array $data): Category
    {
        return $this->save($actor, $data, $category);
    }

    private function save(User $actor, array $data, ?Category $category = null): Category
    {
        try {
            return DB::transaction(function () use ($actor, $data, $category): Category {
                $record = $category ? Category::query()->findOrFail($category->getKey()) : new Category;
                Gate::forUser($actor)->authorize($category ? 'update' : 'create', $category ? $record : Category::class);
                if (is_string($data['name'] ?? null)) {
                    $data['name'] = Category::trimName($data['name']);
                }
                $values = Validator::make($data, [
                    'name' => ['required', 'string', 'max:100'],
                    'is_active' => ['sometimes', 'boolean'],
                ])->validate();
                if (Category::query()->where('name_key', Category::nameKey($values['name']))
                    ->when($record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))->exists()) {
                    throw ValidationException::withMessages(['name' => 'Ce nom de catégorie est déjà utilisé.']);
                }
                $wasActive = $record->is_active;
                $record->fill(['name' => $values['name']])->forceFill([
                    'is_active' => $values['is_active'] ?? ($category ? $record->is_active : true),
                ])->save();
                app(AuditRecorder::class)->record($category ? 'category.updated' : 'category.created', $record, actor: $actor);
                if ($category && $wasActive && ! $record->is_active) {
                    app(AuditRecorder::class)->record('category.disabled', $record, actor: $actor);
                }

                return $record;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Ce nom de catégorie est déjà utilisé.']);
        }
    }
}
