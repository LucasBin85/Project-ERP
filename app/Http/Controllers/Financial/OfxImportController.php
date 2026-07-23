<?php

namespace App\Http\Controllers\Financial;

use App\Exceptions\OfxImportException;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use App\Services\Financial\ConfirmOfxBankStatement;
use App\Services\Financial\PreviewOfxBankStatement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class OfxImportController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);
        $selectedBankAccountId = $request->query('bank_account_id');

        if ($selectedBankAccountId) {
            BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->findOrFail($selectedBankAccountId);
        }

        $imports = BankStatementImport::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('source', ['ofx', 'csv', 'pdf'])
            ->when($selectedBankAccountId, fn ($query) => $query->where('bank_account_id', $selectedBankAccountId))
            ->with([
                'bankAccount:id,name,bank_name',
                'transactions' => fn ($query) => $query
                    ->with('journalEntry:id,entry_date,description,status')
                    ->orderBy('posted_at')
                    ->orderBy('id'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return Inertia::render('Financial/OfxImports/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'selectedBankAccountId' => $selectedBankAccountId ? (int) $selectedBankAccountId : null,
            'imports' => $imports,
        ]);
    }

    public function preview(Request $request, PreviewOfxBankStatement $service, EnsureAccountingPeriodIsOpen $periodGuard): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('is_active', true),
            ],
            'ofx_file' => ['required', 'file', 'max:10240', 'extensions:ofx,csv,pdf'],
        ]);

        $bankAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->findOrFail($data['bank_account_id']);

        $file = $request->file('ofx_file');
        $contents = (string) $file->get();

        try {
            $preview = $service->execute(
                wallet: $wallet,
                bankAccount: $bankAccount,
                contents: $contents,
                originalFilename: $file->getClientOriginalName(),
            );
            foreach ($preview['rows'] as $row) {
                $periodGuard->handle($wallet, $row['date'] ?? $row['posted_at']);
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors(['ofx_file' => $exception->getMessage()])
                ->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors(['ofx_file' => 'Não foi possível ler o arquivo do extrato. Tente OFX, CSV ou PDF textual.'])
                ->withInput();
        }

        $token = Str::random(64);
        $preview['token'] = $token;

        Cache::put($this->previewCacheKey($token), [
            'user_id' => $request->user()?->id,
            'wallet_id' => $wallet->id,
            'bank_account_id' => $bankAccount->id,
            'contents' => $contents,
            'original_filename' => $file->getClientOriginalName(),
            'file_hash' => $preview['file_hash'],
            'preview' => $preview,
        ], now()->addMinutes(30));

        return redirect()
            ->route('bank-accounts.statement', $bankAccount)
            ->with('ofx_preview', $preview);
    }

    public function confirm(
        Request $request,
        ConfirmOfxBankStatement $service,
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'preview_token' => ['required', 'string', 'size:64'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.row_key' => ['required', 'string', 'size:64', 'distinct'],
            'rows.*.action' => ['required', Rule::in(['create', 'link', 'ignore'])],
        ]);

        $cacheKey = $this->previewCacheKey($data['preview_token']);
        $processingKey = $cacheKey.':processing';
        $context = Cache::get($cacheKey);

        if (! is_array($context)
            || (int) ($context['user_id'] ?? 0) !== (int) $request->user()?->id
            || (int) ($context['wallet_id'] ?? 0) !== (int) $wallet->id) {
            return back()->withErrors([
                'preview_token' => 'A pré-visualização expirou. Selecione o arquivo do extrato novamente.',
            ]);
        }

        if (! Cache::add($processingKey, true, now()->addMinutes(5))) {
            return back()
                ->withErrors(['preview_token' => 'Esta pré-visualização já está sendo processada.'])
                ->with('ofx_preview', $context['preview']);
        }

        $bankAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->find($context['bank_account_id']);

        if (! $bankAccount) {
            Cache::forget($processingKey);

            return back()->withErrors([
                'preview_token' => 'A conta bancária desta prévia não está mais disponível.',
            ]);
        }

        try {
            $result = $service->execute(
                wallet: $wallet,
                bankAccount: $bankAccount,
                contents: $context['contents'],
                originalFilename: $context['original_filename'],
                expectedFileHash: $context['file_hash'],
                decisions: $data['rows'],
                expectedRows: $context['preview']['rows'],
            );
        } catch (OfxImportException $exception) {
            Cache::forget($processingKey);

            return redirect()
                ->route('bank-accounts.statement', $bankAccount)
                ->withErrors(['ofx_import' => $exception->getMessage()])
                ->with('ofx_preview', $context['preview']);
        } catch (Throwable $exception) {
            Cache::forget($processingKey);
            report($exception);

            return redirect()
                ->route('bank-accounts.statement', $bankAccount)
                ->withErrors(['ofx_import' => 'Não foi possível concluir a importação do extrato. Nenhum lançamento foi criado.'])
                ->with('ofx_preview', $context['preview']);
        }

        Cache::forget($cacheKey);

        return redirect()
            ->route('bank-accounts.statement', $bankAccount)
            ->with('success', $result->message());
    }

    private function previewCacheKey(string $token): string
    {
        return 'ofx-import-preview:'.$token;
    }
}
