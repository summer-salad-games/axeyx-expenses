<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Rules\TndAmountRule;
use App\Support\TndAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ExpenseService
{
    public function create(User $actor, array $data): Expense
    {
        return app(AttachmentService::class)->save($actor, null, $data['new_attachments'] ?? [], fn () => $this->save($actor, $data));
    }

    public function update(User $actor, Expense $expense, array $data): Expense
    {
        return app(AttachmentService::class)->save($actor, $expense, $data['new_attachments'] ?? [], fn () => $this->save($actor, $data, $expense));
    }

    private function save(User $actor, array $data, ?Expense $expense = null): Expense
    {
        return DB::transaction(function () use ($actor, $data, $expense): Expense {
            $record = $expense ? Expense::query()->findOrFail($expense->getKey()) : new Expense;
            Gate::forUser($actor)->authorize($expense ? 'update' : 'create', $expense ? $record : Expense::class);
            $values = Validator::make($data, [
                'expense_date' => ['required', 'date_format:Y-m-d'],
                'amount' => ['required', 'string', new TndAmountRule],
                'category_id' => ['required', 'integer'],
                'description' => ['nullable', 'string', 'max:5000'],
            ])->validate();

            $category = Category::query()->find($values['category_id']);
            if (! $category || (! $category->is_active && (! $expense || $category->id !== $record->category_id))) {
                throw ValidationException::withMessages(['category_id' => 'Choisissez une catégorie active.']);
            }

            $record->fill([
                'expense_date' => $values['expense_date'],
                'category_id' => $category->id,
                'description' => $values['description'] ?? null,
            ])->forceFill(['amount_millimes' => TndAmount::parse($values['amount'], config('expenses.max_amount_millimes'))]);
            if (! $expense) {
                $record->created_by = $actor->getKey();
            }
            $record->save();
            app(AuditRecorder::class)->record($expense ? 'expense.updated' : 'expense.created', $record, actor: $actor);

            return $record;
        }, 3);
    }

    public function delete(User $actor, Expense $expense): bool
    {
        return app(AttachmentService::class)->removeExpense($actor, $expense);
    }
}
