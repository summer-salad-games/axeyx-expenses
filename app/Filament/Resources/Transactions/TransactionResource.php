<?php

namespace App\Filament\Resources\Transactions;

use App\Enums\TransactionType;
use App\Filament\Resources\Transactions\Pages\CreateTransaction;
use App\Filament\Resources\Transactions\Pages\EditTransaction;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\Pages\ViewTransaction;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\TndAmountRule;
use App\Services\TransactionService;
use App\Support\TndAmount;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'transaction';

    protected static ?string $pluralModelLabel = 'Transactions';

    protected static ?string $slug = 'transactions';

    protected static ?int $navigationSort = 10;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label('Type')->options(TransactionType::options())->searchable()->native(false)->required()
                ->default('expense')->live()->afterStateUpdated(fn (Set $set) => $set('category_id', null)),
            TextInput::make('amount')->label('Montant')->inputMode('decimal')->suffix('TND')->required()
                ->prefix(fn (Get $get) => self::selectedType($get('type'))?->sign())
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
                ->options(fn (?Transaction $record, Get $get) => Category::query()->where('type', self::selectedType($get('type'))?->value)
                    ->where(function (Builder $query) use ($record): void {
                        $query->where('is_active', true);
                        if ($record) {
                            $query->orWhereKey($record->category_id);
                        }
                    })->orderBy('name')->get()->mapWithKeys(fn (Category $category) => [
                        $category->id => $category->name.($category->is_active ? '' : ' (désactivée)'),
                    ])->all())
                ->helperText(fn (Get $get) => Category::query()->where('type', self::selectedType($get('type'))?->value)->where('is_active', true)->exists()
                        ? null : 'Aucune catégorie active. Contactez un administrateur.'),
            DatePicker::make('transaction_date')->label('Date de la transaction')->native()->required()
                ->default(fn () => now(config('transactions.reporting_timezone'))->toDateString()),
            Textarea::make('description')->label('Description')->maxLength(5000)->rows(4)->columnSpanFull(),
            FileUpload::make('new_attachments')->label('Ajouter des pièces jointes')->multiple()->storeFiles(false)
                ->disk('local')->visibility('private')->maxParallelUploads(1)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->maxSize(fn () => intdiv(config('transactions.attachments.file_bytes'), 1024))
                ->maxFiles(fn () => config('transactions.attachments.count'))->previewable(false)->columnSpanFull()
                ->helperText(fn () => 'JPEG, PNG, WebP ou PDF. '.config('transactions.attachments.count').' fichiers maximum, '
                        .intdiv(config('transactions.attachments.file_bytes'), 1048576).' Mio par fichier, '
                        .intdiv(config('transactions.attachments.total_bytes'), 1048576).' Mio au total. Images : 16 mégapixels maximum. Les fichiers existants sont conservés.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('type')->label('Type')->formatStateUsing(fn (TransactionType $state) => $state->label())->badge()->color(fn (TransactionType $state) => $state->color()),
            TextEntry::make('transaction_date')->label('Date de la transaction')->date('d/m/Y'),
            TextEntry::make('amount_millimes')->label('Montant')->formatStateUsing(fn (int $state, Transaction $record) => $record->type->sign().TndAmount::format($state)),
            TextEntry::make('category.name')->label('Catégorie'),
            TextEntry::make('creator.name')->label('Créée par'),
            TextEntry::make('description')->label('Description')->placeholder('Aucune description'),
            TextEntry::make('created_at')->label('Créée le')->dateTime('d/m/Y H:i', timezone: config('transactions.reporting_timezone')),
            TextEntry::make('updated_at')->label('Modifiée le')->dateTime('d/m/Y H:i', timezone: config('transactions.reporting_timezone')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('type')->label('Type')->formatStateUsing(fn (TransactionType $state) => $state->label())->badge()->color(fn (TransactionType $state) => $state->color()),
            TextColumn::make('transaction_date')->label('Date')->date('d/m/Y')->sortable(),
            TextColumn::make('amount_millimes')->label('Montant')->formatStateUsing(fn (int $state, Transaction $record) => $record->type->sign().TndAmount::format($state))
                ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("CASE WHEN type = 'expense' THEN -amount_millimes ELSE amount_millimes END ".($direction === 'desc' ? 'DESC' : 'ASC'))),
            TextColumn::make('category.name')->label('Catégorie')->searchable()->sortable()->wrap(),
            TextColumn::make('creator.name')->label('Créée par')->searchable()->sortable(),
            TextColumn::make('description')->label('Description')->searchable()->limit(20)->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('type')->label('Type')->options(TransactionType::options())->searchable()->native(false),
            SelectFilter::make('category_id')->label('Catégorie')->searchable()->preload()->native(false)
                ->options(fn () => Category::query()->orderBy('name')->get()->mapWithKeys(fn (Category $category) => [$category->id => $category->name.' — '.$category->type->label()])),
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

                return $query->when($values['from'] ?? null, fn (Builder $query, string $date) => $query->where('transaction_date', '>=', $date))
                    ->when($values['until'] ?? null, fn (Builder $query, string $date) => $query->where('transaction_date', '<=', $date));
            })->indicateUsing(function (array $data): array {
                $values = self::periodData($data);

                return array_filter([
                    empty($values['from']) ? null : 'Du '.$values['from'],
                    empty($values['until']) ? null : 'Au '.$values['until'],
                ]);
            }),
        ])->recordActions([ViewAction::make(), EditAction::make()->action(null), self::deleteAction()])
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('transaction_date')->orderByDesc('id'))
            ->stackedOnMobile()->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading('Aucune transaction')->emptyStateDescription('Les dépenses et recettes enregistrées apparaîtront ici.');
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
        return DeleteAction::make()->authorize(fn (Transaction $record) => self::canDelete($record))
            ->modalHeading('Supprimer définitivement cette transaction ?')
            ->modalDescription('Cette action est irréversible. La transaction et toutes ses pièces jointes seront supprimées définitivement.')
            ->using(fn (Transaction $record): bool => app(TransactionService::class)->delete(Filament::auth()->user(), $record));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['category', 'creator']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
            'create' => CreateTransaction::route('/create'),
            'view' => ViewTransaction::route('/{record}'),
            'edit' => EditTransaction::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [RelationManagers\AttachmentsRelationManager::class];
    }

    private static function selectedType(mixed $value): ?TransactionType
    {
        return $value instanceof TransactionType ? $value : (is_string($value) ? TransactionType::tryFrom($value) : null);
    }
}
