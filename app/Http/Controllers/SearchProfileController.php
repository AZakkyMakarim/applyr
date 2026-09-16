<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchProfileRequest;
use App\Models\SearchProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SearchProfileController extends Controller
{
    public function index(): View
    {
        return view('search-profiles.index', [
            'searchProfiles' => SearchProfile::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('search-profiles.create', [
            'searchProfile' => new SearchProfile(['country_code' => 'ID']),
        ]);
    }

    public function store(SearchProfileRequest $request): RedirectResponse
    {
        $searchProfile = SearchProfile::create($request->validated());

        return $this->backToIndex($searchProfile, 'created');
    }

    public function edit(SearchProfile $searchProfile): View
    {
        return view('search-profiles.edit', [
            'searchProfile' => $searchProfile,
        ]);
    }

    public function update(SearchProfileRequest $request, SearchProfile $searchProfile): RedirectResponse
    {
        $searchProfile->update($request->validated());

        return $this->backToIndex($searchProfile, 'updated');
    }

    public function destroy(SearchProfile $searchProfile): RedirectResponse
    {
        $searchProfile->delete();

        return $this->backToIndex($searchProfile, 'deleted');
    }

    public function pause(SearchProfile $searchProfile): RedirectResponse
    {
        $searchProfile->pause();

        return $this->backToIndex($searchProfile, 'paused');
    }

    public function resume(SearchProfile $searchProfile): RedirectResponse
    {
        $searchProfile->resume();

        return $this->backToIndex($searchProfile, 'resumed');
    }

    private function backToIndex(SearchProfile $searchProfile, string $outcome): RedirectResponse
    {
        return to_route('search-profiles.index')
            ->with('status', "SearchProfile \"{$searchProfile->name}\" {$outcome}.");
    }
}
