<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\CreditCardDTO;
use App\DTOs\Financial\CreditCardPaymentDTO;
use App\DTOs\Financial\CreditCardTransactionDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardPayment;
use App\Models\CreditCardTransaction;
use App\Services\Financial\ConfirmCreditCardStatement;
use App\Services\Financial\CreateCreditCard;
use App\Services\Financial\CreateCreditCardTransaction;
use App\Services\Financial\ParseCreditCardStatementFile;
use App\Services\Financial\PayCreditCardInvoice;
use App\Services\Financial\PreviewCreditCardStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditCardController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $cards = CreditCard::query()
            ->where('wallet_id', $wallet->id)
            ->where('card_type', 'main')
            ->whereNull('parent_card_id')
            ->with([
                'liabilityAccount:id,code,name',
                'bankAccount:id,name,bank_name,bank_code,agency,account_number',
                'childCards:id,parent_card_id,name,card_type,last_four,is_active',
            ])
            ->orderBy('issuer_name')
            ->orderBy('name')
            ->get()
            ->map(function (CreditCard $card) use ($wallet) {
                $currentBalance = $this->currentBalanceCents($wallet->id, $card->liability_account_id);

                return [
                    'id' => $card->id,
                    'name' => $card->name,
                    'issuer_name' => $card->issuer_name,
                    'network' => $card->network,
                    'card_type' => $card->card_type,
                    'holder_name' => $card->holder_name,
                    'last_four' => $card->last_four,
                    'closing_day' => $card->closing_day,
                    'due_day' => $card->due_day,
                    'best_purchase_day' => $card->best_purchase_day,
                    'credit_limit_cents' => $card->credit_limit_cents,
                    'current_balance_cents' => $currentBalance,
                    'available_limit_cents' => $card->credit_limit_cents - $currentBalance,
                    'is_active' => $card->is_active,
                    'liability_account' => $card->liabilityAccount,
                    'bank_account' => $card->bankAccount,
                    'child_cards' => $card->childCards,
                ];
            })
            ->values();

        return Inertia::render('Financial/CreditCards/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'suspense_account_id' => $wallet->suspense_account_id,
            ],
            'cards' => $cards,
        ]);
    }

    public function create(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);
        $selectedBankAccountId = $request->query('bank_account_id');

        if ($selectedBankAccountId) {
            BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->findOrFail($selectedBankAccountId);
        }

        return Inertia::render('Financial/CreditCards/Create', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'suspense_account_id' => $wallet->suspense_account_id,
            ],
            'parentCards' => $this->parentCards($wallet->id),
            'bankAccounts' => $this->bankAccounts($wallet->id),
            'selectedBankAccountId' => $selectedBankAccountId ? (int) $selectedBankAccountId : null,
        ]);
    }

    public function store(Request $request, CreateCreditCard $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'issuer_name' => ['required', 'string', 'max:255'],
            'bank_account_id' => [
                'nullable',
                'nullable',
                'integer',
                Rule::exists('bank_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('is_active', true),
            ],
            'network' => ['required', Rule::in(['visa', 'mastercard', 'elo', 'amex', 'hipercard', 'other'])],
            'card_type' => ['required', Rule::in(['main', 'additional', 'virtual'])],
            'parent_card_id' => ['nullable', 'required_unless:card_type,main', 'integer', Rule::exists('credit_cards', 'id')->where('wallet_id', $wallet->id)->where('card_type', 'main')],
            'holder_name' => ['nullable', 'string', 'max:255'],
            'last_four' => ['nullable', 'string', 'size:4'],
            'closing_day' => ['required', 'integer', 'min:1', 'max:31'],
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'best_purchase_day' => ['required', 'integer', 'min:1', 'max:31'],
            'credit_limit_cents' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $creditCard = $service->execute($wallet, CreditCardDTO::fromArray($data));
        $showCard = $creditCard->parentCard ?: $creditCard;

        return redirect()
            ->route('credit-cards.show', $showCard)
            ->with('success', 'Cartão de crédito cadastrado com sucesso.');
    }

    public function show(Request $request, CreditCard $creditCard): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($creditCard->wallet_id === $wallet->id, 404);

        if ($creditCard->parent_card_id) {
            return redirect()->route('credit-cards.show', $creditCard->parent_card_id);
        }

        $creditCard->load([
            'liabilityAccount',
            'bankAccount',
            'childCards' => fn ($query) => $query->orderBy('card_type')->orderBy('name'),
        ]);

        $familyCardIds = $this->familyCardIds($creditCard);

        $invoices = CreditCardInvoice::query()
            ->where('wallet_id', $wallet->id)
            ->where('credit_card_id', $creditCard->id)
            ->withCount(['transactions', 'payments'])
            ->orderByDesc('reference_year')
            ->orderByDesc('reference_month')
            ->limit(12)
            ->get();

        $transactions = CreditCardTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('credit_card_id', $familyCardIds)
            ->with([
                'creditCard:id,name,card_type,last_four',
                'creditCardInvoice:id,reference_month,reference_year,status,due_at',
                'expenseAccount:id,code,name',
                'journalEntry:id,status',
            ])
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $payments = CreditCardPayment::query()
            ->where('wallet_id', $wallet->id)
            ->where('credit_card_id', $creditCard->id)
            ->with([
                'creditCardInvoice:id,reference_month,reference_year,status',
                'bankAccount:id,name,bank_name',
                'journalEntry:id,status',
            ])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $currentBalance = $this->currentBalanceCents($wallet->id, $creditCard->liability_account_id);

        return Inertia::render('Financial/CreditCards/Show', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'suspense_account_id' => $wallet->suspense_account_id,
            ],
            'creditCard' => $creditCard,
            'familyCards' => $this->familyCards($creditCard),
            'summaryByCard' => $this->summaryByCard($wallet->id, $familyCardIds),
            'summary' => [
                'current_balance_cents' => $currentBalance,
                'available_limit_cents' => $creditCard->credit_limit_cents - $currentBalance,
            ],
            'invoices' => $invoices,
            'transactions' => $transactions,
            'payments' => $payments,
            'expenseAccounts' => $this->expenseAccounts($wallet->id),
            'bankAccounts' => $this->bankAccounts($wallet->id),
            'creditCardStatementPreview' => $request->session()->get('credit_card_statement_preview'),
        ]);
    }

    public function previewStatement(Request $request, CreditCard $creditCard, PreviewCreditCardStatement $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $creditCard->wallet_id === (int) $wallet->id, 404);
        $request->validate(['statement_file' => ['required', 'file', 'max:10240', 'extensions:ofx,csv,pdf']]);
        $file = $request->file('statement_file');

        try {
            $preview = $service->execute($wallet, $creditCard, (string) $file->get(), $file->getClientOriginalName());
        } catch (\Throwable $exception) {
            return back()->withErrors(['statement_file' => $exception->getMessage()]);
        }

        $token = Str::random(64);
        $preview['token'] = $token;
        Cache::put('credit-card-statement:'.$token, [
            'user_id' => $request->user()->id,
            'wallet_id' => $wallet->id,
            'credit_card_id' => $creditCard->id,
            'contents' => (string) $file->get(),
            'filename' => $file->getClientOriginalName(),
            'preview' => $preview,
        ], now()->addMinutes(30));

        return back()->with('credit_card_statement_preview', $preview);
    }

    public function confirmStatement(Request $request, CreditCard $creditCard, ConfirmCreditCardStatement $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $creditCard->wallet_id === (int) $wallet->id, 404);
        $data = $request->validate([
            'preview_token' => ['required', 'string', 'size:64'],
            'rows' => ['required', 'array'],
            'rows.*.row_key' => ['required', 'string', 'size:64'],
            'rows.*.action' => ['required', Rule::in(['create', 'ignore'])],
        ]);
        $key = 'credit-card-statement:'.$data['preview_token'];
        $context = Cache::get($key);
        if (! is_array($context) || (int) ($context['user_id'] ?? 0) !== (int) $request->user()->id
            || (int) ($context['wallet_id'] ?? 0) !== (int) $wallet->id
            || (int) ($context['credit_card_id'] ?? 0) !== (int) $creditCard->id) {
            return back()->withErrors(['statement_import' => 'A pré-visualização expirou. Selecione o arquivo novamente.']);
        }

        try {
            $result = $service->execute($wallet, $creditCard, $context['preview'], $context['contents'], $context['filename'], $data['rows']);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['statement_import' => $exception->getMessage()])
                ->with('credit_card_statement_preview', $context['preview']);
        }
        Cache::forget($key);

        return back()->with('success', "{$result['created']} compras importadas; {$result['ignored']} linhas ignoradas.");
    }

    public function previewSetupFile(Request $request, ParseCreditCardStatementFile $parser): JsonResponse
    {
        $this->resolveActiveWallet($request);
        $request->validate(['statement_file' => ['required', 'file', 'max:10240', 'extensions:ofx,csv,pdf']]);
        $file = $request->file('statement_file');

        try {
            $parsed = $parser->parse((string) $file->get(), $file->getClientOriginalName());
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'institution' => $parsed['institution'] ?? null,
            'last_four' => $parsed['last_four'] ?? null,
            'holder_name' => $parsed['holder_name'] ?? null,
            'due_day' => isset($parsed['due_date']) ? (int) substr($parsed['due_date'], -2) : null,
            'warning' => empty($parsed['institution']) && empty($parsed['last_four'])
                ? 'O arquivo não possui metadados suficientes. Continue o preenchimento manual.'
                : null,
        ]);
    }

    public function storeTransaction(Request $request, CreditCard $creditCard, CreateCreditCardTransaction $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($creditCard->wallet_id === $wallet->id, 404);

        if ($creditCard->parent_card_id) {
            return redirect()->route('credit-cards.show', $creditCard->parent_card_id);
        }

        $familyCardIds = $this->familyCardIds($creditCard);

        $data = $request->validate([
            'credit_card_id' => ['required', 'integer', Rule::in($familyCardIds)],
            'expense_account_id' => [
                'required',
                'integer',
                Rule::exists('chart_of_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->whereIn('type', ['despesa', 'ativo'])
                    ->whereDoesntHave('children')
                    ->where('allows_posting', true),
            ],
            'purchase_date' => ['required', 'date'],
            'merchant_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'installments_total' => ['required', 'integer', 'min:1', 'max:60'],
            'installment_number' => ['required', 'integer', 'min:1', 'lte:installments_total'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $service->execute($wallet, CreditCardTransactionDTO::fromArray($data));

        return redirect()
            ->route('credit-cards.show', $creditCard)
            ->with('success', 'Compra no cartão registrada com sucesso.');
    }

    public function classifyTransaction(Request $request, CreditCard $creditCard, CreditCardTransaction $transaction): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $creditCard->wallet_id === (int) $wallet->id && (int) $transaction->wallet_id === (int) $wallet->id, 404);
        $data = $request->validate([
            'chart_of_account_id' => [
                'required', 'integer',
                Rule::exists('chart_of_accounts', 'id')->where('wallet_id', $wallet->id)->whereIn('type', ['despesa', 'ativo'])->where('allows_posting', true),
            ],
        ]);
        $account = ChartOfAccount::query()->where('wallet_id', $wallet->id)->whereKey($data['chart_of_account_id'])
            ->whereDoesntHave('children')
            ->whereNotIn('id', fn ($query) => $query->select('chart_of_account_id')->from('bank_accounts'))
            ->firstOrFail();
        abort_unless($transaction->journalEntry?->status === 'draft', 422);
        $transaction->journalEntry->lines()->where('chart_of_account_id', $wallet->suspense_account_id)
            ->where('type', 'debit')->update(['chart_of_account_id' => $account->id, 'memo' => 'Classificação da compra no cartão']);
        $transaction->update(['expense_account_id' => $account->id]);

        return back()->with('success', 'Compra classificada e pronta para contabilidade.');
    }

    public function payInvoice(Request $request, CreditCard $creditCard, PayCreditCardInvoice $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($creditCard->wallet_id === $wallet->id, 404);

        if ($creditCard->parent_card_id) {
            return redirect()->route('credit-cards.show', $creditCard->parent_card_id);
        }

        $data = $request->validate([
            'credit_card_invoice_id' => [
                'required',
                'integer',
                Rule::exists('credit_card_invoices', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('credit_card_id', $creditCard->id),
            ],
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('is_active', true),
            ],
            'payment_date' => ['required', 'date'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['credit_card_id'] = $creditCard->id;

        $service->execute($wallet, CreditCardPaymentDTO::fromArray($data));

        return redirect()
            ->route('credit-cards.show', $creditCard)
            ->with('success', 'Pagamento da fatura registrado com sucesso.');
    }

    private function currentBalanceCents(int $walletId, int $liabilityAccountId): int
    {
        $transactions = CreditCardTransaction::query()
            ->where('wallet_id', $walletId)
            ->whereHas('creditCard', fn ($query) => $query->where('liability_account_id', $liabilityAccountId))
            ->whereIn('status', ['draft', 'posted'])
            ->sum('amount_cents');

        $payments = CreditCardPayment::query()
            ->where('wallet_id', $walletId)
            ->whereHas('creditCard', fn ($query) => $query->where('liability_account_id', $liabilityAccountId))
            ->whereIn('status', ['draft', 'posted'])
            ->sum('amount_cents');

        return (int) $transactions - (int) $payments;
    }

    private function familyCardIds(CreditCard $creditCard): array
    {
        return collect([$creditCard->id])
            ->merge($creditCard->childCards()->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function familyCards(CreditCard $creditCard): array
    {
        return collect([$creditCard])
            ->merge($creditCard->childCards)
            ->map(fn (CreditCard $card) => [
                'id' => $card->id,
                'name' => $card->name,
                'card_type' => $card->card_type,
                'last_four' => $card->last_four,
                'is_active' => $card->is_active,
            ])
            ->values()
            ->all();
    }

    private function summaryByCard(int $walletId, array $cardIds): array
    {
        return CreditCard::query()
            ->where('wallet_id', $walletId)
            ->whereIn('id', $cardIds)
            ->get(['id', 'name', 'card_type', 'last_four'])
            ->map(function (CreditCard $card) use ($walletId) {
                $amount = CreditCardTransaction::query()
                    ->where('wallet_id', $walletId)
                    ->where('credit_card_id', $card->id)
                    ->whereIn('status', ['draft', 'posted'])
                    ->sum('amount_cents');

                return [
                    'id' => $card->id,
                    'name' => $card->name,
                    'card_type' => $card->card_type,
                    'last_four' => $card->last_four,
                    'amount_cents' => (int) $amount,
                ];
            })
            ->values()
            ->all();
    }

    private function parentCards(int $walletId): array
    {
        return CreditCard::query()
            ->where('wallet_id', $walletId)
            ->where('card_type', 'main')
            ->whereNull('parent_card_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'issuer_name'])
            ->map(fn (CreditCard $card) => [
                'id' => $card->id,
                'label' => "{$card->name} ({$card->issuer_name})",
            ])
            ->values()
            ->all();
    }

    private function expenseAccounts(int $walletId): array
    {
        return ChartOfAccount::query()
            ->where('wallet_id', $walletId)
            ->whereIn('type', ['despesa', 'ativo'])
            ->whereDoesntHave('children')
            ->where('allows_posting', true)
            ->whereNotIn('id', fn ($query) => $query->select('chart_of_account_id')->from('bank_accounts'))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'label' => "{$account->code} - {$account->name}",
            ])
            ->values()
            ->all();
    }

    private function bankAccounts(int $walletId): array
    {
        return BankAccount::query()
            ->where('wallet_id', $walletId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'bank_name', 'bank_code', 'agency', 'account_number'])
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'label' => $this->formatBankAccountLabel($account),
            ])
            ->values()
            ->all();
    }

    private function formatBankAccountLabel(BankAccount $account): string
    {
        $details = collect([
            $account->bank_code,
            $account->agency,
            $account->account_number,
        ])->filter()->join(' / ');

        return $details !== ''
            ? "{$account->name} ({$details})"
            : $account->name;
    }
}
