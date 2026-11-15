<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CategoryTransactions;
use App\Filament\Widgets\CreatorTransactions;
use App\Filament\Widgets\RevenueCategories;
use App\Filament\Widgets\TransactionOverview;
use App\Filament\Widgets\TransactionTimeline;
use App\Models\User;
use App\Security\AccountAccess;
use App\Services\TransactionStatistics;
use App\Support\ReportingPeriod;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use OverflowException;

final class Dashboard extends BaseDashboard
{
    use HasFiltersAction;

    #[Unexposed]
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AccountAccess::class)->allows($user);
    }

    #[Unexposed]
    public function getWidgets(): array
    {
        return [TransactionOverview::class, TransactionTimeline::class, CategoryTransactions::class, RevenueCategories::class, CreatorTransactions::class];
    }

    #[Computed]
    #[Unexposed]
    public function report(): array
    {
        try {
            return app(TransactionStatistics::class)->report(Filament::auth()->user(), $this->filters);
        } catch (ValidationException) {
            return ['error' => 'Période invalide. Choisissez une période valide.'];
        } catch (OverflowException $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    #[Unexposed]
    public function getWidgetData(): array
    {
        return ['statistics' => $this->report];
    }

    #[Unexposed]
    public function getSubheading(): string
    {
        return $this->report['error'] ?? $this->report['period'];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('period')->label('Période')->icon('heroicon-o-calendar-days')->extraAttributes(['class' => 'statistics-period-action'])->modalHeading('Choisir une période')
            ->modalSubmitActionLabel('Appliquer')->schema([
                Select::make('preset')->label('Période')->options(ReportingPeriod::PRESETS)->native(false)->searchable()->required()->live(),
                DatePicker::make('from')->label('Du')->native()->required()->visible(fn (Get $get) => $get('preset') === 'custom'),
                DatePicker::make('until')->label('Au')->native()->required()->afterOrEqual('from')
                    ->helperText(fn () => 'Période maximale : '.config('transactions.statistics.max_months').' mois.')
                    ->visible(fn (Get $get) => $get('preset') === 'custom')
                    ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        try {
                            ReportingPeriod::resolve(['preset' => 'custom', 'from' => $get('from'), 'until' => $value]);
                        } catch (ValidationException $exception) {
                            $fail(collect($exception->errors())->flatten()->first());
                        }
                    }]),
            ])->fillForm(function (): array {
                try {
                    $period = ReportingPeriod::resolve($this->filters);
                } catch (ValidationException) {
                    $period = ReportingPeriod::resolve();
                }

                return ['preset' => $period->preset, 'from' => $period->from, 'until' => $period->until];
            })->action(function (array $data): void {
                $this->filters = ReportingPeriod::resolve($data)->filters();
                $this->updatedFilters();
                unset($this->report);
            })];
    }
}
