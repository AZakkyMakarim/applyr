@extends('layouts.dashboard')

@section('title', 'New SearchProfile')

@section('content')
    <h1 class="text-2xl font-semibold">New SearchProfile</h1>

    <form method="POST" action="{{ route('search-profiles.store') }}" class="mt-6">
        @csrf
        @include('search-profiles._form', ['submitLabel' => 'Create SearchProfile'])
    </form>
@endsection
