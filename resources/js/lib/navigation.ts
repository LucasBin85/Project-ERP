import type { NavItem } from '@/types';
import {
    Banknote,
    BookOpen,
    Building2,
    ChartColumn,
    CircleDollarSign,
    ClipboardCheck,
    CreditCard,
    FileChartColumn,
    FileText,
    FolderTree,
    Landmark,
    LayoutDashboard,
    Receipt,
    Scale,
    Upload,
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
                title: 'Contas Bancárias',
                href: route('bank-accounts.index'),
                icon: Building2,
            },
            {
                title: 'Transferências',
                href: route('bank-transfers.index'),
                icon: Banknote,
            },
            {
                title: 'Extrato Bancário',
                href: route('bank-statements.index'),
                icon: Receipt,
            },
            {
                title: 'Conciliação',
                href: route('bank-reconciliations.index'),
                icon: ClipboardCheck,
            },
            {
                title: 'Contas a Pagar',
                icon: FileText,
                disabled: true,
                badge: 'Em breve',
            },
            {
                title: 'Contas a Receber',
                icon: FileText,
                disabled: true,
                badge: 'Em breve',
            },
            {
                title: 'Cartões de Crédito',
                icon: CreditCard,
                disabled: true,
                badge: 'Em breve',
            },
        ],
    },
    {
        title: 'Importação',
        icon: Upload,
        items: [
            {
                title: 'OFX',
                icon: Upload,
                disabled: true,
                badge: 'Em breve',
            },
            {
                title: 'Open Finance',
                icon: Landmark,
                disabled: true,
                badge: 'Em breve',
            },
        ],
    },
];
