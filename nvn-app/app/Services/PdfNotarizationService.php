<?php

namespace App\Services;

use App\Exceptions\DocumentNotImportableException;
use App\Models\NotarizationRequest;
use App\Models\RequestDocument;
use App\Support\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Assembles the final notarized PDFs.
 *
 * Imports each of the client's uploaded documents, overlays the notary's
 * placements (signature / stamp / seal images and free text) at the coordinates
 * recorded by the editor, embeds notarization metadata, writes the result to
 * private storage, and computes a SHA-256 hash for the evidence trail.
 *
 * One sealed PDF per uploaded document, never a merged file: each document is
 * its own notarial act, is charged as one, and will be presented to a different
 * bank, registry or embassy from the others. Each output records the upload it
 * came from in source_document_id, which is what lets a single document be
 * re-sealed later without disturbing the finished versions of its siblings.
 *
 * Coordinates are stored NORMALIZED (0..1) relative to each page's width/height,
 * so they map correctly regardless of the PDF's actual point dimensions.
 *
 * Requires: composer require setasign/fpdi tecnickcom/tcpdf
 */
class PdfNotarizationService
{
    /** Rewritten copies made to get a modern PDF open; deleted before we return. */
    private array $scratch = [];

    public function __construct(private PdfNormalizer $normalizer) {}

    /**
     * Seal every document on the request.
     *
     * All or nothing: if the third document cannot be imported, the first two
     * are rolled back with it. A half-sealed request would otherwise show the
     * client a "your documents are ready" download containing some of what they
     * paid for, and there would be nothing on the record to say which.
     *
     * @return \Illuminate\Support\Collection<int, RequestDocument> in upload order
     */
    public function generate(NotarizationRequest $request): Collection
    {
        $request->loadMissing(['notarizableDocuments', 'notary.user', 'service', 'session.verificationRecord']);

        $sources = $request->notarizableDocuments;
        abort_unless($sources->isNotEmpty(), 422, 'No source document to notarize.');

        try {
            return DB::transaction(function () use ($request, $sources) {
                // Seals produced before documents were sealed one by one have no
                // source recorded, so the per-document supersession below cannot
                // see them. They were the whole request's single output, and
                // this run replaces them — leave them active and a re-sealed
                // request would offer the client the old file alongside the new.
                RequestDocument::where('request_id', $request->id)
                    ->where('is_final_notarized', true)
                    ->whereNull('source_document_id')
                    ->update(['is_final_notarized' => false]);

                $finals = $sources->map(fn (RequestDocument $source) => $this->build($request, $source));

                AuditLogger::record('document.notarized', 'notarization_request', $request->id, [
                    'documents' => $finals->map(fn (RequestDocument $d) => [
                        'document_id' => $d->id,
                        'source_id'   => $d->source_document_id,
                        'hash'        => $d->file_hash_sha256,
                    ])->all(),
                ]);

                return $finals;
            });
        } finally {
            foreach ($this->scratch as $file) {
                @unlink($file);
            }

            $this->scratch = [];
        }
    }

    /** Seal one uploaded document and record the result. */
    private function build(NotarizationRequest $request, RequestDocument $source): RequestDocument
    {
        $sourcePath = Storage::disk('private')->path($source->file_url);
        $ext        = strtolower(pathinfo($source->original_filename ?? $source->file_url, PATHINFO_EXTENSION));

        // Placements for this document, grouped by page
        $placements = \App\Models\DocumentPlacement::where('document_id', $source->id)
            ->orderBy('page')
            ->get()
            ->groupBy('page');

        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            // ── Image source: create a PDF page sized to the image ──────────
            [$imgW, $imgH] = @getimagesize($sourcePath) ?: [1, 1];
            $pageW = 210.0; // A4 width in mm
            $pageH = ($imgH / max($imgW, 1)) * $pageW;
            $pdf->AddPage('P', [$pageW, $pageH]);
            $pdf->Image($sourcePath, 0, 0, $pageW, $pageH, strtoupper($ext === 'jpg' ? 'jpeg' : $ext));

            foreach ($placements->get(1, []) as $placement) {
                $this->renderPlacement($pdf, $placement, $pageW, $pageH);
            }
        } elseif (in_array($ext, ['docx', 'doc'])) {
            // ── Word document: extract text via ZipArchive (DOCX) or raw (DOC)
            $pageW = 210.0;
            $pageH = 297.0;
            $pdf->AddPage('P', [$pageW, $pageH]);
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->SetTextColor(15, 23, 42);
            $html = $ext === 'docx' ? $this->docxToHtml($sourcePath) : '<p>[DOC content — finalized with placements only]</p>';
            $pdf->writeHTML($html, true, false, true, false, '');

            foreach ($placements->get(1, []) as $placement) {
                $this->renderPlacement($pdf, $placement, $pageW, $pageH);
            }
        } else {
            // ── PDF source: import pages via FPDI ───────────────────────────
            $pageCount = $pdf->setSourceFile($this->importable($sourcePath));

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $tplId = $pdf->importPage($pageNo);
                $size  = $pdf->getTemplateSize($tplId);

                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);

                $pageW = $size['width'];
                $pageH = $size['height'];

                foreach ($placements->get($pageNo, []) as $placement) {
                    $this->renderPlacement($pdf, $placement, $pageW, $pageH);
                }
            }
        }

        // Embed metadata
        $notaryName = $request->notary?->user?->full_name ?? 'Naija Virtual Notary';
        $pdf->SetTitle('Notarized · ' . $request->reference);
        $pdf->SetAuthor($notaryName);
        $pdf->SetSubject('Notarized via Naija Virtual Notary on ' . now()->toDateTimeString());

        // Write to a temp file, then store on the private disk
        $tmp = tempnam(sys_get_temp_dir(), 'nvn_sealed_') . '.pdf';
        $pdf->Output($tmp, 'F');

        // The source id is in the path as well as the timestamp: two documents
        // on the same request are sealed inside the same second, so the clock
        // alone would have them overwrite each other.
        $storedPath = 'notarized/' . $request->reference . '-' . $source->id . '-' . now()->format('YmdHis') . '.pdf';
        Storage::disk('private')->put($storedPath, file_get_contents($tmp));
        $hash = hash_file('sha256', $tmp);
        @unlink($tmp);

        // Supersede only the previous seal of THIS document. Its siblings are
        // finished work in their own right and re-sealing one must not retire
        // the others — which is exactly what the old request-wide update did.
        RequestDocument::where('request_id', $request->id)
            ->where('is_final_notarized', true)
            ->where('source_document_id', $source->id)
            ->update(['is_final_notarized' => false]);

        return RequestDocument::create([
            'request_id'         => $request->id,
            'uploaded_by'        => auth()->id(),
            'source_document_id' => $source->id,
            'file_url'           => $storedPath,
            'original_filename'  => $this->sealedName($request, $source),
            'file_hash_sha256'   => $hash,
            'file_type'          => 'final_notarized',
            'is_final_notarized' => true,
        ]);
    }

    /**
     * What the client sees when the sealed file lands in their downloads.
     *
     * Carries the original name through, because someone who sent three
     * documents needs to tell the sealed deed from the sealed affidavit
     * without opening both.
     */
    private function sealedName(NotarizationRequest $request, RequestDocument $source): string
    {
        $base = Str::slug(pathinfo($source->original_filename ?? '', PATHINFO_FILENAME));

        return $request->reference . '-' . ($base ?: 'document-' . $source->id) . '-notarized.pdf';
    }

    /**
     * A path to this PDF that FPDI will actually open.
     *
     * The bundled parser handles PDF 1.4. Word, Google Docs and Acrobat all
     * write 1.5 or later, where the cross-reference table is a compressed
     * stream — so the common case is a document that is in no way unusual and
     * still cannot be imported. Where the server can rewrite it we do, silently;
     * where it cannot we refuse with something a notary can act on, rather than
     * letting FPDI's own message reach the screen.
     *
     * The probe is a separate throwaway Fpdi on purpose: a failed
     * setSourceFile() leaves the parser half-initialised, and reusing that
     * instance for the real render invites a second, stranger failure.
     */
    private function importable(string $path): string
    {
        if ($this->canImport($path)) {
            return $path;
        }

        $rewritten = $this->normalizer->normalize($path);

        if ($rewritten === null) {
            throw new DocumentNotImportableException();
        }

        $this->scratch[] = $rewritten;

        if (! $this->canImport($rewritten)) {
            throw new DocumentNotImportableException();
        }

        Log::info('Sealed a PDF that needed rewriting first', [
            'tool' => $this->normalizer->tool(),
        ]);

        return $rewritten;
    }

    private function canImport(string $path): bool
    {
        try {
            (new Fpdi())->setSourceFile($path);

            return true;
        } catch (FpdiException) {
            return false;
        }
    }

    /** Extract DOCX content as basic HTML using ZipArchive (no PhpWord needed). */
    private function docxToHtml(string $path): string
    {
        if (! class_exists('ZipArchive')) {
            return '<p>[ZipArchive unavailable — document content could not be extracted]</p>';
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '<p>[Could not open DOCX file]</p>';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! $xml) {
            return '<p>[DOCX document.xml not found]</p>';
        }

        // Insert paragraph breaks and strip XML tags
        $xml  = str_replace(['</w:p>', '</w:tr>'], ['</w:p>' . "\n\n", '</w:tr>' . "\n"], $xml);
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $lines = array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== '');
        $html  = '';
        foreach ($lines as $line) {
            $html .= '<p>' . htmlspecialchars($line) . '</p>';
        }

        return $html ?: '<p>[No text content found in document]</p>';
    }

    /**
     * Render a single placement onto the current page.
     *
     * x/y are the normalized TOP-LEFT corner and width/height the normalized size,
     * matching what the browser editor stores (see public/js/notarize-editor.js).
     * Both TCPDF's Image() and SetXY() anchor at the top-left, so they map directly.
     */
    private function renderPlacement(Fpdi $pdf, $placement, float $pageW, float $pageH): void
    {
        // Normalized (0..1) → page units (mm)
        $x = $placement->x * $pageW;
        $y = $placement->y * $pageH;
        $w = ($placement->width ?? 0) * $pageW;
        $h = ($placement->height ?? 0) * $pageH;

        if ($placement->type === 'asset' && $placement->asset_id) {
            $asset = \App\Models\NotaryAsset::find($placement->asset_id);

            if (! $asset || ! $asset->file_url) {
                Log::warning('Notarization placement skipped: asset missing', [
                    'placement_id' => $placement->id,
                    'asset_id'     => $placement->asset_id,
                ]);

                return;
            }

            $imgPath = Storage::disk('private')->path($asset->file_url);
            if (! is_file($imgPath)) {
                Log::warning('Notarization placement skipped: asset file not on disk', [
                    'placement_id' => $placement->id,
                    'asset_id'     => $asset->id,
                    'path'         => $asset->file_url,
                ]);

                return;
            }

            // 'fitbox' keeps the aspect ratio inside the box the notary drew.
            $pdf->Image(
                $imgPath, $x, $y, $w ?: 0, $h ?: 0,
                '', '', '', false, 300, '', false, false, 0, 'CM',
            );
        } elseif ($placement->type === 'text' && $placement->text_value !== null) {
            // The editor scales text with its box, so derive the point size from the
            // box height rather than hard-coding 11pt. 1 mm = 2.83465 pt; the 0.58
            // factor matches the editor's font-size-to-box-height ratio.
            $fontSize = $h > 0
                ? max(6.0, min(48.0, $h * 2.83465 * 0.58))
                : 11.0;

            $pdf->SetFont('helvetica', '', $fontSize);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->SetXY($x, $y);
            $pdf->Cell($w ?: 0, $h ?: 0, $placement->text_value, 0, 0, 'L', false, '', 0, false, 'T', 'M');
        }
    }
}
