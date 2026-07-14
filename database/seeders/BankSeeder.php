<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        $banks = [
            ['code' => '001', 'name' => 'Banco do Brasil S.A.', 'short_name' => 'Banco do Brasil', 'ispb' => '00000000'],
            ['code' => '033', 'name' => 'Banco Santander (Brasil) S.A.', 'short_name' => 'Santander', 'ispb' => '90400888'],
            ['code' => '041', 'name' => 'Banco do Estado do Rio Grande do Sul S.A.', 'short_name' => 'Banrisul', 'ispb' => '92702067'],
            ['code' => '070', 'name' => 'BRB - Banco de Brasília S.A.', 'short_name' => 'BRB', 'ispb' => '00000208'],
            ['code' => '077', 'name' => 'Banco Inter S.A.', 'short_name' => 'Inter', 'ispb' => '00416968'],
            ['code' => '104', 'name' => 'Caixa Econômica Federal', 'short_name' => 'Caixa', 'ispb' => '00360305'],
            ['code' => '197', 'name' => 'Stone Instituição de Pagamento S.A.', 'short_name' => 'Stone', 'ispb' => '16501555'],
            ['code' => '208', 'name' => 'Banco BTG Pactual S.A.', 'short_name' => 'BTG Pactual', 'ispb' => '30306294'],
            ['code' => '212', 'name' => 'Banco Original S.A.', 'short_name' => 'Banco Original', 'ispb' => '92894922'],
            ['code' => '237', 'name' => 'Banco Bradesco S.A.', 'short_name' => 'Bradesco', 'ispb' => '60746948'],
            ['code' => '260', 'name' => 'Nu Pagamentos S.A.', 'short_name' => 'Nubank', 'ispb' => '18236120'],
            ['code' => '290', 'name' => 'PagSeguro Internet Instituição de Pagamento S.A.', 'short_name' => 'PagBank', 'ispb' => '08561701'],
            ['code' => '323', 'name' => 'Mercado Pago Instituição de Pagamento Ltda.', 'short_name' => 'Mercado Pago', 'ispb' => '10573521'],
            ['code' => '336', 'name' => 'Banco C6 S.A.', 'short_name' => 'C6 Bank', 'ispb' => '31872495'],
            ['code' => '341', 'name' => 'Itaú Unibanco S.A.', 'short_name' => 'Itaú', 'ispb' => '60701190'],
            ['code' => '380', 'name' => 'PicPay Instituição de Pagamento S.A.', 'short_name' => 'PicPay', 'ispb' => '22896431'],
            ['code' => '422', 'name' => 'Banco Safra S.A.', 'short_name' => 'Safra', 'ispb' => '58160789'],
            ['code' => '748', 'name' => 'Banco Cooperativo Sicredi S.A.', 'short_name' => 'Sicredi', 'ispb' => '01181521'],
            ['code' => '756', 'name' => 'Banco Cooperativo Sicoob S.A.', 'short_name' => 'Sicoob', 'ispb' => '02038232'],
        ];

        foreach ($banks as $bank) {
            Bank::query()->updateOrCreate(
                ['ispb' => $bank['ispb']],
                [...$bank, 'active' => true],
            );
        }
    }
}
