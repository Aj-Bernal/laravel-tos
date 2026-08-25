<?php

namespace App\Services\Bloom;

use Smalot\PdfParser\Parser;

/**
 * Extracts plain text from an uploaded lesson file — either a PDF or a
 * photo of a printed page (jpg/png), e.g. scanned with a phone camera.
 *
 * Requires: composer require smalot/pdfparser
 *
 * extractFromPath() reads a PDF's embedded text layer only — for
 * scanned/image-only PDFs it will return an empty/near-empty string.
 * Use extractWithOcrFallback() instead when the source might be a scan
 * or a phone photo; it automatically falls back to the Poppler +
 * Tesseract OCR pipeline (same tools used by the DepEd HR PDF import)
 * when the text layer comes back blank, and skips straight to OCR for
 * image files since there's no embedded text layer to try first. Most
 * typed lesson PDFs / DepEd learning modules exported from Word will
 * work fine with either method.
 */
class PdfTextExtractorService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    public function extractFromPath(string $absolutePath): string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($absolutePath);

        $text = $pdf->getText();

        // Collapse excessive whitespace/newlines from PDF extraction noise
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Same as extractFromPath(), but falls back to the Poppler + Tesseract
     * OCR pipeline (same tools used by the DepEd HR PDF import) when the
     * embedded-text extraction comes back blank/near-empty, i.e. a
     * scanned/image-only lesson PDF — and OCRs directly for image files
     * (jpg/png), e.g. a lesson page photographed with a phone.
     *
     * Call this from TosController instead of extractFromPath() directly.
     */
    public function extractWithOcrFallback(string $absolutePath, int $minChars = 20): string
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            // A phone photo has no embedded text layer to try first —
            // go straight to OCR instead of wastefully attempting a PDF
            // parse that would always come back empty.
            return $this->ocrExtractImage($absolutePath);
        }

        $text = $this->extractFromPath($absolutePath);

        if (strlen($text) >= $minChars) {
            return $text;
        }

        return $this->ocrExtract($absolutePath);
    }

    /**
     * OCRs a single image file directly (no Poppler rasterization step —
     * the input is already an image, unlike a PDF page). Used for lesson
     * files uploaded as jpg/png, e.g. photographed with a phone camera.
     *
     * Note on accuracy: a raw phone photo (skewed angle, shadows, uneven
     * lighting, page curl) OCRs noticeably worse than a flatbed scan or a
     * phone scanner app's cleaned-up output (Adobe Scan, Google Drive
     * scan, iOS Notes scanner all do deskew/contrast correction before
     * exporting). If classification/exam quality looks off for a
     * particular lesson, re-uploading via a scanner app instead of the
     * raw camera roll is the first thing to try.
     */
    public function ocrExtractImage(string $absolutePath): string
    {
        $tesseract = config('services.tesseract.binary_path') ?: 'tesseract';
        $this->assertBinaryIsUsable($tesseract, 'tesseract', 'TESSERACT_PATH');

        $tempDir = storage_path('app/tmp/ocr-' . uniqid());
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $outBase = $tempDir . DIRECTORY_SEPARATOR . 'photo-ocr';

            $cmd = sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($tesseract),
                escapeshellarg($absolutePath),
                escapeshellarg($outBase)
            );
            exec($cmd, $output, $exitCode);

            $outFile = $outBase . '.txt';

            if ($exitCode !== 0 || !file_exists($outFile)) {
                throw new \RuntimeException('tesseract failed on image: ' . implode("\n", $output));
            }

            $text = trim(file_get_contents($outFile));
            $text = preg_replace('/[ \t]+/', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);

            return trim($text);
        } finally {
            $this->cleanupTempDir($tempDir);
        }
    }

    /**
     * Rasterizes each PDF page with Poppler's pdftoppm, then OCRs each
     * page image with Tesseract, concatenating the results.
     *
     * Binary paths are configurable via config/services.php (POPPLER_PDFTOPPM_PATH
     * / TESSERACT_PATH env vars), defaulting to the bare command name so it
     * "just works" as long as the binary is on PATH. We deliberately do NOT
     * guess a hardcoded absolute install path (e.g. a specific
     * "C:\Program Files\..." location) as a fallback: a guessed path that
     * happens to be wrong fails with a confusing raw exec() error deep
     * inside OCR, instead of a clear "here's what to configure" message
     * at the point we can actually give one.
     */
    private function ocrExtract(string $absolutePath): string
    {
        $pdftoppm = config('services.poppler.pdftoppm_path') ?: 'pdftoppm';
        $tesseract = config('services.tesseract.binary_path') ?: 'tesseract';

        $this->assertBinaryIsUsable($pdftoppm, 'pdftoppm', 'POPPLER_PDFTOPPM_PATH');
        $this->assertBinaryIsUsable($tesseract, 'tesseract', 'TESSERACT_PATH');

        $tempDir = storage_path('app/tmp/ocr-' . uniqid());
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $imagePrefix = $tempDir . DIRECTORY_SEPARATOR . 'page';

            // Rasterize each PDF page to page-1.png, page-2.png, ...
            $cmd = sprintf(
                '%s -png -r 300 %s %s 2>&1',
                escapeshellarg($pdftoppm),
                escapeshellarg($absolutePath),
                escapeshellarg($imagePrefix)
            );
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException('pdftoppm failed: ' . implode("\n", $output));
            }

            $pages = glob($imagePrefix . '*.png');
            sort($pages, SORT_NATURAL);

            $textChunks = [];

            foreach ($pages as $imagePath) {
                $outBase = $imagePath . '-ocr';

                $cmd = sprintf(
                    '%s %s %s 2>&1',
                    escapeshellarg($tesseract),
                    escapeshellarg($imagePath),
                    escapeshellarg($outBase)
                );
                exec($cmd, $output, $exitCode);

                $outFile = $outBase . '.txt';
                if ($exitCode === 0 && file_exists($outFile)) {
                    $textChunks[] = trim(file_get_contents($outFile));
                }
            }

            $text = implode("\n\n", array_filter($textChunks));

            $text = preg_replace('/[ \t]+/', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);

            return trim($text);
        } finally {
            $this->cleanupTempDir($tempDir);
        }
    }

    /**
     * Verifies a configured binary is actually runnable before we spend
     * time rasterizing pages, so a bad path fails immediately with a
     * message pointing at exactly which env var to fix, instead of a
     * cryptic "exit code 127 / not recognized" buried in exec() output
     * after work has already started.
     */
    private function assertBinaryIsUsable(string $path, string $label, string $envVar): void
    {
        // If it's a bare command name (no directory separator), rely on
        // the OS `where`/`which`-style resolution built into `exec`'s shell
        // rather than re-implementing PATH lookup ourselves.
        $isBareCommand = !str_contains($path, '/') && !str_contains($path, '\\');

        if ($isBareCommand) {
            $checkCmd = PHP_OS_FAMILY === 'Windows'
                ? sprintf('where %s 2>NUL', escapeshellarg($path))
                : sprintf('command -v %s 2>/dev/null', escapeshellarg($path));
            exec($checkCmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    "OCR fallback needs \"{$label}\" but it isn't on PATH. Either install it, ".
                    "or set {$envVar} in your .env to its full path (e.g. ".
                    "{$envVar}=\"C:\\path\\to\\{$label}.exe\" on Windows)."
                );
            }

            return;
        }

        // An explicit path was configured — check it actually exists
        // rather than letting exec() fail deep inside page-by-page OCR.
        if (!is_file($path) || !is_executable($path)) {
            throw new \RuntimeException(
                "OCR fallback is configured to use \"{$label}\" at \"{$path}\" (via {$envVar}), ".
                'but that file does not exist or is not executable. Double-check the path in .env.'
            );
        }
    }

    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    /**
     * Truncate lesson text to a safe size before sending to the LLM.
     * Keeps API cost/latency predictable for very long lesson PDFs.
     */
    public function truncateForPrompt(string $text, int $maxChars = 12000): string
    {
        if (strlen($text) <= $maxChars) {
            return $text;
        }

        return substr($text, 0, $maxChars) . "\n\n[...lesson content truncated for length...]";
    }
}