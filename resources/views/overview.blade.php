@extends('layouts.app')

@section('title', 'Overview')
@section('subtitle', 'The latest .com candidates')

@section('content')
    <div data-overview-list>
        @include('partials.daily-candidates')
    </div>
@endsection