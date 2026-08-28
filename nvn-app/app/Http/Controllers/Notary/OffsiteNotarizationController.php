<?php

namespace App\Http\Controllers\Notary;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\NotaryProfile;
use App\Models\Payment;
use App\Models\RequestDocument;
use App\Services\OfflinePaymentService;
use App\Services\OffsiteNotarizationService;
use App\Services\PaystackService;
use App\Support\SettlementMethod;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The notary's own desk for work they took on offsite.
 *
 * Everything here belongs to the notary who is signed in: they upload, they
 * pay, they seal, they download. No client ever sees these screens and no
 * marketplace queue lists these jobs — see NotarizationRequest::scopeMarketplace().
 *
 * An admin works the same screens with two differences, because the platform
 * itself takes offsite work: they see every offsite job rather than only their
 * own, and they choose which notary's seal goes on a job they place — their own
 * or a partner's, for a partner who took a job outside and sent it in. They
 * also never see Paystack here. A client who paid the platform directly has
 * already paid; sending an admin to a checkout to move the platform's own money
 * to the platform would cost a card fee and record a fiction. Instead the admin
 * records what the client actually handed over, which is the same offline
 * settlement path the payments screen uses.
 */
class OffsiteNotarizationController extends Controller
{
    public function __construct(
        private OffsiteNotarizationService $offsite,
        private PaystackService $paystack,
    ) {}

    /**
     * Offsite jobs, newest first — this notary's, or all of them for an admin.
     *
     * An admin has to see everybody's: they place jobs under partners' seals and
     * record money against jobs partners started, and neither is possible from a
     * list scoped to their own profile.
     */
    public function index(): View
    {
        $admin   = $this->isAdmin();
        $profile = $this->profile();

        $query = NotarizationRequest::offsite()
            ->withCount('notarizableDocuments')
            ->with(['finalDocuments', 'notary.user:id,full_name'])
            ->latest('id');

        if (! $admin) {
            $query = $profile
                ? $query->where('notary_id', $profile->id)
                : $query->whereRaw('1 = 0');
        }

        return view('notary.offsite.index', [
            'jobs'    => $query->paginate(15),
            'profile' => $profile,
            'isAdmin' => $admin,
            'blocked' => $admin ? $this->offsite->adminBlockedReason() : $this->offsite->blockedReason($profile),
            'fee'     => Settings::offsiteFeeDisplay(),
        ]);
    }

    /** The upload form. */
    public function create(): View|RedirectResponse
    {
        if ($reason = $this->blockedForPlacing()) {
            return redirect()->route('notary.offsite.index')->withErrors(['offsite' => $reason]);
        }

        return view('notary.offsite.create', [
            'feeMinor'  => Settings::offsiteFeeMinor(),
            'fee'       => Settings::offsiteFeeDisplay(),
            'isAdmin'   => $this->isAdmin(),
            // Only an admin picks; a notary's own profile is the only answer.
            'notaries'  => $this->isAdmin() ? $this->offsite->sealableProfiles() : collect(),
            'ownNotary' => $this->profile()?->id,
        ]);
    }

    public function store(Request $http): RedirectResponse
    {
        if ($reason = $this->blockedForPlacing()) {
            return redirect()->route('notary.offsite.index')->withErrors(['offsite' => $reason]);
        }

        $data = $http->validate([
            'described_as' => ['required', 'string', 'max:500'],
            'documents'    => ['required', 'array', 'min:1', 'max:20'],
            'documents.*'  => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:15360'],
        ], [
            'described_as.required' => 'Say in a line what this is, so you can tell it apart later.',
            'documents.required'    => 'Attach at least one document to notarize.',
        ]);

        $profile = $this->placingProfile($http);

        if (! $profile) {
            return back()->withInput()->withErrors([
                'notary_id' => 'Choose the notary whose signature, stamp and seal go on this.',
            ]);
        }

        // Checked again on the chosen profile rather than trusted from the
        // picker: the options were built a page load ago, and an approval can
        // be withdrawn or an asset deleted in between.
        if ($reason = $this->offsite->blockedReason($profile)) {
            return back()->withInput()->withErrors(['notary_id' => $this->isAdmin()
                ? ($profile->user?->full_name ?? 'That notary') . ' cannot be sealed for right now — '
                    . 'their approval or their three marks are no longer both in place. Pick another notary.'
                : $reason]);
        }

        $request = $this->offsite->create(
            $profile,
            Auth::user(),
            $data['described_as'],
            $http->file('documents'),
        );

        return redirect()->route('notary.offsite.show', $request);
    }

    /** The one job: what it costs, what is on it, and what to do next. */
    public function show(NotarizationRequest $request): View
    {
        $this->authorizeOffsiteOwner($request);

        $request->load('notarizableDocuments', 'finalDocuments', 'notary.user');

        return view('notary.offsite.show', [
            'request'  => $request,
            'blocked'  => $this->offsite->blockedReason($request->notary),
            'unitFee'  => NotarizationRequest::money((int) $request->unit_fee_minor, $request->currency ?: 'NGN'),
            'balance'  => $request->balanceMinor(),
            'isAdmin'  => $this->isAdmin(),
            'methods'  => SettlementMethod::OPTIONS,
        ]);
    }

    /** Add another document before paying. */
    public function addDocuments(NotarizationRequest $request, Request $http): RedirectResponse
    {
        $this->authorizeOffsiteOwner($request);
        $this->authorizeUnpaid($request);

        $data = $http->validate([
            'documents'   => ['required', 'array', 'min:1', 'max:20'],
            'documents.*' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:15360'],
        ]);

        $this->offsite->addDocuments($request, $http->file('documents'), Auth::user());

        return redirect()->route('notary.offsite.show', $request)
            ->with('status', count($data['documents']) . ' added. The total has gone up to match.');
    }

    /** Take one off again, while nothing has been paid. */
    public function removeDocument(NotarizationRequest $request, RequestDocument $document): RedirectResponse
    {
        $this->authorizeOffsiteOwner($request);
        $this->authorizeUnpaid($request);

        // Matched inside this request's own set rather than looked up by id, so
        // the URL cannot be turned into a way to delete somebody else's upload.
        abort_unless($document->request_id === $request->id, 404);

        // The last one cannot go: a job with nothing on it is not a job, and
        // billableDocumentCount() floors at one, so it would still be charged.
        abort_if($request->notarizableDocuments()->count() <= 1, 422);

        $this->offsite->removeDocument($request, $document, Auth::user());

        return redirect()->route('notary.offsite.show', $request)
            ->with('status', 'Removed.');
    }

    /**
     * Pay the platform's fee and unlock the editor.
     *
     * A fee of zero is a real setting, not an edge case — the admin may want
     * offsite sealing free for a season — and it must not send anyone to a
     * checkout for ₦0.00, which Paystack refuses anyway.
     */
    public function pay(NotarizationRequest $request): RedirectResponse
    {
        $this->authorizeOffsiteOwner($request);

        // An admin never checks out. The money on their jobs arrived outside
        // Paystack by definition, and record() is where it is written down.
        abort_if($this->isAdmin(), 403);

        if ($reason = $this->offsite->blockedReason($request->notary)) {
            return back()->withErrors(['offsite' => $reason]);
        }

        $amount = $request->balanceMinor();

        if ($amount <= 0) {
            $this->offsite->markPaid($request);

            return redirect()->route('notary.offsite.show', $request)
                ->with('status', 'Unlocked. Place your marks whenever you are ready.');
        }

        $reference = $this->paystack->reference('off');

        $payment = Payment::create([
            'request_id'         => $request->id,
            'user_id'            => Auth::id(),
            'type'               => 'offsite_fee',
            'amount'             => $amount,
            'currency'           => $request->currency ?: 'NGN',
            'paystack_reference' => $reference,
            'status'             => 'pending',
        ]);

        $init = $this->paystack->initializeTransaction(
            email: Auth::user()->email,
            amountMinor: $amount,
            reference: $reference,
            callbackUrl: route('notary.offsite.callback', $request),
            currency: $payment->currency,
            metadata: ['purpose' => 'offsite_fee', 'request_id' => $request->id],
        );

        if (! $init['authorization_url']) {
            return back()->withErrors(['offsite' => 'Could not start payment. Please try again.']);
        }

        return redirect()->away($init['authorization_url']);
    }

    /**
     * Admin only: write down what the client paid, and open the job.
     *
     * The figure asked for here is the real one — what the walk-in actually
     * handed over for the notarization — not the platform's per-document fee.
     * On an admin job there is no partner to pay us: the platform did the
     * collecting, so the whole amount is platform revenue and belongs on the
     * books at its true size. It is written as an 'offsite_fee' payment by
     * OfflinePaymentService, which keeps it out of scopePayable() and out of
     * the payout run — nothing here is owed to anybody.
     *
     * Zero is allowed and means exactly what it says: open the job, record no
     * money. A favour, a re-do, a fee waived.
     */
    public function record(NotarizationRequest $request, Request $http, OfflinePaymentService $offline): RedirectResponse
    {
        $this->authorizeOffsiteOwner($request);
        abort_unless($this->isAdmin(), 403);
        $this->authorizeUnpaid($request);

        if ($reason = $this->offsite->blockedReason($request->notary)) {
            return back()->withErrors(['offsite' => $reason]);
        }

        $data = $http->validate([
            'amount_major' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'method'       => ['required', 'string', 'in:' . implode(',', array_keys(SettlementMethod::OPTIONS))],
            'received_at'  => ['nullable', 'date', 'before_or_equal:now'],
            'reference'    => ['nullable', 'string', 'max:255'],
            'note'         => ['nullable', 'string', 'max:1000'],
        ], [
            'amount_major.required' => 'Say what the client paid. Enter 0 if nothing was charged.',
            'received_at.before_or_equal' => 'Money cannot have arrived in the future.',
        ]);

        $amount = (int) round(((float) $data['amount_major']) * 100);

        if ($amount <= 0) {
            $this->offsite->markPaid($request);

            return redirect()->route('notary.offsite.show', $request)
                ->with('status', 'Opened with nothing recorded. Place the marks whenever you are ready.');
        }

        $payment = $offline->recordRequestFee($request, [
            'amount'      => $amount,
            'method'      => $data['method'],
            'reference'   => $data['reference'] ?? null,
            'note'        => $data['note'] ?? null,
            'received_at' => $data['received_at'] ?? now(),
        ], Auth::id());

        return redirect()->route('notary.offsite.show', $request)->with(
            'status',
            NotarizationRequest::money((int) $payment->amount, $payment->currency)
                . ' recorded as ' . SettlementMethod::label($payment->settlement_method)
                . '. The job is open — place the marks and finalize.',
        );
    }

    /** Paystack redirect-back. Verify for instant feedback; the webhook is authoritative. */
    public function callback(NotarizationRequest $request): RedirectResponse
    {
        $this->authorizeOffsiteOwner($request);

        $reference = request('reference') ?? request('trxref');

        if ($reference) {
            try {
                $data = $this->paystack->verifyTransaction($reference);

                if ($this->paystack->isSuccessful($data)) {
                    $this->offsite->settleReference($reference);
                }
            } catch (\Throwable $e) {
                Log::error('Paystack offsite callback verification failed', [
                    'reference' => $reference,
                    'error'     => $e->getMessage(),
                ]);
                // The webhook will confirm shortly; fall through to the job page.
            }
        }

        return redirect()->route('notary.offsite.show', $request);
    }

    /** Stream one of the notary's own uploads back to them. */
    public function document(NotarizationRequest $request, RequestDocument $document)
    {
        $this->authorizeOffsiteOwner($request);
        abort_unless($document->request_id === $request->id, 404);

        $filename = $document->original_filename ?: 'document';

        return Storage::disk('private')->response($document->file_url, $filename, [
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    private function profile(): ?NotaryProfile
    {
        return Auth::user()->notaryProfile;
    }

    private function isAdmin(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    /**
     * Whether this user can place an offsite job at all, and why not.
     *
     * A notary is asking about themselves. An admin is asking whether there is
     * anyone to seal in the name of, since they place jobs under other people's
     * profiles as well as their own.
     */
    private function blockedForPlacing(): ?string
    {
        return $this->isAdmin()
            ? $this->offsite->adminBlockedReason()
            : $this->offsite->blockedReason($this->profile());
    }

    /**
     * Which notary's marks go on a job being placed.
     *
     * A notary never chooses — it is their own profile or nothing. An admin
     * chooses, defaulting to the platform's own profile, and the id is resolved
     * against the sealable set rather than looked up directly so the form
     * cannot be edited into placing a job under an unapproved notary.
     */
    private function placingProfile(Request $http): ?NotaryProfile
    {
        if (! $this->isAdmin()) {
            return $this->profile();
        }

        $chosen = (int) $http->input('notary_id');

        return $chosen
            ? $this->offsite->sealableProfiles()->firstWhere('id', $chosen)
            : $this->profile();
    }

    /**
     * This job, and this notary.
     *
     * An admin passes for any offsite job: they run the platform's own notary
     * desk, they place work under partners' seals, and they record the money on
     * jobs partners started. A notary sees only their own — the profile check
     * is the whole of it, and there is no other way into these screens.
     */
    private function authorizeOffsiteOwner(NotarizationRequest $request): void
    {
        abort_unless($request->is_offsite, 404);

        if ($this->isAdmin()) {
            return;
        }

        $profile = $this->profile();

        abort_unless($profile && $request->notary_id === $profile->id, 403);
    }

    /** Documents may only move while the fee is still unpaid. */
    private function authorizeUnpaid(NotarizationRequest $request): void
    {
        abort_unless($request->status === RequestStatus::Draft, 422);
    }
}
