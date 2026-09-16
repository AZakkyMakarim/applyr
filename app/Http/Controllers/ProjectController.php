<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\MasterProfile;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class ProjectController extends Controller
{
    public function store(ProjectRequest $request): RedirectResponse
    {
        MasterProfile::query()->firstOrFail()->projects()->create($request->entry());

        return $this->backToSection('added');
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->entry());

        return $this->backToSection('updated');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $project->delete();

        return $this->backToSection('deleted');
    }

    private function backToSection(string $outcome): RedirectResponse
    {
        return to_route('master-profile.edit')->withFragment('projects')->with('status', "Project {$outcome}.");
    }
}
