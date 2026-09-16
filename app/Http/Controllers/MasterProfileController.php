<?php

namespace App\Http\Controllers;

use App\Http\Requests\MasterProfileRequest;
use App\Models\MasterProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterProfileController extends Controller
{
    public function edit(): View
    {
        return view('master-profile.edit', [
            'masterProfile' => MasterProfile::current(),
        ]);
    }

    public function update(MasterProfileRequest $request): RedirectResponse
    {
        $masterProfile = MasterProfile::current()->fill($request->safe()->except('photo'));

        if ($request->hasFile('photo')) {
            $masterProfile->replacePhoto($request->file('photo'));
        } else {
            $masterProfile->save();
        }

        return to_route('master-profile.edit')->with('status', 'MasterProfile saved.');
    }

    public function photo(): StreamedResponse
    {
        $photoPath = MasterProfile::current()->photo_path;

        abort_unless($photoPath !== null && Storage::disk(MasterProfile::PHOTO_DISK)->exists($photoPath), 404);

        return Storage::disk(MasterProfile::PHOTO_DISK)->response($photoPath);
    }
}
