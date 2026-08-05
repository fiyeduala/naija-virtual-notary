<?php

namespace App\Http\Controllers\Session;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\SessionParticipant;
use App\Models\VerificationRecord;
use App\Services\Video\VideoProvider;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function __construct(private VideoProvider $video) {}

    /** The verification call screen (video). Not recorded. */
    public function join(NotarizationRequest $request): View|RedirectResponse
    {
        $this->authorizeParticipant($request);
        $session = $request->session;
        abort_unless($session, 404);

        $user = Auth::user();
        $role = $this->roleFor($request, $user);

        $credentials = $this->video->joinCredentials($session, $user, $role);

        // Record join + mark session in progress
        SessionParticipant::updateOrCreate(
            ['session_id' => $session->id, 'user_id' => $user->id],
            ['role' => $role, 'joined_at' => now()],
        );

        if ($session->status === 'scheduled') {
            $session->update(['status' => 'in_progress', 'actual_start_at' => now()]);
            if ($request->status === RequestStatus::Accepted) {
                $request->update(['status' => RequestStatus::InVerification]);
            }
            AuditLogger::record('session.started', 'session', $session->id, [], $user->id);
        }

        AuditLogger::record('session.participant_joined', 'session', $session->id, ['role' => $role], $user->id);

        return view('session.join', [
            'request'     => $request,
            'session'     => $session,
            'credentials' => $credentials,
            'role'        => $role,
            'isNotary'    => in_array($role, ['notary', 'admin'], true),
        ]);
    }

    /**
     * Notary skips the live call entirely — marks identity verified via uploaded ID
     * without entering the video session. Redirects straight to the notarize editor.
     */
    public function skipCall(NotarizationRequest $request, Request $http): RedirectResponse
    {
        $this->authorizeNotarySide($request);

        $session = $request->session;
        abort_unless($session, 404);

        $idDoc = $request->documents()->where('file_type', 'identification')->first();

        VerificationRecord::updateOrCreate(
            ['session_id' => $session->id],
            [
                'notary_id'      => Auth::id(),
                'client_id'      => $request->client_id,
                'id_document_id' => $idDoc?->id,
                'method'         => 'uploaded_id',
                'verified_at'    => now(),
                'ip_address'     => $http->ip(),
            ],
        );

        if (! $session->identity_verified) {
            $session->update([
                'verification_method' => 'uploaded_id',
                'identity_verified'   => true,
                'status'              => 'in_progress',
                'actual_start_at'     => $session->actual_start_at ?? now(),
            ]);

            if ($request->status === \App\Enums\RequestStatus::Accepted) {
                $request->update(['status' => \App\Enums\RequestStatus::InVerification]);
            }
        }

        AuditLogger::record('session.identity_verified', 'session', $session->id, [
            'method'    => 'uploaded_id',
            'skipped_call' => true,
            'client_id' => $request->client_id,
        ], Auth::id());

        return redirect()->route('session.notarize', $request)
            ->with('status', 'Identity verified via uploaded ID. You can now notarize the document.');
    }

    /** The notary confirms identity — writes the verification record (evidence). */
    public function verifyIdentity(NotarizationRequest $request, Request $http): RedirectResponse
    {
        $this->authorizeNotarySide($request);

        $validated = $http->validate([
            'method' => ['required', 'in:live_visual,uploaded_id'],
        ]);

        $session = $request->session;
        $idDoc = $request->documents()->where('file_type', 'identification')->first();

        VerificationRecord::updateOrCreate(
            ['session_id' => $session->id],
            [
                'notary_id'      => Auth::id(),
                'client_id'      => $request->client_id,
                'id_document_id' => $idDoc?->id,
                'method'         => $validated['method'],
                'verified_at'    => now(),
                'ip_address'     => $http->ip(),
            ],
        );

        $session->update([
            'verification_method' => $validated['method'],
            'identity_verified'   => true,
        ]);

        AuditLogger::record('session.identity_verified', 'session', $session->id, [
            'method'    => $validated['method'],
            'client_id' => $request->client_id,
        ], Auth::id());

        return redirect()->route('session.notarize', $request)
            ->with('status', 'Identity verified. You can now notarize the document.');
    }

    private function roleFor(NotarizationRequest $request, $user): string
    {
        if ($user->id === $request->client_id) {
            return 'client';
        }
        if ($user->isAdmin() || $request->handled_by === $user->id) {
            return 'admin';
        }

        return 'notary';
    }

    private function authorizeParticipant(NotarizationRequest $request): void
    {
        $user = Auth::user();
        $allowed = $user->id === $request->client_id
            || $user->id === $request->notary?->user_id
            || $user->isAdmin()
            || $user->id === $request->handled_by;

        abort_unless($allowed, 403);
    }

    private function authorizeNotarySide(NotarizationRequest $request): void
    {
        $user = Auth::user();
        $allowed = $user->id === $request->notary?->user_id
            || $user->isAdmin()
            || $user->id === $request->handled_by;

        abort_unless($allowed, 403);
    }
}
