@extends('errors.layout')

{{-- Any other 4xx (Laravel falls back to this view), notably 429 Too Many Requests. --}}
@php($status = $exception->getStatusCode())

@section('code', (string) $status)
@section('title', $status === 429 ? 'Too many requests' : 'Request not accepted')
@section('message', $status === 429
    ? 'Too many attempts in a short time. Wait a minute, then try again.'
    : 'The request could not be completed. Go back and try again.')
@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Go to the start page</a>
@endsection
