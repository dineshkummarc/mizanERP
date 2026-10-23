<?php

namespace Tests\Feature;

use App\Support\Pdf\NodeRuntime;
use App\Support\Pdf\Pdf;
use Tests\TestCase;

/**
 * PDFs render through App\Support\Pdf, which picks an engine at runtime:
 * Browsershot (headless Chrome) where Node is installed, Dompdf (pure PHP)
 * where it is not. Templates ship Dompdf-only CSS overrides because Dompdf
 * supports no flexbox, grid, or JavaScript.
 */
class PdfDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        NodeRuntime::flush();
    }

    protected function tearDown(): void
    {
        NodeRuntime::flush();

        parent::tearDown();
    }

    public function test_an_explicit_driver_wins_over_probing(): void
    {
        config(['pdf.driver' => 'dompdf', 'services.node.binary' => '/usr/bin/node']);

        $this->assertSame('dompdf', Pdf::view('pdfs.mpr-report')->driver());

        config(['pdf.driver' => 'browsershot', 'services.node.binary' => '/nope/node']);
        NodeRuntime::flush();

        $this->assertSame('browsershot', Pdf::view('pdfs.mpr-report')->driver());
    }

    public function test_auto_falls_back_to_dompdf_when_node_is_missing(): void
    {
        config(['pdf.driver' => 'auto', 'services.node.binary' => '/definitely/not/here/node']);

        $this->assertFalse(NodeRuntime::isAvailable());
        $this->assertSame('dompdf', Pdf::view('pdfs.mpr-report')->driver());
    }

    /** Node on its own is not an engine: a runner with Node and no puppeteer must fall back too. */
    public function test_auto_falls_back_to_dompdf_when_node_has_no_puppeteer(): void
    {
        config(['pdf.driver' => 'auto', 'services.node.binary' => $this->fakeNode(withPuppeteer: false)]);

        $this->assertFalse(NodeRuntime::isAvailable());
        $this->assertSame('dompdf', Pdf::view('pdfs.mpr-report')->driver());

        config(['services.node.binary' => $this->fakeNode(withPuppeteer: true)]);
        NodeRuntime::flush();

        $this->assertTrue(NodeRuntime::isAvailable());
        $this->assertSame('browsershot', Pdf::view('pdfs.mpr-report')->driver());
    }

    /** A stand-in for node: answers --version, and resolves puppeteer only when told to. */
    private function fakeNode(bool $withPuppeteer): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fake-node-');
        file_put_contents($path, "#!/bin/sh\n".'[ "$1" = "--version" ] && exit 0'."\n".($withPuppeteer ? 'exit 0' : 'exit 1')."\n");
        chmod($path, 0755);

        return $path;
    }

    public function test_the_node_probe_is_memoised(): void
    {
        config(['services.node.binary' => '/definitely/not/here/node']);

        $this->assertFalse(NodeRuntime::isAvailable());

        // A later config change is not picked up until the cache is flushed —
        // the probe shells out, so it must not run per PDF.
        config(['services.node.binary' => $this->fakeNode(withPuppeteer: true)]);
        $this->assertFalse(NodeRuntime::isAvailable());

        NodeRuntime::flush();
        $this->assertTrue(NodeRuntime::isAvailable());
    }

    public function test_dompdf_renders_a_pdf_without_node(): void
    {
        config(['pdf.driver' => 'dompdf']);

        $raw = Pdf::view('pdfs.mpr-report', $this->mprData())->format('a4')->raw();

        $this->assertStringStartsWith('%PDF-', $raw);
        $this->assertGreaterThan(1000, strlen($raw));
    }

    public function test_dompdf_saves_to_disk_and_creates_missing_directories(): void
    {
        config(['pdf.driver' => 'dompdf']);

        $path = storage_path('framework/testing/pdf-driver/nested/report.pdf');
        @unlink($path);

        Pdf::view('pdfs.mpr-report', $this->mprData())->save($path);

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($path));

        unlink($path);
    }

    /**
     * The MPR sheet gets its styling from the Tailwind browser CDN, which is a
     * <script> — inert under Dompdf. Its shim must therefore be present.
     */
    public function test_the_dompdf_shim_loads_only_for_dompdf(): void
    {
        config(['pdf.driver' => 'dompdf']);
        $dompdfHtml = Pdf::view('pdfs.mpr-report', $this->mprData())->html();

        $this->assertStringContainsString('.grid-cols-2', $dompdfHtml);
        $this->assertStringContainsString('.space-y-6', $dompdfHtml);

        config(['pdf.driver' => 'browsershot']);
        $chromeHtml = Pdf::view('pdfs.mpr-report', $this->mprData())->html();

        $this->assertStringNotContainsString('.grid-cols-2', $chromeHtml);
    }

    /** Every template with a Dompdf override must actually include it. */
    public function test_templates_with_overrides_wire_them_up(): void
    {
        $pairs = [
            'resources/views/pdfs/payslip.blade.php' => 'pdfs.partials.dompdf-payslip',
            'resources/views/pdfs/employee.blade.php' => 'pdfs.partials.dompdf-employee',
            'resources/views/pdfs/invoice.blade.php' => 'pdfs.partials.dompdf-invoice',
            'resources/views/pdfs/mpr-report.blade.php' => 'pdfs.partials.dompdf-mpr-report',
            'resources/views/reports/layout.blade.php' => 'pdfs.partials.dompdf-reports',
        ];

        foreach ($pairs as $template => $partial) {
            $source = (string) file_get_contents(base_path($template));

            $this->assertStringContainsString($partial, $source, "{$template} does not include its Dompdf overrides");
            $this->assertStringContainsString("(\$pdfEngine ?? null) === 'dompdf'", $source, "{$template} includes its overrides unconditionally");
            $this->assertTrue(view()->exists($partial), "{$partial} is missing");
        }
    }

    /** @return array<string, mixed> */
    protected function mprData(): array
    {
        return [
            'mode' => 'single',
            'reportFields' => ['User Name' => 'Ada Lovelace', 'MPR Date' => '2026-07-01', 'Progress' => "line one\nline two"],
            'contentLabels' => [1 => 'Progress'],
        ];
    }
}
