<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionType: string
{
    case Expense = 'expense';
    case Revenue = 'revenue';

    public function label(): string
    {
        return $this === self::Expense ? 'Dépense' : 'Recette';
    }

    public function sign(): string
    {
        return $this === self::Expense ? '−' : '+';
    }

    public function color(): string
    {
        return $this === self::Expense ? 'danger' : 'success';
    }

    public static function options(): array
    {
        return ['expense' => self::Expense->label(), 'revenue' => self::Revenue->label()];
    }
}
