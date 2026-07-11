<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankTransferDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Services\Financial\CreateBankTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankTransferController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $transfers = BankTransfer::query()
            ->where('wallet_id', $wallet->id)
            ->with([
                'fromBankAccount:id,name,bank_name,bank_code,agency,account_number',
                'toBankAccount:id,name,bank_name,bank_code,agency,account_number',
                'journalEntry:id,status',
            ])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Financial/BankTransfers/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'transfers' => $transfers,
        ]);
    }

    public function create(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);
        $selectedFromBankAccountId = $request->query('from_bank_account_id');

        if ($selectedFromBankAccountId) {
            BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->findOrFail($selectedFromBankAccountId);
        }

        $bankAccounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'bank_name',
                'bank_code',
                'agency',
                'account_number',
                'account_type',
            ]);

        return Inertia::render('Financial/BankTransfers/Create', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'bankAccounts' => $bankAccounts,
            'selectedFromBankAccountId' => $selectedFromBankAccountId ? (int) $selectedFromBankAccountId : null,
        ]);
    }

    public function store(Request $request, CreateBankTransfer $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'from_bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('is_active', true),
            ],
            'to_bank_account_id' => [
                'required',
                'integer',
                'different:from_bank_account_id',
                Rule::exists('bank_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('is_active', true),
            ],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'transfer_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        $bankTransfer = $service->execute($wallet, BankTransferDTO::fromArray($data));

        return redirect()
            ->route('bank-transfers.show', $bankTransfer)
            ->with('success', 'Transferência bancária registrada com sucesso.');
    }

    public function show(Request $request, BankTransfer $bankTransfer): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($bankTransfer->wallet_id === $wallet->id, 404);

        $bankTransfer->load([
            'fromBankAccount.chartOfAccount',
            'toBankAccount.chartOfAccount',
            'journalEntry.lines.chartOfAccount',
        ]);

        return Inertia::render('Financial/BankTransfers/Show', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'transfer' => $bankTransfer,
        ]);
    }
}
