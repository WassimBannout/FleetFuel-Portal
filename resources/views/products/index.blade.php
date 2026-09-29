@extends('layouts.app')

@section('title', 'Products')

@section('content')
    @php($admin = auth()->user()->isAdmin())

    <h1 class="h3 mb-3">{{ $admin ? 'Products' : 'Active products' }}</h1>

    @if ($products->isEmpty())
        <p class="text-body-secondary">No products yet.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">Name</th>
                        <th scope="col">Fuel type</th>
                        <th scope="col">Unit</th>
                        @if ($admin)
                            <th scope="col">Status</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($products as $product)
                        <tr>
                            <td class="font-monospace">{{ $product->code }}</td>
                            <td>{{ $product->name }}</td>
                            <td>{{ ucfirst($product->fuel_type->value) }}</td>
                            <td>{{ $product->unit }}</td>
                            @if ($admin)
                                <td>@include('partials.active-badge', ['active' => $product->is_active])</td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('products.edit', $product) }}">Edit<span class="visually-hidden"> {{ $product->code }}</span></a>
                                    @include('partials.active-toggle', ['action' => route('products.active', $product), 'active' => $product->is_active, 'name' => $product->code])
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
