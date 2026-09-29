@extends('layouts.app')

@section('title', 'Edit product')

@section('content')
    <h1 class="h3 mb-3">Edit product <span class="font-monospace">{{ $product->code }}</span></h1>

    <div class="row">
        <div class="col-lg-6">
            <form method="POST" action="{{ route('products.update', $product) }}" class="card card-body shadow-sm">
                @csrf
                @method('PUT')

                <p class="mb-3">
                    Code <span class="font-monospace">{{ $product->code }}</span> ·
                    {{ ucfirst($product->fuel_type->value) }} · sold per {{ $product->unit }}.
                    The code and fuel type never change.
                </p>
                <x-form.input name="name" label="Display name" :value="$product->name" required maxlength="80" />

                <div>
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a class="btn btn-link" href="{{ route('products.index') }}">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
