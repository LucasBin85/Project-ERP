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
use App\Models\CreditCardPayment;
use App\Models\CreditCardTransaction;
use App\Services\Financial\CreateCreditCard;
use App\Services\Financial\CreateCreditCardTransaction;
use App\Services\Financial\PayCreditCardInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ],
            'cards' => $cards,
        ]);
    }

    public function create(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        return Inertia::render('Financial/CreditCards/Create', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'parentCards' => $this->parentCards($wallet->id),
            'bankAccounts' => $this->bankAccounts($wallet->id),
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
                'required_if:card_type,main',
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

        $transactions = CreditCardTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('credit_card_id', $familyCardIds)
            ->with(['creditCard:id,name,card_type,last_four', 'expenseAccount:id,code,name', 'journalEntry:id,status'])
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $payments = CreditCardPayment::query()
            ->where('wallet_id', $wallet->id)
            ->where('credit_card_id', $creditCard->id)
            ->with(['bankAccount:id,name,bank_name', 'journalEntry:id,status'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $currentBalance = $this->currentBalanceCents($wallet->id, $creditCard->liability_account_id);

        return Inertia::render('Financial/CreditCards/Show', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'creditCard' => $creditCard,
            'familyCards' => $this->familyCards($creditCard),
            'summaryByCard' => $this->summaryByCard($wallet->id, $familyCardIds),
            'summary' => [
                'current_balance_cents' => $currentBalance,
                'available_limit_cents' => $creditCard->credit_limit_cents - $currentBalance,
            ],
            'transactions' => $transactions,
            'payments' => $payments,
            'expenseAccounts' => $this->expenseAccounts($wallet->id),
            'bankAccounts' => $this->bankAccounts($wallet->id),
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
                    ->where('type', 'despesa')
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

    public function payInvoice(Request $request, CreditCard $creditCard, PayCreditCardInvoice $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($creditCard->wallet_id === $wallet->id, 404);

        if ($creditCard->parent_card_id) {
            return redirect()->route('credit-cards.show', $creditCard->parent_card_id);
        }

        $data = $request->validate([
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
            ->where('status', 'posted')
            ->sum('amount_cents');

        $payments = CreditCardPayment::query()
            ->where('wallet_id', $walletId)
            ->whereHas('creditCard', fn ($query) => $query->where('liability_account_id', $liabilityAccountId))
            ->where('status', 'posted')
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
                    ->where('status', 'posted')
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
            ->where('type', 'despesa')
            ->where('allows_posting', true)
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
