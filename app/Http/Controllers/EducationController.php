<?php

namespace App\Http\Controllers;

use App\Http\Requests\EducationRequest;
use App\Models\Education;
use App\Models\MasterProfile;
use Illuminate\Http\RedirectResponse;

class EducationController extends Controller
{
    public function store(EducationRequest $request): RedirectResponse
    {
        MasterProfile::query()->firstOrFail()->educations()->create($request->entry());

        return $this->backToSection('added');
    }

    public function update(EducationRequest $request, Education $education): RedirectResponse
    {
        $education->update($request->entry());

        return $this->backToSection('updated');
    }

    public function destroy(Education $education): RedirectResponse
    {
        $education->delete();

        return $this->backToSection('deleted');
    }

    private function backToSection(string $outcome): RedirectResponse
    {
        return to_route('master-profile.edit')->withFragment('educations')->with('status', "Education {$outcome}.");
    }
}
