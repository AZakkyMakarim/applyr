@extends('layouts.dashboard')

@section('title', 'Edit SearchProfile')

@section('content')
    <h1 class="text-2xl font-semibold">Edit SearchProfile</h1>

    <form method="POST" action="{{ route('search-profiles.update', $searchProfile) }}" class="mt-6">
        @csrf
        @method('PUT')
        @include('search-profiles._form', ['submitLabel' => 'Save changes'])
    </form>
@endsection
