<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Rules\TndAmountRule;
use App\Services\ExpenseService;
use App\Support\TndAmount;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'dépense';

    protected static ?string $pluralModelLabel = 'Dépenses';

    protected static ?string $slug = 'expenses';

    protected static ?int $navigationSort = 10;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('expense_date')->label('Date de la dépense')->native()->required()
                ->default(fn () => now(config('expenses.reporting_timezone'))->toDateString()),
            TextInput::make('amount')->label('Montant')->inputMode('decimal')->suffix('TND')->required()
                ->default('')->placeholder('0,000')
                ->extraAlpineAttributes([
                    'x-on:focus' => '$el.select()',
                    'x-on:beforeinput' => <<<'JS'
                        if (!$event.inputType.startsWith('insert') || $event.data === null) return;
                        const start = $el.selectionStart;
                        const end = $el.selectionEnd;
                        const next = $el.value.slice(0, start) + $event.data + $el.value.slice(end);
                        if (next === '.' || next === ',') {
                            $event.preventDefault();
                            $el.setRangeText('0' + next, start, end, 'end');
                            $el.dispatchEvent(new Event('input', { bubbles: true }));
                        } else if (!/^(?:[0-9]+(?:[,.][0-9]{0,3})?)?(?![\s\S])/.test(next)) {
                            $event.preventDefault();
                        }
                        JS,
                    'x-on:paste' => <<<'JS'
                        const text = $event.clipboardData.getData('text');
                        const next = $el.value.slice(0, $el.selectionStart) + text + $el.value.slice($el.selectionEnd);
                        if (!/^(?:[0-9]+(?:[,.][0-9]{0,3})?)?(?![\s\S])/.test(next)) $event.preventDefault();
                        JS,
                    'x-on:blur' => <<<'JS'
                        const parts = $el.value.match(/^([0-9]+)(?:[,.]([0-9]{0,3}))?(?![\s\S])/);
                        if (parts) {
                            $el.value = parts[1] + ',' + (parts[2] ?? '').padEnd(3, '0');
                            $el.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                        JS,
                ])
                ->rules([new TndAmountRule])->helperText('Virgule ou point, jusqu’à trois décimales.'),
            Select::make('category_id')->label('Catégorie')->required()->searchable()->native(false)
                ->options(fn (?Expense $record) => Category::query()->where(function (Builder $query) use ($record): void {
                    $query->where('is_active', true);
                    if ($record) {
                        $query->orWhereKey($record->category_id);
                    }
                })->orderBy('name')->get()->mapWithKeys(fn (Category $category) => [
                    $category->id => $category->name.($category->is_active ? '' : ' (désactivée)'),
                ])->all())
                ->helperText(fn () => Category::query()->where('is_active', true)->exists()
                    ? null : 'Aucune catégorie active. Contactez un administrateur.'),
            Textarea::make('description')->label('Description')->maxLength(5000)->rows(4)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('expense_date')->label('Date de la dépense')->date('d/m/Y'),
            TextEntry::make('amount_millimes')->label('Montant')->formatStateUsing(fn (int $state) => TndAmount::format($state)),
            TextEntry::make('category.name')->label('Catégorie'),
            TextEntry::make('creator.name')->label('Créée par'),
            TextEntry::make('description')->label('Description')->placeholder('Aucune description')->columnSpanFull(),
            TextEntry::make('created_at')->label('Créée le')->dateTime('d/m/Y H:i', timezone: config('expenses.reporting_timezone')),
            TextEntry::make('updated_at')->label('Modifiée le')->dateTime('d/m/Y H:i', timezone: config('expenses.reporting_timezone')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('expense_date')->label('Date')->date('d/m/Y')->sortable(),
            TextColumn::make('amount_millimes')->label('Montant')->formatStateUsing(fn (int $state) => TndAmount::format($state))->sortable(),
            TextColumn::make('category.name')->label('Catégorie')->searchable()->sortable()->wrap(),
            TextColumn::make('creator.name')->label('Créée par')->searchable()->sortable(),
            TextColumn::make('description')->label('Description')->searchable()->limit(20)->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('category_id')->label('Catégorie')->searchable()->preload()->native(false)
                ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id')),
            SelectFilter::make('created_by')->label('Créée par')->searchable()->preload()->native(false)
                ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')),
            Filter::make('period')->label('Période')->schema([
                DatePicker::make('from')->label('Du')->native(),
                DatePicker::make('until')->label('Au')->native()->afterOrEqual('from'),
            ])->query(function (Builder $query, array $data): Builder {
                $values = self::periodData($data);
                if ($values === null) {
                    // Invalid crafted/applied state must never broaden a query or fail during rendering.
                    return $query->whereRaw('0 = 1');
                }

                return $query->when($values['from'] ?? null, fn (Builder $query, string $date) => $query->where('expense_date', '>=', $date))
                    ->when($values['until'] ?? null, fn (Builder $query, string $date) => $query->where('expense_date', '<=', $date));
            })->indicateUsing(function (array $data): array {
                $values = self::periodData($data);

                return array_filter([
                    empty($values['from']) ? null : 'Du '.$values['from'],
                    empty($values['until']) ? null : 'Au '.$values['until'],
                ]);
            }),
        ])->recordActions([ViewAction::make(), EditAction::make()->action(null), self::deleteAction()])
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('expense_date')->orderByDesc('id'))
            ->stackedOnMobile()->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading('Aucune dépense')->emptyStateDescription('Les dépenses enregistrées apparaîtront ici.');
    }

    private static function periodData(array $data): ?array
    {
        $validator = Validator::make($data, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', ...(! empty($data['from']) && is_string($data['from']) ? ['after_or_equal:from'] : [])],
        ]);

        return $validator->fails() ? null : $validator->validated();
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()->authorize(fn (Expense $record) => self::canDelete($record))
            ->modalDescription('Cette dépense sera retirée du registre. Ses pièces jointes seront conservées.')
            ->using(fn (Expense $record): bool => app(ExpenseService::class)->delete(Filament::auth()->user(), $record));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['category', 'creator']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
