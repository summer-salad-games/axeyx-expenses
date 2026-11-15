<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ReportingPeriod;
use App\Support\TndAmount;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use OverflowException;

final class TransactionStatistics
{
    private const AGGREGATES = "COALESCE(SUM(CASE WHEN type = 'expense' THEN amount_millimes ELSE 0 END), 0) AS expense_millimes,
        COALESCE(SUM(CASE WHEN type = 'revenue' THEN amount_millimes ELSE 0 END), 0) AS revenue_millimes,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN 1 ELSE 0 END), 0) AS expense_count,
        COALESCE(SUM(CASE WHEN type = 'revenue' THEN 1 ELSE 0 END), 0) AS revenue_count, COUNT(*) AS count";

    public function report(User $actor, ?array $filters = null): array
    {
        Gate::forUser($actor)->authorize('viewAny', Transaction::class);
        $period = ReportingPeriod::resolve($filters);
        try {
            return DB::transaction(function () use ($period): array {
                $query = DB::table('transactions')->whereBetween('transaction_date', [$period->from, $period->until]);
                $total = (clone $query)->selectRaw(self::AGGREGATES)->first();
                $summary = $this->values($total);
                $daily = $period->granularity() === 'day';
                $expression = $daily ? 'transaction_date' : 'substr(transaction_date, 1, 7)';
                $buckets = (clone $query)->selectRaw($expression.' AS bucket, '.self::AGGREGATES)
                    ->groupBy('bucket')->orderBy('bucket')->get()->keyBy('bucket');
                $timeline = [];
                $date = CarbonImmutable::parse($period->from);
                $last = CarbonImmutable::parse($period->until);
                if (! $daily) {
                    $date = $date->startOfMonth();
                    $last = $last->startOfMonth();
                }
                for (; $date <= $last; $date = $daily ? $date->addDay() : $date->addMonth()) {
                    $key = $date->format($daily ? 'Y-m-d' : 'Y-m');
                    $label = $daily ? $date->format('d/m/Y') : $date->locale('fr')->translatedFormat('M Y');
                    $timeline[] = ['id' => $key, 'label' => $label, 'axis_label' => $daily ? $date->format('d/m') : $label]
                        + $this->values($buckets->get($key));
                }
                $categories = (clone $query)->selectRaw('category_id AS id, type, SUM(amount_millimes) AS millimes, COUNT(*) AS count')
                    ->groupBy('category_id', 'type')->orderByDesc('millimes')->orderBy('category_id')->get();
                $creators = (clone $query)->selectRaw('created_by AS id, '.self::AGGREGATES)
                    ->groupBy('created_by')->orderBy('created_by')->get();
                $categoryNames = Category::query()->whereKey($categories->pluck('id'))->pluck('name', 'id');
                $creatorNames = User::query()->whereKey($creators->pluck('id'))->pluck('name', 'id');
                $categoryRows = ['expense_categories' => [], 'revenue_categories' => []];
                foreach ($categories as $row) {
                    $millimes = $this->integer($row->millimes);
                    $categoryRows[$row->type.'_categories'][] = ['id' => $row->id, 'label' => $categoryNames[$row->id],
                        'millimes' => (string) $millimes, 'formatted' => TndAmount::format($millimes), 'count' => $this->integer($row->count)];
                }

                return ['period' => $period->label(), 'from' => $period->from, 'until' => $period->until,
                    'granularity' => $daily ? 'day' : 'month', 'timeline' => $timeline,
                    'creators' => $creators->map(fn ($row) => ['id' => $row->id, 'label' => $creatorNames[$row->id]] + $this->values($row))->all(),
                ] + $summary + $categoryRows;
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'integer overflow')) {
                throw new OverflowException('Un total dépasse la limite de calcul. Choisissez une période plus courte.', previous: $exception);
            }
            throw $exception;
        }
    }

    private function values(?object $row): array
    {
        $expense = $this->integer($row?->expense_millimes ?? 0);
        $revenue = $this->integer($row?->revenue_millimes ?? 0);
        $net = $revenue - $expense;

        return ['expense_millimes' => (string) $expense, 'revenue_millimes' => (string) $revenue, 'net_millimes' => (string) $net,
            'expense_formatted' => TndAmount::format($expense), 'revenue_formatted' => TndAmount::format($revenue), 'net_formatted' => TndAmount::format($net),
            'expense_count' => $this->integer($row?->expense_count ?? 0), 'revenue_count' => $this->integer($row?->revenue_count ?? 0), 'count' => $this->integer($row?->count ?? 0)];
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new OverflowException('Un total dépasse la limite de calcul. Choisissez une période plus courte.');
        }

        return $value;
    }
}
