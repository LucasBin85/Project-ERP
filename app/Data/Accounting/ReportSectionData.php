<?php

namespace App\Data\Accounting;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

class ReportSectionData implements Arrayable
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly int $totalCents,
        public readonly Collection $rows,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'total_cents' => $this->totalCents,
            'rows' => $this->rows->values(),
        ];
    }
}