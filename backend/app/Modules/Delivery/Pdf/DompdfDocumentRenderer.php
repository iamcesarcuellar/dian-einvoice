<?php

namespace App\Modules\Delivery\Pdf;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Contracts\View\Factory as ViewFactory;
use RuntimeException;

/**
 * Local adapter of DocumentRenderer: dompdf, a pure PHP library (LGPL-2.1), draws the Blade
 * template delivery::pdf.invoice on US Letter pages. One family, Inter (SIL Open Font License, in
 * resources/fonts), embedded as a subset in three faces, with its tabular figures as the digits (see
 * TabularFigures). The running header with the test mark and the running footer with the CUFE, the
 * vector QR code (on every page, as section 11.7 of annex 1.9 requires) and the legends are fixed
 * elements of the template; the page number and the faint diagonal watermark are drawn on every
 * page by a page script, since dompdf has no CSS for them.
 */
final class DompdfDocumentRenderer implements DocumentRenderer
{
    public const string FAMILY = 'Inter';

    /** Font file => dompdf face: regular, medium (labels) and semibold, used as bold. */
    private const array FACES = [
        'Inter-Regular.ttf' => 'normal',
        'Inter-Medium.ttf' => '500',
        'Inter-SemiBold.ttf' => 'bold',
    ];

    /** Points per millimetre. */
    private const float MM = 72 / 25.4;

    /** Left and right page margin, in millimetres; the template uses the same. */
    private const int MARGIN = 20;

    private const array INK = [0.094, 0.094, 0.106];

    private const array MUTED = [0.443, 0.443, 0.478];

    public function __construct(private readonly ViewFactory $views) {}

    public function render(InvoiceRepresentation $representation): RenderedDocument
    {
        // dompdf raises benign notices and warnings of its own; Laravel would turn them into
        // exceptions, so they are ignored while it draws. The result is still checked: InvoicePdfs
        // refuses anything that is not a PDF of at least one page.
        set_error_handler(static fn (): bool => true, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);

        try {
            return $this->draw($representation);
        } finally {
            restore_error_handler();
        }
    }

    private function draw(InvoiceRepresentation $representation): RenderedDocument
    {
        $base = sys_get_temp_dir().'/dompdf';
        $fonts = $base.'/fonts';

        foreach ([$base, $fonts] as $directory) {
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException("The directory {$directory} could not be created.");
            }
        }

        $dompdf = new Dompdf(new Options([
            'tempDir' => $base,
            'fontDir' => $fonts,
            'fontCache' => $fonts,
            'chroot' => [$base],
            'defaultFont' => self::FAMILY,
            'defaultPaperSize' => 'letter',
            'defaultPaperOrientation' => 'portrait',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
        ]));

        $this->registerFonts($dompdf->getFontMetrics(), $base.'/inter');

        $dompdf->loadHtml($this->views->make('delivery::pdf.invoice', [
            'r' => $representation,
            'qr' => base64_encode(QrSvg::render($representation->qrText)),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $watermark = $representation->watermark;

        $canvas->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $metrics) use ($watermark): void {
            $this->watermark($canvas, $metrics, $watermark);
            $this->pageNumber($canvas, $metrics, $page, $pages);
        });

        foreach (['Title' => 'title', 'Author' => 'author', 'Subject' => 'subject', 'Keywords' => 'keywords'] as $label => $key) {
            $dompdf->addInfo($label, $representation->metadata[$key]);
        }

        $dompdf->addInfo('Creator', 'dian-einvoice');

        return new RenderedDocument((string) $dompdf->output(), $canvas->get_page_count());
    }

    /**
     * Registers the three faces of Inter with dompdf, from the copies with tabular figures, unless
     * this container registered them before (dompdf keeps them in its font directory).
     */
    private function registerFonts(FontMetrics $metrics, string $copies): void
    {
        $family = $metrics->getFontFamilies()[mb_strtolower(self::FAMILY)] ?? [];

        foreach (self::FACES as $file => $face) {
            $registered = is_array($family) ? $family[$face] ?? null : null;

            if (is_string($registered) && is_file($registered.'.ttf') && is_file($registered.'.ufm')) {
                continue;
            }

            $copy = TabularFigures::copy(resource_path('fonts/inter/'.$file), $copies);

            if (! $metrics->registerFont(['family' => self::FAMILY, 'weight' => $face, 'style' => 'normal'], $copy)) {
                throw new RuntimeException("dompdf could not register the font {$file}.");
            }
        }
    }

    /** "TEST DOCUMENT" across the page, at a few percent of opacity. */
    private function watermark(Canvas $canvas, FontMetrics $metrics, string $text): void
    {
        $font = $metrics->getFont(self::FAMILY, 'bold');

        if ($font === null) {
            return;
        }

        $size = 54.0;
        $angle = -35.0;
        $width = $metrics->getTextWidth($text, $font, $size);
        $radians = deg2rad(-$angle);
        $x = $canvas->get_width() / 2 - ($width / 2) * cos($radians);
        $y = $canvas->get_height() / 2 + ($width / 2) * sin($radians);

        $canvas->set_opacity(0.045);
        $canvas->text($x, $y, $text, $font, $size, self::INK, 0.0, 0.0, $angle);
        $canvas->set_opacity(1.0);
    }

    /** "Page 1 of 3" at the bottom right, below the running footer. */
    private function pageNumber(Canvas $canvas, FontMetrics $metrics, int $page, int $pages): void
    {
        $font = $metrics->getFont(self::FAMILY, 'normal');

        if ($font === null) {
            return;
        }

        $size = 6.4;
        $text = (string) __('delivery::pdf.page', ['page' => $page, 'pages' => $pages]);
        $x = $canvas->get_width() - self::MARGIN * self::MM - $metrics->getTextWidth($text, $font, $size);
        $y = $canvas->get_height() - 11 * self::MM;

        $canvas->text($x, $y, $text, $font, $size, self::MUTED);
    }
}
