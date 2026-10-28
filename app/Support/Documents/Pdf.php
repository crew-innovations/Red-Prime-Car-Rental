<?php

namespace App\Support\Documents;

use Illuminate\Http\Response;

/**
 * Renders a Blade view to a PDF with dompdf (28 Oct, Priority 7). If the PDF
 * library is not installed yet on a server, the same page is shown as HTML
 * with a print button, so the link never breaks.
 */
class Pdf
{
    public static function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    public static function render(string $view, array $data): string
    {
        $html = view($view, $data + ['asPdf' => true])->render();

        $tmp = storage_path('app/dompdf');
        $fonts = storage_path('fonts');
        foreach ([$tmp, $fonts] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        $options = new \Dompdf\Options([
            'isRemoteEnabled' => false,          // never fetch anything from the internet
            'isHtml5ParserEnabled' => true,
            'chroot' => [public_path(), resource_path()],
            'tempDir' => $tmp,
            'fontDir' => $fonts,
            'fontCache' => $fonts,
            'defaultFont' => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
            'dpi' => 96,
        ]);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Page numbers in the footer of every page.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.45, 0.45, 0.45]);

        return $dompdf->output();
    }

    public static function response(string $view, array $data, string $filename, bool $download = false): Response
    {
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store'];

        if (! self::available()) {
            return response(view($view, $data + ['asPdf' => false])->render(), 200, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return response(self::render($view, $data), 200, $headers + [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.str_replace('"', '', $filename).'"',
        ]);
    }

    /** Logo as a data URI, so the PDF needs no file or network access. */
    public static function logo(): ?string
    {
        foreach (['images/logo-mark-light.png', 'images/logo.png'] as $file) {
            $path = public_path($file);
            if (is_file($path)) {
                return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        return null;
    }
}
