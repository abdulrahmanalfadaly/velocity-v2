<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Whether a reset link can really be emailed. With the "log" mailer (no mail server set up yet)
     * nothing would arrive, so "Forgot it?" is hidden and this page goes back to the login.
     */
    public static function available(): bool
    {
        return config('mail.default') !== 'log';
    }

    /**
     * Show the password reset link request page.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if (! self::available()) {
            return to_route('login');
        }

        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        if (! self::available()) {
            return to_route('login');
        }
        $request->validate([
            'email' => 'required|email',
        ]);

        Password::sendResetLink(
            $request->only('email')
        );

        return back()->with('status', __('A reset link will be sent if the account exists.'));
    }
}
