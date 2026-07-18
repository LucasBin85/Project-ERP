<?php

return [
    'pdf_ocr' => [
        'enabled' => (bool) env('STATEMENT_PDF_OCR_ENABLED', false),
        'pdftoppm_path' => env('STATEMENT_PDF_PDFTOPPM_PATH', 'pdftoppm'),
        'tesseract_path' => env('STATEMENT_PDF_TESSERACT_PATH', 'tesseract'),
        'language' => env('STATEMENT_PDF_OCR_LANGUAGE', 'por+eng'),
        'dpi' => (int) env('STATEMENT_PDF_OCR_DPI', 300),
    ],
];
