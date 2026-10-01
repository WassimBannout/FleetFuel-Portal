<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAuditLogsRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\AuditDisplay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

/**
 * The read-only audit trail for admins (docs/01-PROJECT-BRIEF.md, F10): who
 * changed what, when, and the selected before/after values. There is no
 * route that edits or deletes an audit row.
 */
class AuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request): View
    {
        $user = $request->actor();
        $range = $request->range();
        $actor = $request->filterValue('actor');
        $action = $request->filterValue('action');
        $entity = $request->filterValue('entity');
        $company = $request->filterValue('company_id');

        // A fixed number of queries per page: the count, the rows, and one
        // eager-loaded query each for the actors and companies shown.
        $entries = AuditLog::query()
            ->visibleTo($user)
            ->with(['user', 'company'])
            ->when($actor === 'system', fn (Builder $q) => $q->whereNull('user_id'))
            ->when($actor !== null && $actor !== 'system', fn (Builder $q) => $q->where('user_id', (int) $actor))
            ->when($action !== null, fn (Builder $q) => $q->where('action', $action))
            ->when($entity !== null, fn (Builder $q) => $q->where('auditable_type', $entity))
            ->when($company !== null, fn (Builder $q) => $q->where('company_id', (int) $company))
            ->when($range !== null, fn (Builder $q) => $q->where('created_at', '>=', $range[0])->where('created_at', '<', $range[1]))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ListAuditLogsRequest::PER_PAGE)
            ->withQueryString();

        return view('audit.index', [
            'entries' => $entries,
            'filters' => compact('actor', 'action', 'entity', 'company') + [
                'from' => $request->filterValue('from'),
                'to' => $request->filterValue('to'),
            ],
            // Filter choices: only actions and actors that appear in the trail.
            'actions' => AuditLog::query()->visibleTo($user)->distinct()->orderBy('action')->pluck('action'),
            'actors' => User::query()
                ->whereIn('id', AuditLog::query()->visibleTo($user)->whereNotNull('user_id')->select('user_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'role']),
            'entities' => AuditDisplay::entityOptions(),
            'companies' => Company::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
