<?php

namespace App\Services;

use App\Models\RequestDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use TCPDF;
use Throwable;
use ZipArchive;

/**
 * Turns an uploaded Word document into a real PDF, once, before anyone works on it.
 *
 * This exists because a .docx used to be converted TWICE by two different
 * converters that disagreed with each other. The editor ran mammoth.js in the
 * browser and drew the result as ONE tall page of whatever height the text
 * happened to reach; the sealing service ran its own text extraction through
 * TCPDF and got A4 pages, usually three of them. Placements are stored as a
 * fraction of the page they were dropped on, so a stamp at 85% down the
 * browser's single tall page was then drawn 85% down an A4 page — a different
 * place entirely, on a page the notary never saw. Worse, the fractions
 * themselves depended on the screen: the browser page had no fixed aspect
 * ratio, so the same drop on a phone and on a desktop produced different
 * numbers. That is why a Word document came out misaligned while a PDF never
 * did.
 *
 * The cure is to stop converting twice. A Word upload is rendered to a PDF here
 * and that PDF is what the editor shows and what the sealing service imports,
 * so the pages the notary places marks on are the pages the marks land on.
 *
 * The rendition is written next to nothing and owned by nobody: it is a cache,
 * derived entirely from the upload, and deleting it only costs the second or so
 * it takes to make again. The client's original .docx is never touched.
 */
class DocxRenderer
{
    /**
     * Bump this when the conversion itself changes.
     *
     * It is part of the rendition's filename, so an improved converter produces
     * a new file rather than leaving every document already in flight showing
     * the output of the old one.
     */
    private const VERSION = 1;

    /** A4 in mm. The sealing service assumes the same, and must keep doing so. */
    public const PAGE_WIDTH = 210.0;

    public const PAGE_HEIGHT = 297.0;

    private const MARGIN = 18.0;

    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    /** EMU per inch — the unit Word stores picture sizes in. */
    private const EMU_PER_INCH = 914400;

    /** Images pulled out of the archive for one conversion; removed afterwards. */
    private array $scratch = [];

    /** Marks a hard page break found mid-paragraph. */
    private const PAGE_BREAK = "\x00PAGEBREAK\x00";

    /** Is this something we can render? .doc (the old binary format) is not. */
    public function handles(?string $filename): bool
    {
        return strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION)) === 'docx';
    }

    /**
     * The path on the private disk of this document's PDF rendition.
     *
     * Made on first use and kept. Null means the upload is not a .docx, or the
     * conversion failed — callers must carry on without it rather than fail the
     * job, because a notary halfway through sealing cannot do anything about a
     * missing ZipArchive extension.
     */
    public function renditionFor(RequestDocument $document): ?string
    {
        if (! $this->handles($document->original_filename ?? $document->file_url)) {
            return null;
        }

        $target = 'renditions/docx-' . $document->id . '-v' . self::VERSION . '.pdf';

        if (Storage::disk('private')->exists($target)) {
            return $target;
        }

        $source = Storage::disk('private')->path($document->file_url);

        if (! is_file($source)) {
            return null;
        }

        try {
            Storage::disk('private')->put($target, $this->toPdf($source));
        } catch (Throwable $e) {
            Log::warning('Word document could not be rendered to PDF', [
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }

        return $target;
    }

    /** Render a .docx file to PDF bytes. */
    public function toPdf(string $sourcePath): string
    {
        $pdf = new TCPDF('P', 'mm', [self::PAGE_WIDTH, self::PAGE_HEIGHT]);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(true, self::MARGIN);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(15, 23, 42);

        try {
            $html = $this->toHtml($sourcePath);
            $pdf->AddPage();
            $pdf->writeHTML($html, true, false, true, false, '');

            return $pdf->Output('', 'S');
        } finally {
            foreach ($this->scratch as $file) {
                @unlink($file);
            }

            $this->scratch = [];
        }
    }

    /**
     * The document's content as the HTML subset TCPDF understands.
     *
     * Never throws: anything unreadable comes back as a one-line notice, so a
     * broken upload produces a page saying so rather than a failed seal.
     */
    public function htmlFor(string $sourcePath): string
    {
        try {
            return $this->toHtml($sourcePath);
        } catch (Throwable $e) {
            Log::warning('Word document could not be read', [
                'path'  => basename($sourcePath),
                'error' => $e->getMessage(),
            ]);

            return '<p>[The content of this Word document could not be read. '
                . 'It has been sealed with the notary&rsquo;s marks only.]</p>';
        }
    }

    private function toHtml(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The ZipArchive extension is not installed.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The file is not a readable .docx archive.');
        }

        $xml = $zip->getFromName('word/document.xml');

        if ($xml === false) {
            $zip->close();

            throw new RuntimeException('word/document.xml is missing.');
        }

        $images = $this->extractImages($zip);
        $zip->close();

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('word/document.xml could not be parsed.');
        }

        $body = $dom->getElementsByTagNameNS(self::NS_W, 'body')->item(0);

        if (! $body instanceof DOMElement) {
            throw new RuntimeException('The document has no body.');
        }

        $html = $this->blocks($body, $images);

        return trim($html) === '' ? '<p>[This document contains no text.]</p>' : $html;
    }

    /** Paragraphs and tables, in document order. */
    private function blocks(DOMElement $parent, array $images): string
    {
        $html = '';

        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== self::NS_W) {
                continue;
            }

            $html .= match ($node->localName) {
                'p'   => $this->paragraph($node, $images),
                'tbl' => $this->table($node, $images),
                default => '',
            };
        }

        return $html;
    }

    private function paragraph(DOMElement $p, array $images): string
    {
        $inner = $this->runs($p, $images);

        [$tag, $style] = $this->paragraphStyle($p);

        // A hard page break inside a paragraph splits it, rather than being
        // swallowed: Word puts one wherever the author pressed Ctrl+Enter, and
        // those are exactly the page boundaries the notary expects to see.
        $pieces = explode(self::PAGE_BREAK, $inner);
        $html   = '';

        foreach ($pieces as $index => $piece) {
            if ($index > 0) {
                $html .= '<br pagebreak="true"/>';
            }

            if (trim(strip_tags($piece, '<img>')) === '' && $index < count($pieces) - 1) {
                continue;
            }

            // An empty paragraph is a deliberate blank line in Word.
            $html .= '<' . $tag . $style . '>' . ($piece === '' ? '&nbsp;' : $piece) . '</' . $tag . '>';
        }

        return $html;
    }

    /** @return array{0: string,1: string} the tag to use and its style attribute */
    private function paragraphStyle(DOMElement $p): array
    {
        $pPr = $this->child($p, 'pPr');
        $tag = 'p';
        $css = [];

        if ($pPr) {
            $styleName = strtolower($this->attr($this->child($pPr, 'pStyle'), 'val'));

            if (preg_match('/^heading(\d)$/', $styleName, $m)) {
                $tag = 'h' . min(4, (int) $m[1]);
            }

            $align = strtolower($this->attr($this->child($pPr, 'jc'), 'val'));
            $align = match ($align) {
                'center'  => 'center',
                'right'   => 'right',
                'both'    => 'justify',
                'left'    => 'left',
                default   => null,
            };

            if ($align) {
                $css[] = 'text-align:' . $align;
            }

            // Lists arrive as ordinary paragraphs carrying a numbering
            // reference. Indenting them with a bullet is closer to the truth
            // than losing the structure altogether.
            if ($this->child($pPr, 'numPr')) {
                $css[] = 'margin-left:8mm';
            }
        }

        return [$tag, $css === [] ? '' : ' style="' . implode(';', $css) . '"'];
    }

    /** The text of one paragraph, with bold/italic/underline and images kept. */
    private function runs(DOMNode $parent, array $images): string
    {
        $html = '';

        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== self::NS_W) {
                continue;
            }

            $html .= match ($node->localName) {
                'r'         => $this->run($node, $images),
                'hyperlink' => $this->runs($node, $images),
                'ins'       => $this->runs($node, $images),
                'smartTag'  => $this->runs($node, $images),
                default     => '',
            };
        }

        return $html;
    }

    private function run(DOMElement $r, array $images): string
    {
        $text = '';

        foreach ($r->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->namespaceURI !== self::NS_W) {
                continue;
            }

            switch ($node->localName) {
                case 't':
                    $text .= htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    break;

                case 'tab':
                    $text .= '&nbsp;&nbsp;&nbsp;&nbsp;';
                    break;

                case 'br':
                    $text .= strtolower($this->attr($node, 'type')) === 'page'
                        ? self::PAGE_BREAK
                        : '<br/>';
                    break;

                case 'drawing':
                case 'pict':
                    $text .= $this->image($node, $images);
                    break;
            }
        }

        if ($text === '') {
            return '';
        }

        $rPr = $this->child($r, 'rPr');

        if ($rPr) {
            // <w:b/> with no val means on; val="0" or "false" means off.
            if ($this->toggled($rPr, 'b')) {
                $text = '<b>' . $text . '</b>';
            }

            if ($this->toggled($rPr, 'i')) {
                $text = '<i>' . $text . '</i>';
            }

            if ($this->child($rPr, 'u') && strtolower($this->attr($this->child($rPr, 'u'), 'val')) !== 'none') {
                $text = '<u>' . $text . '</u>';
            }
        }

        return $text;
    }

    private function table(DOMElement $tbl, array $images): string
    {
        $html = '<table border="1" cellpadding="3" cellspacing="0">';

        foreach ($tbl->childNodes as $tr) {
            if (! $tr instanceof DOMElement || $tr->namespaceURI !== self::NS_W || $tr->localName !== 'tr') {
                continue;
            }

            $html .= '<tr>';

            foreach ($tr->childNodes as $tc) {
                if (! $tc instanceof DOMElement || $tc->namespaceURI !== self::NS_W || $tc->localName !== 'tc') {
                    continue;
                }

                $span = (int) $this->attr($this->child($this->child($tc, 'tcPr'), 'gridSpan'), 'val');
                $html .= '<td' . ($span > 1 ? ' colspan="' . $span . '"' : '') . '>'
                    . $this->blocks($tc, $images)
                    . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * A picture, at the size Word recorded for it.
     *
     * The size is in EMU; TCPDF wants pixels, which it maps back to page units
     * at 96 dpi. Anything wider than the text column is scaled down, or it would
     * run off the page and take the rest of the line with it.
     */
    private function image(DOMElement $node, array $images): string
    {
        $blips = $node->getElementsByTagNameNS(self::NS_A, 'blip');
        $blip  = $blips->item(0);

        if (! $blip instanceof DOMElement) {
            return '';
        }

        $id = $blip->getAttributeNS(self::NS_R, 'embed');
        $file = $images[$id] ?? null;

        if (! $file) {
            return '';
        }

        $maxPx = (int) round((self::PAGE_WIDTH - 2 * self::MARGIN) / 25.4 * 96);
        $widthPx = $maxPx;
        $heightPx = 0;

        $extent = $node->getElementsByTagNameNS(self::NS_WP, 'extent')->item(0);

        if ($extent instanceof DOMElement) {
            $cx = (float) $extent->getAttribute('cx');
            $cy = (float) $extent->getAttribute('cy');

            if ($cx > 0) {
                $widthPx  = (int) round($cx / self::EMU_PER_INCH * 96);
                $heightPx = $cy > 0 ? (int) round($cy / self::EMU_PER_INCH * 96) : 0;

                if ($widthPx > $maxPx) {
                    $heightPx = $heightPx > 0 ? (int) round($heightPx * $maxPx / $widthPx) : 0;
                    $widthPx  = $maxPx;
                }
            }
        }

        return '<img src="' . htmlspecialchars($file, ENT_QUOTES) . '" width="' . $widthPx . '"'
            . ($heightPx > 0 ? ' height="' . $heightPx . '"' : '') . '/>';
    }

    /**
     * Pull the pictures out of the archive into temporary files.
     *
     * Keyed by relationship id, which is what the drawing refers to. The files
     * are deleted as soon as the PDF is written — TCPDF needs them on disk only
     * while it is embedding them.
     *
     * @return array<string, string> relationship id => temporary file path
     */
    private function extractImages(ZipArchive $zip): array
    {
        $rels = $zip->getFromName('word/_rels/document.xml.rels');

        if ($rels === false) {
            return [];
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($rels, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $found = [];

        foreach ($dom->getElementsByTagName('Relationship') as $rel) {
            if (! str_ends_with($rel->getAttribute('Type'), '/image')) {
                continue;
            }

            $target = ltrim($rel->getAttribute('Target'), '/');

            // External images are a URL, not a file in the archive.
            if ($rel->getAttribute('TargetMode') === 'External' || str_contains($target, '://')) {
                continue;
            }

            $entry = str_starts_with($target, 'word/') ? $target : 'word/' . $target;
            $bytes = $zip->getFromName($entry);

            if ($bytes === false) {
                continue;
            }

            $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

            // TCPDF embeds these; anything else (emf, wmf, svg) it cannot.
            if (! in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)) {
                continue;
            }

            $temp = tempnam(sys_get_temp_dir(), 'nvn_docx_') . '.' . $extension;
            file_put_contents($temp, $bytes);

            $this->scratch[] = $temp;
            $found[$rel->getAttribute('Id')] = $temp;
        }

        return $found;
    }

    /** A Word on/off property: present means on unless it says otherwise. */
    private function toggled(DOMElement $parent, string $name): bool
    {
        $node = $this->child($parent, $name);

        if (! $node) {
            return false;
        }

        $val = strtolower($this->attr($node, 'val'));

        return ! in_array($val, ['0', 'false', 'off'], true);
    }

    private function child(?DOMElement $parent, string $name): ?DOMElement
    {
        if (! $parent) {
            return null;
        }

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::NS_W && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    private function attr(?DOMElement $node, string $name): string
    {
        return $node ? $node->getAttributeNS(self::NS_W, $name) : '';
    }
}
