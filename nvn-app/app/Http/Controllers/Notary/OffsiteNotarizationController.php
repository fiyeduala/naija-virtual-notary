<?php

namespace App\Http\Controllers\Notary;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\NotaryProfile;
use App\Models\Payment;
use App\Models\RequestDocument;
use App\Services\OffsiteNotarizationService;
use App\Services\PaystackService;
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
 */
class OffsiteNotarizationController extends Controller
{
    public function __construct(
        private OffsiteNotarizationService $offsite,
        private PaystackService $paystack,
    ) {}

    /** Every offsite job this notary has started, newest first. */
    public function index(): View
    {
        $profile = $this->profile();

        $jobs = $profile
            ? NotarizationRequest::offsite()
                ->where('notary_id', $profile->id)
                ->withCount('notarizableDocuments')
                ->with('finalDocuments')
                ->latest('id')
                ->paginate(15)
            : NotarizationRequest::whereRaw('1 = 0')->paginate(15);

        return view('notary.offsite.index', [
            'jobs'    => $jobs,
            'profile' => $profile,
            'blocked' => $this->offsite->blockedReason($profile),
            'fee'     => Settings::offsiteFeeDisplay(),
        ]);
    }

    /** The upload form. */
    public function create(): View|RedirectResponse
    {
        $profile = $this->profile();

        if ($reason = $this->offsite->blockedReason($profile)) {
            return redirect()->route('notary.offsite.index')->withErrors(['offsite' => $reason]);
        }

        return view('notary.offsite.create', [
            'feeMinor' => Settings::offsiteFeeMinor(),
            'fee'      => Settings::offsiteFeeDisplay(),
        ]);
    }

    public function store(Request $http): RedirectResponse
    {
        $profile = $this->profile();

        if ($reason = $this->offsite->blockedReason($profile)) {
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

    /**
     * This job, and this notary.
     *
     * Admins pass because they run the platform's own notary desk and hold a
     * profile of their own; the profile check below is what actually decides
     * it for them, so an admin sees their own offsite jobs and not everybody's.
     */
    private function authorizeOffsiteOwner(NotarizationRequest $request): void
    {
        abort_unless($request->is_offsite, 404);

        $profile = $this->profile();

        abort_unless($profile && $request->notary_id === $profile->id, 403);
    }

    /** Documents may only move while the fee is still unpaid. */
    private function authorizeUnpaid(NotarizationRequest $request): void
    {
        abort_unless($request->status === RequestStatus::Draft, 422);
    }
}
