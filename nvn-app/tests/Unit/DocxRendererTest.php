<?php

namespace Tests\Unit;

use App\Services\DocxRenderer;
use setasign\Fpdi\Tcpdf\Fpdi;
use Tests\TestCase;
use ZipArchive;

/**
 * A Word upload has to become a PDF before a notary places anything on it.
 *
 * The misalignment this guards against: a .docx used to be converted once in
 * the browser (one tall page, whatever height the text reached) and again on
 * the server (A4 pages, usually three). Placements are a fraction of their
 * page, so a mark dropped at the foot of the browser's single page was drawn
 * near the foot of an A4 page the notary had never seen — and because the
 * browser page had no fixed proportions, a phone and a desktop recorded the
 * same drop as two different points.
 */
class DocxRendererTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/nvn_docx_test_' . getmypid();

        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_it_only_claims_docx(): void
    {
        $renderer = new DocxRenderer();

        $this->assertTrue($renderer->handles('deed.docx'));
        $this->assertTrue($renderer->handles('DEED.DOCX'));
        $this->assertFalse($renderer->handles('deed.doc'), '.doc is a different, unreadable format');
        $this->assertFalse($renderer->handles('deed.pdf'));
        $this->assertFalse($renderer->handles(null));
    }

    public function test_it_keeps_the_formatting_that_carries_meaning(): void
    {
        $html = (new DocxRenderer())->htmlFor($this->makeDocx());

        $this->assertStringContainsString('<h1', $html, 'a heading is a heading');
        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('<b>BETWEEN</b>', $html);
        $this->assertStringContainsString('<i>(the Assignor)</i>', $html);
        $this->assertSame(4, substr_count($html, '<td>'), 'the table survives as a table');
        $this->assertStringContainsString('pagebreak="true"', $html, "the author's own page break is kept");
    }

    public function test_an_ampersand_is_escaped_exactly_once(): void
    {
        $html = (new DocxRenderer())->htmlFor($this->makeDocx());

        $this->assertStringContainsString('Adaeze &amp; Sons', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_an_unreadable_file_becomes_a_notice_rather_than_an_exception(): void
    {
        $broken = $this->dir . '/broken.docx';
        file_put_contents($broken, 'this is not a zip archive');

        $html = (new DocxRenderer())->htmlFor($broken);

        $this->assertStringContainsString('could not be read', $html);
    }

    public function test_the_rendition_is_a4_pages_that_fpdi_can_import(): void
    {
        $pdf = $this->dir . '/rendition.pdf';
        file_put_contents($pdf, (new DocxRenderer())->toPdf($this->makeDocx()));

        $probe = new Fpdi();
        $pages = $probe->setSourceFile($pdf);

        $this->assertGreaterThan(1, $pages, 'the sample runs past a single page');

        $size = $probe->getTemplateSize($probe->importPage(1));

        $this->assertSame(210, (int) round($size['width']));
        $this->assertSame(297, (int) round($size['height']));
    }

    /**
     * The mechanism behind the original bug, pinned down.
     *
     * TCPDF draws on whichever page it is sitting on, and writeHTML leaves it
     * on the last page it produced — so marks written straight afterwards all
     * landed on the final page. PdfNotarizationService now names the page
     * before each group; if this ever stops being true, that code is wrong.
     */
    public function test_tcpdf_draws_on_the_page_it_was_last_told_about(): void
    {
        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->AddPage();
        $pdf->writeHTML(str_repeat('<p>Filler that pushes this past one page.</p>', 90), true, false, true, false, '');

        $this->assertGreaterThan(1, $pdf->getNumPages());
        $this->assertSame($pdf->getNumPages(), $pdf->getPage(), 'writeHTML ends on the last page');

        $pdf->setPage(1);

        $this->assertSame(1, $pdf->getPage());
    }

    /** A small Word document: heading, styled runs, a table, and a page break. */
    private function makeDocx(): string
    {
        $body = '<w:p><w:pPr><w:pStyle w:val="Heading1"/><w:jc w:val="center"/></w:pPr>'
            . '<w:r><w:t>DEED OF ASSIGNMENT</w:t></w:r></w:p>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>BETWEEN</w:t></w:r>'
            . '<w:r><w:t xml:space="preserve"> Adaeze &amp; Sons </w:t></w:r>'
            . '<w:r><w:rPr><w:i/></w:rPr><w:t>(the Assignor)</w:t></w:r></w:p>'
            . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Party</w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:p><w:r><w:t>Capacity</w:t></w:r></w:p></w:tc></w:tr>'
            . '<w:tr><w:tc><w:p><w:r><w:t>Adaeze &amp; Sons</w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:p><w:r><w:t>Assignor</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';

        for ($i = 1; $i <= 60; $i++) {
            $body .= '<w:p><w:r><w:t>Clause ' . $i . '. The land described in the schedule hereto shall '
                . 'pass to the Assignee free of encumbrance.</w:t></w:r></w:p>';
        }

        $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
            . '<w:p><w:r><w:t>IN WITNESS WHEREOF the parties have set their hands.</w:t></w:r></w:p>';

        $path = $this->dir . '/sample.docx';
        @unlink($path);

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '</Types>',
        );
        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Target="word/document.xml" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"/>'
            . '</Relationships>',
        );
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '</w:body></w:document>',
        );
        $zip->close();

        return $path;
    }
}
