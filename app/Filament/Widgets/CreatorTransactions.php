<?php

namespace App\Filament\Widgets;

class CreatorTransactions extends TransactionChart
{
    protected int|string|array $columnSpan = 'full';

    protected string $group = 'creators';

    protected ?string $heading = 'Transactions par personne';
}
