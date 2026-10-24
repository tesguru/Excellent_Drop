@extends('layouts.app')

@section('title', 'Watchlist')
@section('subtitle', 'Domains you keep an eye on')

@section('content')
    <form id="watchlist-form" class="card flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-56 flex-1">
            <label for="domain" class="label">Domain</label>
            <input id="domain" name="domain" type="text" placeholder="example.com" required
                   class="field mt-2">
        </div>
        <div>
            <label for="status" class="label">Status</label>
            <select id="status" name="status" class="field mt-2 cursor-pointer">
                @foreach ($statuses as $status)
                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Add</button>
    </form>

    <p id="watchlist-message" class="hidden text-sm"></p>

    @include('partials.watchlist-entries', [
        'entries' => $entries,
        'statuses' => $statuses,
        'query' => $query ?? '',
        'pagination' => $pagination ?? null,
    ])
@endsection
