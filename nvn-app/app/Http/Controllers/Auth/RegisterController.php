<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\OtpService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function show(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::create([
            'full_name' => $request->validated('full_name'),
            'email'     => $request->validated('email'),
            'phone'     => $request->validated('phone'),
            'password'  => $request->validated('password'),
            'role'      => UserRole::Client, // public signups are always clients
            'status'    => 'active',
        ]);

        AuditLogger::record('user.registered', 'user', $user->id, [], $user->id);

        // Log them in but route to verification — unverified accounts are gated.
        Auth::login($user);
        $this->otp->issue($user);

        return redirect()->route('verify.show');
    }
}
