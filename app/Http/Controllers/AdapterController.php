<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Models\AdapterHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdapterController extends Controller
{
    public function index(): View
    {
        $stored = AdapterHealth::query()->get()->keyBy(fn (AdapterHealth $health) => $health->platform->value);

        return view('adapters.index', [
            // An Adapter that has never run has no row yet; show it as healthy without creating one.
            'adapters' => collect(Platform::cases())->map(
                fn (Platform $platform) => $stored->get($platform->value) ?? new AdapterHealth(['platform' => $platform]),
            ),
        ]);
    }

    public function resume(Platform $platform): RedirectResponse
    {
        AdapterHealth::for($platform)->resume();

        return to_route('adapters.index')->with('status', "{$platform->label()} Adapter resumed.");
    }
}
