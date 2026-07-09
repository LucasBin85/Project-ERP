<?php

namespace Database\Seeders;

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\BankTransferDTO;
use App\DTOs\Financial\CreditCardDTO;
use App\DTOs\Financial\CreditCardPaymentDTO;
use App\DTOs\Financial\CreditCardTransactionDTO;
use App\DTOs\Financial\PayAccountPayableDTO;
use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardPayment;
use App\Models\CreditCardTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateAccountPayable;
use App\Services\Financial\CreateAccountReceivable;
use App\Services\Financial\CreateBankAccount;
use App\Services\Financial\CreateBankTransfer;
use App\Services\Financial\CreateCreditCard;
use App\Services\Financial\CreateCreditCardTransaction;
use App\Services\Financial\PayAccountPayable;
use App\Services\Financial\PayCreditCardInvoice;
use App\Services\Financial\ReceiveAccountReceivable;
use Illuminate\Database\Seeder;

class JournalEntryTestSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first();

        if (! $user) {
            $this->command?->warn('Nenhum usuário encontrado. Crie um usuário antes de rodar o seeder.');
            return;
        }

        $wallet = $user->wallets()->first();

        if (! $wallet) {
            $this->command?->warn('Usuário sem wallet vinculada.');
            return;
        }

        $this->ensureSuspenseAccount($wallet);

        $expense = $this->account($wallet, '5.1.1');
        $income = $this->account($wallet, '4.1.1');

        if (! $expense || ! $income || ! $wallet->suspense_account_id) {
            $this->command?->warn('Plano de contas base incompleto. Seeder financeiro não executado.');
            return;
        }

        $primaryBank = $this->bankAccount($wallet, [
            'name' => 'Banco Principal',
            'bank_name' => 'Banco do Brasil',
            'bank_code' => '001',
            'agency' => '1234-5',
            'account_number' => '98765-4',
            'account_type' => 'checking',
            'opening_balance_cents' => 2500000,
            'opening_balance_date' => now()->startOfMonth()->toDateString(),
        ]);

        $reserveBank = $this->bankAccount($wallet, [
            'name' => 'Banco Reserva',
            'bank_name' => 'Nubank',
            'bank_code' => '260',
            'agency' => '0001',
            'account_number' => '123456-7',
            'account_type' => 'savings',
            'opening_balance_cents' => 850000,
            'opening_balance_date' => now()->startOfMonth()->toDateString(),
        ]);

        $this->entry($wallet, 'demo-ofx-001', 'ofx', now()->subDays(8)->toDateString(), 'Compra no mercado', 'draft', [
            ['account' => $primaryBank->chartOfAccount, 'type' => 'credit', 'amount' => 12590, 'memo' => 'Saída da conta bancária'],
            ['account' => $wallet->suspenseAccount, 'type' => 'debit', 'amount' => 12590, 'memo' => 'A classificar'],
        ]);

        $this->entry($wallet, 'demo-open-001', 'open_finance', now()->subDays(7)->toDateString(), 'Recebimento PIX a classificar', 'draft', [
            ['account' => $primaryBank->chartOfAccount, 'type' => 'debit', 'amount' => 350000, 'memo' => 'Entrada na conta bancária'],
            ['account' => $wallet->suspenseAccount, 'type' => 'credit', 'amount' => 350000, 'memo' => 'A classificar'],
        ]);

        $this->entry($wallet, 'demo-manual-001', 'manual', now()->subDays(6)->toDateString(), 'Combustível', 'posted', [
            ['account' => $expense, 'type' => 'debit', 'amount' => 23000, 'memo' => 'Despesa operacional'],
            ['account' => $primaryBank->chartOfAccount, 'type' => 'credit', 'amount' => 23000, 'memo' => 'Pagamento via banco'],
        ]);

        $this->entry($wallet, 'demo-manual-002', 'manual', now()->subDays(5)->toDateString(), 'Venda de serviço', 'posted', [
            ['account' => $primaryBank->chartOfAccount, 'type' => 'debit', 'amount' => 850000, 'memo' => 'Recebimento bancário'],
            ['account' => $income, 'type' => 'credit', 'amount' => 850000, 'memo' => 'Receita operacional'],
        ]);

        $this->entry($wallet, 'demo-ofx-002', 'ofx', now()->subDays(4)->toDateString(), 'Padaria', 'draft', [
            ['account' => $primaryBank->chartOfAccount, 'type' => 'credit', 'amount' => 1890, 'memo' => 'Saída da conta bancária'],
            ['account' => $wallet->suspenseAccount, 'type' => 'debit', 'amount' => 1890, 'memo' => 'A classificar'],
        ]);

        $this->bankTransfer($wallet, $primaryBank, $reserveBank);
        $this->accountsPayable($wallet, $expense, $primaryBank);
        $this->accountsReceivable($wallet, $income, $primaryBank);
        $this->creditCards($wallet, $expense, $primaryBank);

        $this->command?->info('JournalEntryTestSeeder executado com dados financeiros de demonstração.');
    }

    private function ensureSuspenseAccount(Wallet $wallet): void
    {
        $suspense = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('code', '1.1.99')
            ->first();

        if ($suspense && ! $wallet->suspense_account_id) {
            $wallet->update(['suspense_account_id' => $suspense->id]);
            $wallet->refresh();
        }
    }

    private function account(Wallet $wallet, string $code): ?ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('code', $code)
            ->where('allows_posting', true)
            ->first();
    }

    private function bankAccount(Wallet $wallet, array $data): BankAccount
    {
        $existing = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('name', $data['name'])
            ->with('chartOfAccount')
            ->first();

        if ($existing) {
            return $existing;
        }

        return app(CreateBankAccount::class)->execute($wallet, $data)->fresh('chartOfAccount');
    }

    private function entry(Wallet $wallet, string $externalId, string $source, string $date, string $description, string $status, array $lines): void
    {
        if (JournalEntry::query()->where('wallet_id', $wallet->id)->where('source', $source)->where('external_id', $externalId)->exists()) {
            return;
        }

        $debit = collect($lines)->where('type', 'debit')->sum('amount');
        $credit = collect($lines)->where('type', 'credit')->sum('amount');

        $entry = JournalEntry::query()->create([
            'wallet_id' => $wallet->id,
            'source' => $source,
            'external_id' => $externalId,
            'entry_date' => $date,
            'description' => $description,
            'status' => $status,
            'posted_at' => $status === 'posted' ? now() : null,
            'is_balanced' => $debit === $credit,
            'balance_diff_cents' => $debit - $credit,
        ]);

        foreach ($lines as $line) {
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $line['account']->id,
                'type' => $line['type'],
                'amount_cents' => $line['amount'],
                'memo' => $line['memo'] ?? null,
            ]);
        }
    }

    private function bankTransfer(Wallet $wallet, BankAccount $from, BankAccount $to): void
    {
        if (JournalEntry::query()->where('wallet_id', $wallet->id)->where('description', 'Transferência para reserva')->exists()) {
            return;
        }

        app(CreateBankTransfer::class)->execute(
            $wallet,
            new BankTransferDTO(
                fromBankAccountId: $from->id,
                toBankAccountId: $to->id,
                amountCents: 300000,
                transferDate: now()->subDays(3)->toDateString(),
                description: 'Transferência para reserva',
            ),
        );
    }

    private function accountsPayable(Wallet $wallet, ChartOfAccount $expense, BankAccount $bankAccount): void
    {
        $paid = AccountPayable::query()
            ->where('wallet_id', $wallet->id)
            ->where('description', 'Internet empresarial')
            ->first();

        if (! $paid) {
            $paid = app(CreateAccountPayable::class)->execute(
                $wallet,
                new AccountPayableDTO(
                    expenseAccountId: $expense->id,
                    payeeName: 'Fornecedor Internet',
                    description: 'Internet empresarial',
                    dueDate: now()->subDays(2)->toDateString(),
                    amountCents: 12990,
                ),
            );
        }

        if ($paid->status === 'pending') {
            app(PayAccountPayable::class)->execute(
                $wallet,
                $paid,
                new PayAccountPayableDTO(
                    bankAccountId: $bankAccount->id,
                    paidAt: now()->subDay()->toDateString(),
                ),
            );
        }

        if (! AccountPayable::query()->where('wallet_id', $wallet->id)->where('description', 'Aluguel sala comercial')->exists()) {
            app(CreateAccountPayable::class)->execute(
                $wallet,
                new AccountPayableDTO(
                    expenseAccountId: $expense->id,
                    payeeName: 'Imobiliária Centro',
                    description: 'Aluguel sala comercial',
                    dueDate: now()->addDays(5)->toDateString(),
                    amountCents: 180000,
                ),
            );
        }
    }

    private function accountsReceivable(Wallet $wallet, ChartOfAccount $income, BankAccount $bankAccount): void
    {
        $received = AccountReceivable::query()
            ->where('wallet_id', $wallet->id)
            ->where('description', 'Mensalidade cliente Alpha')
            ->first();

        if (! $received) {
            $received = app(CreateAccountReceivable::class)->execute(
                $wallet,
                new AccountReceivableDTO(
                    revenueAccountId: $income->id,
                    customerName: 'Cliente Alpha',
                    description: 'Mensalidade cliente Alpha',
                    dueDate: now()->subDays(3)->toDateString(),
                    amountCents: 220000,
                ),
            );
        }

        if ($received->status === 'pending') {
            app(ReceiveAccountReceivable::class)->execute(
                $wallet,
                $received,
                new ReceiveAccountReceivableDTO(
                    bankAccountId: $bankAccount->id,
                    receivedAt: now()->subDays(2)->toDateString(),
                ),
            );
        }

        if (! AccountReceivable::query()->where('wallet_id', $wallet->id)->where('description', 'Projeto cliente Beta')->exists()) {
            app(CreateAccountReceivable::class)->execute(
                $wallet,
                new AccountReceivableDTO(
                    revenueAccountId: $income->id,
                    customerName: 'Cliente Beta',
                    description: 'Projeto cliente Beta',
                    dueDate: now()->addDays(7)->toDateString(),
                    amountCents: 480000,
                ),
            );
        }
    }

    private function creditCards(Wallet $wallet, ChartOfAccount $expense, BankAccount $bankAccount): void
    {
        $mainCard = CreditCard::query()
            ->where('wallet_id', $wallet->id)
            ->where('name', 'Nubank Principal')
            ->first();

        if (! $mainCard) {
            $mainCard = app(CreateCreditCard::class)->execute(
                $wallet,
                new CreditCardDTO(
                    name: 'Nubank Principal',
                    issuerName: 'Nubank',
                    network: 'mastercard',
                    cardType: 'main',
                    closingDay: 5,
                    dueDay: 15,
                    bestPurchaseDay: 6,
                    creditLimitCents: 600000,
                    bankAccountId: $bankAccount->id,
                    holderName: 'Usuário Demo',
                    lastFour: '1234',
                ),
            );
        }

        if (! CreditCard::query()->where('wallet_id', $wallet->id)->where('name', 'Nubank Virtual')->exists()) {
            app(CreateCreditCard::class)->execute(
                $wallet,
                new CreditCardDTO(
                    name: 'Nubank Virtual',
                    issuerName: 'Nubank',
                    network: 'mastercard',
                    cardType: 'virtual',
                    closingDay: 5,
                    dueDay: 15,
                    bestPurchaseDay: 6,
                    creditLimitCents: 600000,
                    parentCardId: $mainCard->id,
                    holderName: 'Usuário Demo',
                    lastFour: '5678',
                ),
            );
        }

        if (! CreditCardTransaction::query()->where('wallet_id', $wallet->id)->where('description', 'Assinatura software ERP')->exists()) {
            app(CreateCreditCardTransaction::class)->execute(
                $wallet,
                new CreditCardTransactionDTO(
                    creditCardId: $mainCard->id,
                    expenseAccountId: $expense->id,
                    purchaseDate: now()->subDays(9)->toDateString(),
                    merchantName: 'SaaS Tools',
                    description: 'Assinatura software ERP',
                    amountCents: 8990,
                ),
            );
        }

        if (! CreditCardTransaction::query()->where('wallet_id', $wallet->id)->where('description', 'Notebook acessórios')->exists()) {
            $virtualCard = CreditCard::query()
                ->where('wallet_id', $wallet->id)
                ->where('name', 'Nubank Virtual')
                ->first();

            app(CreateCreditCardTransaction::class)->execute(
                $wallet,
                new CreditCardTransactionDTO(
                    creditCardId: $virtualCard?->id ?? $mainCard->id,
                    expenseAccountId: $expense->id,
                    purchaseDate: now()->subDays(2)->toDateString(),
                    merchantName: 'Loja Tech',
                    description: 'Notebook acessórios',
                    amountCents: 24990,
                    installmentsTotal: 3,
                    installmentNumber: 1,
                ),
            );
        }

        if (! CreditCardPayment::query()->where('wallet_id', $wallet->id)->where('description', 'Pagamento fatura Nubank')->exists()) {
            $invoice = CreditCardInvoice::query()
                ->where('wallet_id', $wallet->id)
                ->where('credit_card_id', $mainCard->id)
                ->where('balance_cents', '>', 0)
                ->orderBy('due_at')
                ->first();

            if (! $invoice) {
                return;
            }

            app(PayCreditCardInvoice::class)->execute(
                $wallet,
                new CreditCardPaymentDTO(
                    creditCardId: $mainCard->id,
                    creditCardInvoiceId: $invoice->id,
                    bankAccountId: $bankAccount->id,
                    paymentDate: now()->subDay()->toDateString(),
                    amountCents: min(8990, $invoice->balance_cents),
                    description: 'Pagamento fatura Nubank',
                ),
            );
        }
    }
}
