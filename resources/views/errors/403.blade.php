@extends('errors.layout')

@section('code', '403')
@section('title', 'Not allowed')
@section('message', 'Your account does not have access to this page or action. If you think it should, ask an administrator.')
@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Go to the start page</a>
@endsection
