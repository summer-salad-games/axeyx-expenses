<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class ReportingPeriod
{
    public const PRESETS = [
        'today' => 'Aujourd’hui', 'week' => 'Cette semaine', 'month' => 'Ce mois-ci',
        'last_month' => 'Mois dernier', 'year' => 'Cette année', 'custom' => 'Personnalisée',
    ];

    private function __construct(public string $preset, public string $from, public string $until) {}

    public static function resolve(?array $filters = null): self
    {
        $filters = $filters ?: ['preset' => 'month'];
        $values = Validator::make($filters, [
            'preset' => ['required', 'string', Rule::in(array_keys(self::PRESETS))],
            'from' => ['exclude_unless:preset,custom', 'required', 'string', 'date_format:Y-m-d'],
            'until' => ['exclude_unless:preset,custom', 'required', 'string', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        $today = CarbonImmutable::now(config('transactions.reporting_timezone'))->startOfDay();
        [$from, $until] = match ($values['preset']) {
            'today' => [$today, $today],
            'week' => [$today->startOfWeek(CarbonImmutable::MONDAY), $today->endOfWeek(CarbonImmutable::SUNDAY)],
            'month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'year' => [$today->startOfYear(), $today->endOfYear()],
            'custom' => [CarbonImmutable::createFromFormat('!Y-m-d', $values['from']), CarbonImmutable::createFromFormat('!Y-m-d', $values['until'])],
        };
        $months = ($until->year - $from->year) * 12 + $until->month - $from->month + 1;
        if ($months > config('transactions.statistics.max_months')) {
            throw ValidationException::withMessages(['until' => 'Choisissez une période de '.config('transactions.statistics.max_months').' mois maximum.']);
        }

        return new self($values['preset'], $from->toDateString(), $until->toDateString());
    }

    public function filters(): array
    {
        return $this->preset === 'custom'
            ? ['preset' => $this->preset, 'from' => $this->from, 'until' => $this->until]
            : ['preset' => $this->preset];
    }

    public function granularity(): string
    {
        // Inclusive calendar dates: 31 days or fewer use daily buckets.
        return CarbonImmutable::parse($this->from)->diff(CarbonImmutable::parse($this->until))->days < 31 ? 'day' : 'month';
    }

    public function label(): string
    {
        return self::PRESETS[$this->preset].' · Du '.CarbonImmutable::parse($this->from)->format('d/m/Y').' au '.CarbonImmutable::parse($this->until)->format('d/m/Y');
    }
}
