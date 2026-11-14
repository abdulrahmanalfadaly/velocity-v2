<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\ApiAccess;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Settings → Integrations: access keys for Mark (or anything else that calls the API).
 * A key is shown once; Velocity keeps only its fingerprint.
 */
class IntegrationController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/integrations', [
            'keys' => $user->tokens()->latest('id')->get()->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'access' => ApiAccess::summary($t->abilities),
                'createdAt' => $t->created_at?->toIso8601ZuluString(),
                'lastUsedAt' => $t->last_used_at?->toIso8601ZuluString(),
                'expiresAt' => $t->expires_at?->toIso8601ZuluString(),
                'expired' => $t->expires_at?->isPast() ?? false, // keys run on real time, not the demo clock
            ])->values(),
            'areas' => collect(ApiAccess::AREAS)->map(fn ($a, $key) => ['key' => $key, 'label' => $a[0], 'writable' => $a[1]])->values(),
            // The new key, once, right after it is made.
            'newKey' => $request->session()->get('newKey'),
            'apiUrl' => url('/api/v1'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'full' => ['required', 'boolean'],
            'levels' => ['array'],
            'levels.*' => [Rule::in(['none', 'read', 'write'])],
            'expires' => ['required', Rule::in(['never', '30', '90', '365'])],
        ]);
        $abilities = ApiAccess::abilities((bool) $d['full'], $d['levels'] ?? []);
        if (! $abilities) {
            throw ValidationException::withMessages(['levels' => 'Choose at least one area.']);
        }
        $expires = $d['expires'] === 'never' ? null : now()->addDays((int) $d['expires']);
        $key = $request->user()->createToken(trim($d['name']), $abilities, $expires);

        return to_route('integrations.edit')
            ->with('newKey', ['id' => $key->accessToken->id, 'name' => $key->accessToken->name, 'key' => $key->plainTextToken])
            ->with('toast', "Made the key “{$key->accessToken->name}”");
    }

    /** Revoking is final: anything using the key stops working at once. */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $token = $request->user()->tokens()->findOrFail($id);
        $token->delete();

        return to_route('integrations.edit')->with('toast', "Revoked “{$token->name}”");
    }
}
