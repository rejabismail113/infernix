<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Middleware;

use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return list<array{label: string, href: string, group: string, icon: string}> */
    private function nav(User $user): array
    {
        $p = app(PermissionService::class);
        $items = [
            ['Owner dashboard', 'reports.dashboard.owner', 'dashboard_owner', 'view', 'Overview', 'chart'],
            ['Finance dashboard', 'reports.dashboard.finance', 'dashboard_finance', 'view', 'Overview', 'chart'],
            ['Leads', 'projects.leads.index', 'leads', 'view', 'Sales', 'chart'],
            ['Clients', 'projects.clients.index', 'clients', 'view', 'Sales', 'users'],
            ['Quotations', 'commercial.quotations.index', 'quotations', 'view', 'Sales', 'document'],
            ['Invoices', 'commercial.invoices.index', 'invoices', 'view', 'Sales', 'document'],
            ['Credit notes', 'commercial.credit-notes.index', 'credit_notes', 'view', 'Sales', 'swap'],
            ['Receipts', 'commercial.receipts.index', 'receipts', 'view', 'Sales', 'download'],
            ['Client POs / LOAs', 'commercial.client-pos.index', 'client_pos', 'view', 'Sales', 'document'],
            ['Projects', 'projects.projects.index', 'projects', 'view', 'Projects', 'list'],
            ['Letters & memos', 'commercial.generated.index', 'letters', 'view', 'Sales', 'document'],
            ['Document templates', 'commercial.templates.index', 'document_templates', 'view', 'Sales', 'settings'],
            ['e-Invoice', 'commercial.einvoice.index', 'invoices', 'view', 'Sales', 'upload'],
            ['Finance overview', 'finance.overview', 'claims', 'view', 'Finance', 'chart'],
            ['Claims', 'finance.claims.index', 'claims', 'view', 'Finance', 'audit'],
            ['Expenses & income', 'finance.transactions.index', 'transactions', 'view', 'Finance', 'swap'],
            ['Bills', 'finance.bills.index', 'bills', 'view', 'Finance', 'document'],
            ['Payments', 'finance.payments.index', 'payments', 'view', 'Finance', 'upload'],
            ['Bank', 'finance.bank.index', 'bank', 'view', 'Finance', 'building'],
            ['Journals', 'finance.journals.index', 'journals', 'view', 'Finance', 'list'],
            ['Chart of accounts', 'finance.accounts.index', 'chart_accounts', 'view', 'Finance', 'hash'],
            ['Period close', 'finance.periods.index', 'fiscal_periods', 'view', 'Finance', 'lock'],
            ['Finance reports', 'finance.reports.index', 'finance_reports', 'view', 'Finance', 'report'],
            ['Purchase requests', 'procurement.requests.index', 'purchase_requests', 'view', 'Procurement', 'list'],
            ['Vendors', 'procurement.vendors.index', 'vendors', 'view', 'Procurement', 'users'],
            ['Vendor POs', 'commercial.vendor-pos.index', 'vendor_pos', 'view', 'Procurement', 'document'],
            ['Subscriptions', 'procurement.subscriptions.index', 'subscriptions', 'view', 'Procurement', 'calendar'],
            ['Assets', 'assets.assets.index', 'assets', 'view', 'Operations', 'building'],
            ['Activities & travel', 'activities.activities.index', 'activities', 'view', 'Operations', 'calendar'],
            ['CSR programmes', 'csr.programmes.index', 'csr_programmes', 'view', 'Operations', 'users'],
            ['Certifications', 'certifications.certifications.index', 'certifications', 'view', 'People', 'shield'],
            ['Credentials', 'credentials.credentials.index', 'credentials', 'view', 'Security', 'lock'],
            ['Access checklists', 'credentials.jml.index', 'jml_checklists', 'view', 'Security', 'list'],
            ['Staff directory', 'hr.staff.index', 'staff_profiles', 'view', 'People', 'users'],
            ['Timesheets', 'hr.timesheets.index', 'timesheets', 'view', 'People', 'calendar'],
            ['Payment batches', 'hr.batches.index', 'payment_batches', 'view', 'People', 'upload'],
            ['My memos', 'hr.memos.index', 'memos', 'view', 'People', 'bell'],
            ['Project dashboard', 'reports.projects.dashboard', 'projects', 'view', 'Reports', 'chart'],
            ['All reports', 'reports.index', 'projects', 'view', 'Reports', 'report'],
            ['Export Centre', 'reports.exports.index', 'exports', 'create', 'Reports', 'download'],
            ['Documents', 'documents.index', 'documents', 'view', 'Documents', 'document'],
            ['Document reports', 'documents.reports.inventory', 'documents', 'view', 'Documents', 'report'],
            ['Users', 'admin.users.index', 'users', 'view', 'Administration', 'users'],
            ['Permissions', 'admin.permissions.index', 'permissions', 'view', 'Administration', 'shield'],
            ['Approval matrix', 'admin.approval-matrix.index', 'approval_matrix', 'view', 'Administration', 'swap'],
            ['Delegations', 'admin.delegations.index', 'delegations', 'view', 'Administration', 'swap'],
            ['Company', 'admin.company.edit', 'company', 'view', 'Administration', 'building'],
            ['Fiscal periods', 'admin.fiscal-periods.index', 'fiscal_periods', 'view', 'Administration', 'calendar'],
            ['Numbering', 'admin.numbering.index', 'numbering', 'view', 'Administration', 'hash'],
            ['Dictionaries', 'admin.dictionaries.index', 'dictionaries', 'view', 'Administration', 'list'],
            ['Notification rules', 'admin.notification-rules.index', 'notification_rules', 'view', 'Administration', 'bell'],
            ['Audit log', 'admin.audit-log.index', 'audit_log', 'view', 'Administration', 'audit'],
            ['Backups', 'admin.backups.index', 'backup_status', 'view', 'Administration', 'download'],
            ['Data import', 'admin.imports.index', 'data_import', 'create', 'Administration', 'upload'],
            ['Access reports', 'admin.reports.user-access', 'reports_access', 'view', 'Administration', 'report'],
        ];
        $nav = [['label' => __('Dashboard'), 'href' => route('dashboard'), 'group' => __('Overview'), 'icon' => 'home']];
        foreach ($items as [$label, $routeName, $resource, $action, $group, $icon]) {
            if ($p->can($user, $action, $resource)) {
                $nav[] = ['label' => __($label), 'href' => route($routeName), 'group' => __($group), 'icon' => $icon];
            }
        }

        return $nav;
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            ],
            'app' => [
                'name' => config('app.name'),
                'company' => config('infernix.company'),
                'currency' => config('infernix.currency'),
                'timezone' => config('infernix.timezone'),
            ],
            // Sidebar entries are filtered by the same PermissionService the routes use (UI hiding is NOT a control).
            'nav' => fn () => $user instanceof User ? $this->nav($user) : [],
            'notifications' => fn () => $user instanceof User ? ['unread' => $user->unreadNotifications()->count()] : ['unread' => 0],
            // NFR-12: UI strings for the client t() helper (lang/{locale}.json; EN keys are the source text).
            'i18n' => fn () => ['locale' => app()->getLocale(), 'messages' => $this->messages()],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'import' => $request->session()->get('import'),
                'data_import' => $request->session()->get('data_import'),
            ],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        $path = lang_path(app()->getLocale().'.json');
        /** @var array<string, string> $m */
        $m = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];

        return $m;
    }
}
