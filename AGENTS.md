# Project-ERP

## Stack
- Laravel 12
- Vue 3
- Inertia
- TypeScript
- Tailwind
- MySQL/SQLite tests

## Architecture
- Backend: Controller -> Service -> DTO -> Model
- Frontend: Page -> Composable -> Components -> lib
- Monetary values are stored in cents.
- Always respect active wallet context.
- Use routes with Ziggy on frontend.
- Prefer services for business rules.

## Accounting rules
- Double-entry accounting.
- Journal entries have status draft or posted.
- Draft entries can be adjusted.
- Posted entries must not be edited or deleted.
- Synthetic accounts cannot receive postings.
- Bank account movements should appear in the bank statement.
- Bank statement should show manual, OFX and future Open Finance origins.
- Bank statement should show reconciliation status: reconciled or pending.

## Financial rules
- Accounts payable/receivable registration does not post journal entries.
- Payment/receipt posts journal entry.
- Credit card purchase posts:
  - Debit expense
  - Credit credit card payable
- Credit card invoice payment posts:
  - Debit credit card payable
  - Credit bank account
- Main card represents invoice.
- Additional/virtual cards share main card settings.

## Commands after changes
Run:
```bash
php artisan test
npm run build
```
