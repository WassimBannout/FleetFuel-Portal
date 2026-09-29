@extends('layouts.app')

@section('title', $company ? 'Edit company' : 'Add company')

@section('content')
    <h1 class="h3 mb-3">{{ $company ? 'Edit '.$company->name : 'Add company' }}</h1>

    <div class="row g-4">
        <div class="col-lg-6">
            <form method="POST" action="{{ $company ? route('companies.update', $company) : route('companies.store') }}" class="card card-body shadow-sm">
                @csrf
                @if ($company)
                    @method('PUT')
                @endif

                <x-form.input name="name" label="Name" :value="$company?->name" required maxlength="120" />
                <x-form.input name="tax_no" label="Tax number" :value="$company?->tax_no" maxlength="50" help="Optional. Must be unique when given." />

                <div>
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a class="btn btn-link" href="{{ route('companies.index') }}">Cancel</a>
                </div>
            </form>
        </div>

        @if ($company)
            @php($active = $company->status === App\Enums\CompanyStatus::Active)
            <div class="col-lg-6">
                <div class="card card-body shadow-sm">
                    <h2 class="h5">Status @include('partials.active-badge', ['active' => $active])</h2>
                    <p class="mb-2">{{ $company->vehicles_count }} vehicles · {{ $company->drivers_count }} drivers · {{ $company->fuel_cards_count }} cards</p>
                    <p class="small text-body-secondary">
                        Companies are never deleted. An inactive company keeps all its data and history, and its fleet
                        becomes read-only: cards can still be blocked or archived, and vehicles or drivers deactivated.
                    </p>
                    <form method="POST" action="{{ route('companies.status', $company) }}"
                          @if ($active) data-confirm="Deactivate {{ $company->name }}?" @endif>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="{{ $active ? 'inactive' : 'active' }}">
                        <button type="submit" @class(['btn', 'btn-outline-danger' => $active, 'btn-outline-success' => ! $active])>
                            {{ $active ? 'Deactivate company' : 'Reactivate company' }}
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
