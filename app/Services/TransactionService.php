<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\TndAmountRule;
use App\Support\TndAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TransactionService
{
    public function create(User $actor, array $data): Transaction
    {
        return app(AttachmentService::class)->save($actor, null, $data['new_attachments'] ?? [], fn () => $this->save($actor, $data));
    }

    public function update(User $actor, Transaction $transaction, array $data): Transaction
    {
        return app(AttachmentService::class)->save($actor, $transaction, $data['new_attachments'] ?? [], fn () => $this->save($actor, $data, $transaction));
    }

    private function save(User $actor, array $data, ?Transaction $transaction = null): Transaction
    {
        return DB::transaction(function () use ($actor, $data, $transaction): Transaction {
            $record = $transaction ? Transaction::query()->findOrFail($transaction->getKey()) : new Transaction;
            Gate::forUser($actor)->authorize($transaction ? 'update' : 'create', $transaction ? $record : Transaction::class);
            $values = Validator::make($data, [
                'transaction_date' => ['required', 'date_format:Y-m-d'],
                'type' => ['required', Rule::enum(TransactionType::class)],
                'amount' => ['required', 'string', new TndAmountRule],
                'category_id' => ['required', 'integer'],
                'description' => ['nullable', 'string', 'max:5000'],
            ])->validate();

            $category = Category::query()->find($values['category_id']);
            $type = TransactionType::from($values['type']);
            if (! $category || $category->type !== $type || (! $category->is_active && (! $transaction || $category->id !== $record->category_id))) {
                throw ValidationException::withMessages(['category_id' => 'Choisissez une catégorie active correspondant au type de transaction.']);
            }

            $record->fill([
                'transaction_date' => $values['transaction_date'],
                'category_id' => $category->id,
                'description' => $values['description'] ?? null,
            ])->forceFill(['type' => $type, 'amount_millimes' => TndAmount::parse($values['amount'], config('transactions.max_amount_millimes'))]);
            if (! $transaction) {
                $record->created_by = $actor->getKey();
            }
            $record->save();
            app(AuditRecorder::class)->record($transaction ? 'transaction.updated' : 'transaction.created', $record, actor: $actor);

            return $record;
        }, 3);
    }

    public function delete(User $actor, Transaction $transaction): bool
    {
        return app(AttachmentService::class)->removeTransaction($actor, $transaction);
    }
}
