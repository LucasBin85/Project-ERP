import { computed } from 'vue'

export function useJournalEntriesIndex(entries) {
    const rows = computed(() => entries?.data ?? [])

    function entryTotal(entry) {
        if (entry.amount_cents !== undefined && entry.amount_cents !== null) {
            return entry.amount_cents
        }

        if (entry.debit_total_cents !== undefined && entry.debit_total_cents !== null) {
            return entry.debit_total_cents
        }

        if (entry.debit_total !== undefined && entry.debit_total !== null) {
            return entry.debit_total
        }

        if (entry.lines?.length) {
            return entry.lines
                .filter((line) => line.type === 'debit' || line.debit_cents)
                .reduce((total, line) => total + Number(line.amount_cents ?? line.debit_cents ?? 0), 0)
        }

        return 0
    }

    function entryDate(entry) {
        return entry.entry_date ?? entry.date
    }

    return {
        rows,
        entryTotal,
        entryDate,
    }
}
