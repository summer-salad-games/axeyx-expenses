<?php

namespace App\Filament\Widgets;

use App\Enums\TransactionType;

class CategoryTransactions extends TransactionChart
{
    protected string $group = 'expense_categories';

    protected ?TransactionType $direction = TransactionType::Expense;

    protected ?string $heading = 'Dépenses par catégorie';
}
