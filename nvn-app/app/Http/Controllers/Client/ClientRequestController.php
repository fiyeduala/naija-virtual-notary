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
use App\Support\MetaAttribution;
use App\Support\OrganizationReferral;
use App\Support\VisitorCurrency;
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

        // A client in Nigeria is quoted naira and a client abroad dollars,
        // without either having to find the dropdown. The dropdown stays, as
        // the override — see App\Support\VisitorCurrency for why a detected
        // currency is a default and never a verdict.
        $currencies = array_values(array_filter(
            config('nvn.currencies'),
            fn (string $currency) => VisitorCurrency::canCharge($currency),
        ));

        return view('client.request.intake', [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $user->email,
            'phone'      => $user->phone,

            // Only what can actually be collected is offered. A currency in
            // this list that checkout would refuse is a dead end dressed as a
            // choice, so while the dollar valve is shut dollars are not on it.
            'currencies'       => $currencies,
            'currencyDefault'  => VisitorCurrency::chargeable(VisitorCurrency::for(request())),
            'detectedAbroad'   => VisitorCurrency::detect(request()) === 'USD',
        ]);
    }

    /** Store intake → create draft request + documents → go to marketplace. */
    public function store(IntakeRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        // Their choice outranks the header from here on, for this visit and
        // for any further request they start in it.
        VisitorCurrency::remember($request, $data['currency']);

        // One currency per request, always. NotarizationRequest::amountPaidMinor()
        // sums payment amounts with no currency filter — correctly, because a
        // request is settled in one currency — so a row quoted in dollars and
        // part-paid in naira would have kobo added to cents and report a
        // balance that is arithmetic nonsense. A category correction asks for
        // a difference as a second payment, which makes that reachable rather
        // than theoretical. So the currency a request is created in is one we
        // can actually collect, and nothing is converted at a rate nobody set.
        $currency = VisitorCurrency::chargeable($data['currency']);

        // The same reasoning as the advert attribution below, and the same one
        // moment to do it in. The rate is frozen alongside the body rather
        // than read later, so renegotiating tomorrow cannot change what was
        // earned on a job somebody is halfway through paying for.
        $organization = OrganizationReferral::forRequest($user, $request);

        $nrequest = DB::transaction(function () use ($user, $request, $data, $organization, $currency) {
            $nrequest = NotarizationRequest::create([
                'client_id'           => $user->id,
                'status'              => RequestStatus::Draft,
                'document_use'        => $data['document_use'],
                'currency'            => $currency,
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
                // The only moment an ad click can be recorded. Payment clears
                // in a webhook or, for a bank transfer, in an admin's hands
                // days later, and neither has a browser to read cookies from.
                // Empty for everyone who did not arrive on an advert, which is
                // most people. See App\Support\MetaAttribution.
                'attribution' => MetaAttribution::capture($request) ?: null,

                // Null for ordinary work, which is almost all of it.
                'organization_id'              => $organization?->id,
                'organization_commission_rate' => $organization?->commission_rate,
                'organization_referred_at'     => $organization ? now() : null,
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
