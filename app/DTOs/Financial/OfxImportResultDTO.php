<?php

namespace App\DTOs\Financial;

use App\Models\BankStatementImport;

final readonly class OfxImportResultDTO
{
    public function __construct(
        public BankStatementImport $import,
        public int $created,
        public int $linked,
        public int $duplicates,
        public int $ignored,
    ) {}

    public function message(): string
    {
        $message = sprintf(
            'OFX importado: %d novos, %d vinculados, %d duplicados ignorados.',
            $this->created,
            $this->linked,
            $this->duplicates,
        );

        if ($this->ignored > 0) {
            $message .= sprintf(' %d linhas não importadas.', $this->ignored);
        }

        return $message;
    }
}
