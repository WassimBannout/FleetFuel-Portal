@extends('errors.layout')

@section('code', '404')
@section('title', 'Page not found')
{{-- The same words whether the record never existed or belongs to another company: a 404 reveals nothing. --}}
@section('message', 'This page or record does not exist, or you do not have access to it. Check the address, or go back to your start page.')
@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Go to the start page</a>
@endsection
