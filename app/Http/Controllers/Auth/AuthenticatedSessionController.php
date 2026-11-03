<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * There is no separate login page (owner decision 2026-11-03): GET /login goes to the home page, which
     * opens its login modal (Welcome.vue, `openLogin`). The route stays, because the `auth` middleware sends
     * guests here and password reset redirects here; reflash keeps that `status` message for the home page.
     */
    public function create(Request $request): RedirectResponse
    {
        $request->session()->reflash();

        return to_route('home')->with('openLogin', true);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $destination = $request->user()->canAccessAdminPanel() ? route('dashboard', absolute: false) : route('home', absolute: false);

        return redirect()->intended($destination);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
