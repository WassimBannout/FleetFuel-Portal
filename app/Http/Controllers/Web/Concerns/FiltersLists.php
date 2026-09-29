<?php

namespace App\Http\Controllers\Web\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Query-string filters for list pages (?q=, ?status=, ?company=). Every
 * value is bound as a parameter; column names are fixed in code, never
 * taken from the request.
 */
trait FiltersLists
{
    protected function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    protected function searchTerm(Request $request): ?string
    {
        $term = Str::limit(trim($request->string('q')->toString()), 100, '');

        return $term === '' ? null : $term;
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns
     */
    protected function applySearch(Builder $query, ?string $term, array $columns): void
    {
        if ($term === null) {
            return;
        }

        $query->where(function (Builder $inner) use ($term, $columns): void {
            foreach ($columns as $column) {
                $inner->orWhere($column, 'like', Like::contains($term));
            }
        });
    }

    /**
     * ?status=active|inactive on an is_active column; anything else shows all.
     *
     * @param  Builder<covariant Model>  $query
     */
    protected function applyActiveFilter(Builder $query, Request $request): void
    {
        $status = $request->query('status');

        if ($status === 'active' || $status === 'inactive') {
            $query->where('is_active', $status === 'active');
        }
    }

    /**
     * ?company= narrows an admin's list. A manager's list is already limited
     * to their own company by visibleTo(), so the parameter is ignored and
     * can never widen it.
     *
     * @param  Builder<covariant Model>  $query
     */
    protected function applyCompanyFilter(Builder $query, Request $request, User $user): void
    {
        $company = $request->query('company');

        if ($user->isAdmin() && is_string($company) && ctype_digit($company)) {
            $query->where('company_id', (int) $company);
        }
    }

    /**
     * Company names for an admin's filter or form dropdown; null for managers.
     *
     * @return Collection<int, string>|null
     */
    protected function companyOptions(User $user, bool $activeOnly = false): ?Collection
    {
        if (! $user->isAdmin()) {
            return null;
        }

        return Company::query()
            ->when($activeOnly, fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('name')
            ->pluck('name', 'id');
    }
}
