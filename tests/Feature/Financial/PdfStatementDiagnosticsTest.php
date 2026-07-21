<?php

use App\Services\Financial\LocalPdfOcr;
use App\Services\Financial\ParsePdfStatement;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

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

it('reports the specific layout message when OCR reads useful unrecognized text', function () {
    $this->mock(LocalPdfOcr::class, function (MockInterface $mock) {
        $mock->shouldReceive('enabled')->once()->andReturnTrue();
        $mock->shouldReceive('extract')->once()->andReturn('EXTRATO DE CONTA sem movimentos reconhecíveis');
    });
    expect(fn () => app(ParsePdfStatement::class)->parse("%PDF-1.4\n/Subtype /Image\n%%EOF"))
        ->toThrow(\RuntimeException::class, 'O OCR leu o PDF, mas o layout ainda não foi reconhecido.');
});

it('keeps useful tesseract stdout even when stderr and exit status report a warning', function () {
    config()->set('statements.pdf_ocr.pdftoppm_path', PHP_BINARY);
    config()->set('statements.pdf_ocr.tesseract_path', PHP_BINARY);
    config()->set('statements.pdf_ocr.language', 'eng');

    $convert = mockOcrProcess(true, '', '', 'pdftoppm command');
    $tesseract = mockOcrProcess(false, 'Pix recebido R$ 4,85', 'warning from tesseract', 'tesseract command');
    $ocr = testOcrWithProcesses([$convert, $tesseract]);
    $result = $ocr->extractWithDiagnostics("%PDF-1.4\n%%EOF");

    expect($result['text'])->toBe('Pix recebido R$ 4,85')
        ->and($result['errors'])->toContain('warning from tesseract');
});

it('falls back to eng when por from the configured language is unavailable', function () {
    config()->set('statements.pdf_ocr.pdftoppm_path', PHP_BINARY);
    config()->set('statements.pdf_ocr.tesseract_path', PHP_BINARY);
    config()->set('statements.pdf_ocr.language', 'por+eng');

    $convert = mockOcrProcess(true, '', '', 'pdftoppm command');
    $portuguese = mockOcrProcess(false, '', "Failed loading language 'por'", 'tesseract por+eng');
    $english = mockOcrProcess(true, 'Pix received R$ 4,85', '', 'tesseract eng');
    $ocr = testOcrWithProcesses([$convert, $portuguese, $english]);
    $result = $ocr->extractWithDiagnostics("%PDF-1.4\n%%EOF");

    expect($result['text'])->toBe('Pix received R$ 4,85')
        ->and($result['languages'])->toBe(['eng'])
        ->and($result['errors'][0])->toContain("Failed loading language 'por'");
});

function mockOcrProcess(bool $successful, string $stdout, string $stderr, string $command): Process
{
    $process = \Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->andReturn(0);
    $process->shouldReceive('isSuccessful')->andReturn($successful);
    $process->shouldReceive('getOutput')->andReturn($stdout);
    $process->shouldReceive('getErrorOutput')->andReturn($stderr);
    $process->shouldReceive('getCommandLine')->andReturn($command);
    return $process;
}

function testOcrWithProcesses(array $processes): LocalPdfOcr
{
    return new class($processes) extends LocalPdfOcr {
        public function __construct(private array $processes) {}
        protected function process(array $command): Process { return array_shift($this->processes); }
        protected function generatedImages(string $prefix): array { return ['page-1.png']; }
    };
}

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
