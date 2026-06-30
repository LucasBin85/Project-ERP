<?php

namespace App\Services\Accounting\Reports;

use Illuminate\Support\Collection;
use App\Data\Accounting\ReportSectionData;

class ReportTreeBuilder
{
    public function build(
        Collection $rows,
        string $type,
        string $title,
        string $amountKey = 'balance_cents'
    ): ReportSectionData {
        $sectionRows = $rows
            ->where('type', $type)
            ->values();

        $childrenByParent = $sectionRows->groupBy('parent_id');

        $rootRows = $sectionRows
            ->filter(fn ($row) => blank($row['parent_id']) || ! $sectionRows->contains('account_id', $row['parent_id']))
            ->sortBy('code')
            ->values();

        $flattened = [];

        foreach ($rootRows as $row) {
            $this->appendRow($row, $childrenByParent, $flattened, $amountKey);
        }

        $filtered = collect($flattened)
            ->filter(fn ($row) => $row[$amountKey] !== 0)
            ->values();

        return new ReportSectionData(
            key: $type,
            title: $title,
            totalCents: $filtered
                ->where('level', 0)
                ->sum($amountKey),
            rows: $filtered,
        );
    }

    private function appendRow(
        array $row,
        Collection $childrenByParent,
        array &$flattened,
        string $amountKey,
        int $level = 0
    ): int {
        $children = $childrenByParent
            ->get($row['account_id'], collect())
            ->sortBy('code')
            ->values();

        $currentIndex = count($flattened);

        $flattened[] = [
            ...$row,
            'level' => $level,
            $amountKey => $row[$amountKey],
            'is_summary' => $children->isNotEmpty(),
        ];

        $childrenTotal = 0;

        foreach ($children as $child) {
            $childrenTotal += $this->appendRow($child, $childrenByParent, $flattened, $amountKey, $level + 1);
        }

        $total = $row[$amountKey] + $childrenTotal;

        $flattened[$currentIndex][$amountKey] = $total;

        return $total;
    }
}