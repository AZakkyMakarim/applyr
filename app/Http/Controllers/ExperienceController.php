<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExperienceRequest;
use App\Models\Experience;
use App\Models\MasterProfile;
use Illuminate\Http\RedirectResponse;

class ExperienceController extends Controller
{
    public function store(ExperienceRequest $request): RedirectResponse
    {
        MasterProfile::query()->firstOrFail()->experiences()->create($request->entry());

        return $this->backToSection('added');
    }

    public function update(ExperienceRequest $request, Experience $experience): RedirectResponse
    {
        $experience->update($request->entry());

        return $this->backToSection('updated');
    }

    public function destroy(Experience $experience): RedirectResponse
    {
        $experience->delete();

        return $this->backToSection('deleted');
    }

    private function backToSection(string $outcome): RedirectResponse
    {
        return to_route('master-profile.edit')->withFragment('experiences')->with('status', "Experience {$outcome}.");
    }
}
