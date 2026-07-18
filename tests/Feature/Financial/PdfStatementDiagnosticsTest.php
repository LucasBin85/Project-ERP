<?php

use App\Services\Financial\LocalPdfOcr;
use App\Services\Financial\ParsePdfStatement;
use Mockery\MockInterface;

it('reports that image PDFs need optional OCR without creating a preview', function () {
    config()->set('statements.pdf_ocr.enabled', false);
    expect(fn () => app(ParsePdfStatement::class)->parse("%PDF-1.4\n/Subtype /Image\nstream\nimage\nendstream\n%%EOF"))
        ->toThrow(\RuntimeException::class, 'Este PDF parece ser baseado em imagem.');
});

it('uses optional local OCR text through the same Mercado Pago parser', function () {
    $ocrText = file_get_contents(base_path('tests/Fixtures/mercado_pago_ocr.txt'));
    $this->mock(LocalPdfOcr::class, function (MockInterface $mock) use ($ocrText) {
        $mock->shouldReceive('enabled')->once()->andReturnTrue();
        $mock->shouldReceive('extract')->once()->andReturn($ocrText);
    });
    $result = app(ParsePdfStatement::class)->parse("%PDF-1.4\n/Subtype /Image\n%%EOF");
    expect($result['transactions'])->toHaveCount(3);
});

it('returns a friendly message when enabled OCR tools are unavailable', function () {
    $this->mock(LocalPdfOcr::class, function (MockInterface $mock) {
        $mock->shouldReceive('enabled')->once()->andReturnTrue();
        $mock->shouldReceive('extract')->once()->andThrow(new \RuntimeException('OCR local não está disponível neste ambiente. Instale as dependências ou use outro formato.'));
    });
    expect(fn () => app(ParsePdfStatement::class)->parse("%PDF-1.4\n/Subtype /Image\n%%EOF"))
        ->toThrow(\RuntimeException::class, 'OCR local não está disponível neste ambiente.');
});

it('sanitizes sensitive identifiers in PDF diagnostic samples', function () {
    $sample = app(ParsePdfStatement::class)->sanitizeSample('CPF 123.456.789-00 Agência 1234 Conta 98765-4');
    expect($sample)->not->toContain('123.456.789-00')->not->toContain('1234')->not->toContain('98765-4');
});

it('runs the PDF diagnostic command in testing and refuses it in production', function () {
    $path = storage_path('framework/testing-debug.pdf');
    file_put_contents($path, "%PDF-1.4\n/Subtype /Image\n/Encrypt 9 0 R\n%%EOF");
    try {
        $this->artisan('statement:debug-pdf', ['path' => $path])->assertSuccessful();
        app()->detectEnvironment(fn () => 'production');
        $this->artisan('statement:debug-pdf', ['path' => $path])
            ->expectsOutput('Este comando só pode ser executado em ambiente local ou testing.')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        @unlink($path);
    }
});
