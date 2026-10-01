{{-- Laravel ships its own 500 view, which would win over 5xx; this file points back to ours. --}}
@extends('errors.5xx')
