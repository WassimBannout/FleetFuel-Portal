@extends('errors.layout')

{{--
    500, 503 and any other server error (Laravel falls back to this view).
    No exception text is shown: it is logged, with the request ID.
--}}
@php($status = $exception->getStatusCode())

@section('code', (string) $status)
@section('title', $status === 503 ? 'Temporarily unavailable' : 'Something went wrong')
@section('message', $status === 503
    ? 'The portal is briefly unavailable, for example during maintenance. Try again in a few minutes.'
    : 'An unexpected error stopped this request on our side. It has been logged. Try again in a moment; if it keeps happening, tell an administrator what you were doing.')
@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Go to the start page</a>
@endsection
