<?php

namespace App\Http\Controllers\Client;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\IntakeRequest;
use App\Models\NotarizationRequest;
use App\Models\RequestDocument;
use App\Notifications\Admin\RequestAwaitingPaymentNotification;
use App\Support\AdminAlert;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ClientRequestController extends Controller
{
    /** Step 1 — intake form. */
    public function create(): View
    {
        $user = Auth::user();
        [$first, $last] = $this->splitName($user->full_name);

        return view('client.request.intake', [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $user->email,
            'phone'      => $user->phone,
        ]);
    }

    /** Store intake → create draft request + documents → go to marketplace. */
    public function store(IntakeRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        $nrequest = DB::transaction(function () use ($user, $request, $data) {
            $nrequest = NotarizationRequest::create([
                'client_id'           => $user->id,
                'status'              => RequestStatus::Draft,
                'document_use'        => $data['document_use'],
                'currency'            => $data['currency'],
                'hard_copy_requested' => (bool) $data['hard_copy'],
                'delivery_address'    => $data['hard_copy'] ? [
                    'street'      => $data['street'] ?? null,
                    'apartment'   => $data['apartment'] ?? null,
                    'city'        => $data['city'] ?? null,
                    'state'       => $data['state'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'country'     => $data['country'] ?? null,
                ] : null,
                'intake_data' => [
                    'first_name' => $data['first_name'],
                    'last_name'  => $data['last_name'],
                    'email'      => $data['email'],
                    'phone'      => $data['phone'],
                ],
            ]);

            // Primary document
            $this->storeDocument($nrequest, $request->file('document'), 'document', $user->id);

            // Identification
            $this->storeDocument($nrequest, $request->file('identification'), 'identification', $user->id);

            // Additional documents (optional)
            foreach ((array) $request->file('additional', []) as $file) {
                $this->storeDocument($nrequest, $file, 'additional', $user->id);
            }

            // Optional in-app signature from canvas (base64 PNG)
            if ($sigData = $request->validated('client_signature')) {
                $png = base64_decode(preg_replace('/^data:image\/png;base64,/', '', $sigData));
                $filename = 'signature_' . $user->id . '_' . time() . '.png';
                $path = 'request-documents/' . $filename;
                \Illuminate\Support\Facades\Storage::disk('private')->put($path, $png);
                $hash = hash('sha256', $png);
                RequestDocument::create([
                    'request_id'        => $nrequest->id,
                    'uploaded_by'       => $user->id,
                    'file_url'          => $path,
                    'original_filename' => $filename,
                    'file_hash_sha256'  => $hash,
                    'file_type'         => 'client_signature',
                ]);
            }

            return $nrequest;
        });

        AuditLogger::record('request.created', 'notarization_request', $nrequest->id, [], $user->id);

        // Documents are in, payment is not. Told to the desk on the phone only —
        // if it clears in the next two minutes nobody wanted an email about it.
        AdminAlert::send(new RequestAwaitingPaymentNotification($nrequest));

        return redirect()->route('client.marketplace.index', ['request' => $nrequest->id]);
    }

    /** Final review screen before payment (Phase 5 takes over from here). */
    public function review(NotarizationRequest $request): View|RedirectResponse
    {
        $this->authorizeOwner($request);
        $request->load('notary.user', 'service', 'session', 'documents');

        if (! $request->notary_id || ! $request->service_id || ! $request->session) {
            return redirect()->route('client.marketplace.index', ['request' => $request->id])
                ->with('status', 'Please choose a notary and a time slot to continue.');
        }

        return view('client.request.review', ['request' => $request]);
    }

    private function storeDocument(NotarizationRequest $req, $file, string $type, int $userId): void
    {
        $path = $file->store('request-documents', 'private');
        $hash = hash_file('sha256', $file->getRealPath());

        RequestDocument::create([
            'request_id'        => $req->id,
            'uploaded_by'       => $userId,
            'file_url'          => $path,
            'original_filename' => $file->getClientOriginalName(),
            'file_hash_sha256'  => $hash,
            'file_type'         => $type,
        ]);
    }

    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function authorizeOwner(NotarizationRequest $request): void
    {
        abort_unless($request->client_id === Auth::id(), 403);
    }
}
