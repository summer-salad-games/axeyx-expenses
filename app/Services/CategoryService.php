<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Expense;
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

    public function delete(User $actor, Category $category): bool
    {
        return DB::transaction(function () use ($actor, $category): bool {
            $record = Category::query()->findOrFail($category->getKey());
            Gate::forUser($actor)->authorize('update', $record);
            if (Expense::query()->where('category_id', $record->getKey())->exists()) {
                throw ValidationException::withMessages(['name' => 'Cette catégorie est utilisée par des dépenses. Désactivez-la au lieu de la supprimer.']);
            }
            Gate::forUser($actor)->authorize('delete', $record);
            app(AuditRecorder::class)->record('category.deleted', $record, actor: $actor);
            $record->delete();

            return true;
        }, 3);
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
