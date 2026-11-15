<?php

namespace App\Filament\Widgets;

use App\Enums\TransactionType;
use App\Filament\Widgets\Concerns\HasTransactionStatistics;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

abstract class TransactionChart extends ChartWidget
{
    use HasTransactionStatistics;

    protected string $group;

    protected ?TransactionType $direction = null;

    protected ?string $maxHeight = '300px';

    protected ?string $description = 'Montants en TND. Touchez ou survolez le graphique pour afficher les valeurs.';

    protected function getType(): string
    {
        return $this->group === 'timeline' ? 'line' : 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->statistics[$this->group] ?? [];

        $datasets = [];
        foreach ($this->direction ? [$this->direction] : [TransactionType::Revenue, TransactionType::Expense] as $type) {
            $prefix = $type->value;
            $color = $type === TransactionType::Revenue ? '#16804a' : '#d23a4f';
            $datasets[] = [
                'label' => $type->label(),
                'data' => array_column($rows, $this->direction ? 'millimes' : $prefix.'_millimes'),
                'exactLabels' => array_column($rows, $this->direction ? 'formatted' : $prefix.'_formatted'),
                'fullLabels' => array_column($rows, 'label'),
                'counts' => array_column($rows, $this->direction ? 'count' : $prefix.'_count'),
                'borderColor' => $color, 'backgroundColor' => $color, 'borderWidth' => 2,
            ];
        }

        return ['datasets' => $datasets, 'labels' => array_map(fn ($row) => Str::limit($row['axis_label'] ?? $row['label'], 25), $rows)];
    }

    protected function getOptions(): RawJs
    {
        $horizontal = $this->group !== 'timeline' ? 'true' : 'false';

        $legend = $this->direction === null ? 'true' : 'false';

        return RawJs::make(<<<JS
            (() => {
                const horizontal = {$horizontal};
                const format = value => {
                    if (!Number.isSafeInteger(value) || value < 0) return '';
                    const millimes = BigInt(value);
                    return (millimes / 1000n).toLocaleString('fr-FR') + ',' + (millimes % 1000n).toString().padStart(3, '0') + ' TND';
                };
                return {
                    indexAxis: horizontal ? 'y' : 'x',
                    animation: { duration: 0 },
                    plugins: {
                        legend: { display: {$legend} },
                        tooltip: { callbacks: {
                            title: items => items.length ? items[0].dataset.fullLabels[items[0].dataIndex] : '',
                            label: item => item.dataset.exactLabels[item.dataIndex],
                            afterLabel: item => item.dataset.counts[item.dataIndex] + ' transaction(s)',
                        } },
                    },
                    scales: { [horizontal ? 'x' : 'y']: { beginAtZero: true, ticks: { callback: format } } },
                };
            })()
            JS);
    }

    #[Unexposed]
    public function isEmpty(): bool
    {
        return empty($this->statistics['count']) || empty($this->statistics[$this->group]);
    }

    #[Unexposed]
    public function getEmptyStateHeading(): string
    {
        return isset($this->statistics['error']) ? 'Statistiques indisponibles' : 'Aucune transaction sur cette période';
    }

    #[Unexposed]
    public function getEmptyStateDescription(): string
    {
        return $this->statistics['error'] ?? 'Choisissez une autre période ou ajoutez une transaction.';
    }

    #[Unexposed]
    public function getChartAssistiveContent(): View
    {
        return view('filament.widgets.transaction-chart-data', ['heading' => $this->getHeading(), 'rows' => $this->statistics[$this->group] ?? [], 'direction' => $this->direction?->label()]);
    }
}
