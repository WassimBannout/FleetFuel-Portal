@extends('errors.layout')

@section('code', '419')
@section('title', 'Your session expired')
{{-- A form was sent with an outdated security (CSRF) token: signed out, timed out, or signed in again in another tab. --}}
@section('message', 'For your security, forms stop working after you sign out or stay inactive for a while. Nothing was saved. Sign in again, then repeat the change.')
@section('actions')
    <a class="btn btn-primary" href="{{ route('login') }}">Sign in again</a>
    <a class="btn btn-outline-secondary" href="{{ url('/') }}">Go to the start page</a>
@endsection
