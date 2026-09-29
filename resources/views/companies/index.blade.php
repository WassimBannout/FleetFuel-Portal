@extends('layouts.app')

@section('title', 'Companies')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Companies</h1>
        @can('create', App\Models\Company::class)
            <a class="btn btn-primary" href="{{ route('companies.create') }}">Add company</a>
        @endcan
    </div>

    @include('partials.list-filters', [
        'searchLabel' => 'Name or tax number',
        'statusOptions' => ['active' => 'Active', 'inactive' => 'Inactive'],
        'companies' => null,
    ])

    @if ($companies->isEmpty())
        <p class="text-body-secondary">No companies match these filters.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Tax number</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Vehicles</th>
                        <th scope="col" class="text-end">Drivers</th>
                        <th scope="col" class="text-end">Cards</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($companies as $company)
                        <tr>
                            <td>{{ $company->name }}</td>
                            <td>{{ $company->tax_no ?? '—' }}</td>
                            <td>@include('partials.active-badge', ['active' => $company->status === App\Enums\CompanyStatus::Active])</td>
                            <td class="text-end">{{ $company->vehicles_count }}</td>
                            <td class="text-end">{{ $company->drivers_count }}</td>
                            <td class="text-end">{{ $company->fuel_cards_count }}</td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('companies.edit', $company) }}">Edit<span class="visually-hidden"> {{ $company->name }}</span></a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $companies->links() }}
    @endif
@endsection
