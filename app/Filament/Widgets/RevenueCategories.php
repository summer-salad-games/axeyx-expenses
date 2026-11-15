<?php

namespace App\Filament\Widgets;

use App\Enums\TransactionType;

class RevenueCategories extends TransactionChart
{
    protected string $group = 'revenue_categories';

    protected ?TransactionType $direction = TransactionType::Revenue;

    protected ?string $heading = 'Recettes par catégorie';
}
