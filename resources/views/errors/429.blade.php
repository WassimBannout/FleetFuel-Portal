{{-- Laravel ships its own 429 view, which would win over 4xx; this file points back to ours. --}}
@extends('errors.4xx')
