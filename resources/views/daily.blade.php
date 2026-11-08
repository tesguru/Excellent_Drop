@extends('layouts.app')

@section('title', 'Daily list')
@section('subtitle', 'Candidates from the names that left the .com zone on a given day')

@section('content')
    <div data-daily-list>
        @include('partials.daily-candidates')
    </div>
@endsection