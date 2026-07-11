import type { NavItem } from '@/types';
import {
    BookOpen,
    Building2,
    ChartColumn,
    CircleDollarSign,
    CreditCard,
    FileChartColumn,
    FileText,
    FolderTree,
    Landmark,
    LayoutDashboard,
    Scale,
    WalletCards,
} from 'lucide-vue-next';
import { route } from 'ziggy-js';

export const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
        icon: LayoutDashboard,
    },
    {
        title: 'Contabilidade',
        icon: Scale,
        items: [
            {
                title: 'Plano de Contas',
                href: route('chart-of-accounts.index'),
                icon: FolderTree,
            },
            {
                title: 'Lançamentos',
                href: route('journal-entries.index'),
                icon: FileText,
            },
            {
                title: 'Livro Diário',
                href: route('general-journal.index'),
                icon: BookOpen,
            },
            {
                title: 'Livro Razão',
                href: route('ledger.index'),
                icon: Scale,
            },
            {
                title: 'Balancete',
                href: route('trial-balance.index'),
                icon: FileChartColumn,
            },
            {
                title: 'DRE',
                href: route('income-statement.index'),
                icon: ChartColumn,
            },
            {
                title: 'Balanço Patrimonial',
                href: route('balance-sheet.index'),
                icon: Landmark,
            },
        ],
    },
    {
        title: 'Financeiro',
        icon: WalletCards,
        items: [
            {
                title: 'Posição Financeira',
                href: route('financial-position.index'),
                icon: CircleDollarSign,
            },
            {
                title: 'Fluxo de Caixa',
                href: route('cash-flow.index'),
                icon: ChartColumn,
            },
            {
                title: 'Contas Bancárias',
                href: route('bank-accounts.index'),
                icon: Building2,
            },
            {
                title: 'Contas a Pagar',
                href: route('accounts-payable.index'),
                icon: FileText,
            },
            {
                title: 'Contas a Receber',
                href: route('accounts-receivable.index'),
                icon: FileText,
            },
            {
                title: 'Cartões de Crédito',
                href: route('credit-cards.index'),
                icon: CreditCard,
            },
        ],
    },
];
