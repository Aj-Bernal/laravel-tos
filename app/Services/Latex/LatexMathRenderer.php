<?php

namespace App\Services\Latex;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Renders $...$ / $$...$$ LaTeX spans (the same notation Gemini is
 * instructed to produce — see ExamGeneratorService::typeSchemaInstructions)
 * to inline SVG for the PDF export path.
 *
 * The web results view handles this entirely client-side via KaTeX
 * (layouts/app.blade.php's window.renderMath), but DomPDF can't run JS, so
 * this reproduces the same math as static SVG generated server-side by
 * MathJax (scripts/render-latex.cjs) and spliced directly into the HTML
 * DomPDF renders.
 *
 * Usage: collect every raw string that might contain math into one call to
 * renderStrings(), which batches ALL unique expressions across the whole
 * export into a SINGLE Node process invocation (spawning Node per question
 * would be needlessly slow for a multi-lesson exam) and returns each input
 * string transformed into safe HTML (plain text escaped, math replaced with
 * <svg>) ready for {!! !!} in a Blade view.
 */
class LatexMathRenderer
{
    /** Matches $$...$$ (display) OR $...$ (inline); $$ tried first per position. */
    private const PATTERN = '/\$\$(.+?)\$\$|\$(.+?)\$/s';

    public function __construct(
        private ?string $nodeBinary = null,
        private ?string $scriptPath = null,
    ) {
        $this->nodeBinary ??= config('services.mathjax.node_binary', 'node');
        $this->scriptPath ??= config('services.mathjax.script_path', base_path('scripts/render-latex.cjs'));
    }

    /**
     * @param array<string, string|null> $strings Keyed however the caller likes
     *        (e.g. "q12", "q12.opt.A") — raw text, possibly containing $...$/
     *        $$...$$ math and plain prose. Null/empty values pass through as ''.
     * @return array<string, string> Same keys, each value safe HTML (escaped
     *         prose + inline <svg> for math) ready for {!! !!} in Blade.
     */
    public function renderStrings(array $strings): array
    {
        $expressions = $this->collectExpressions($strings);

        $svgById = empty($expressions)
            ? []
            : $this->renderBatch($expressions);

        $output = [];
        foreach ($strings as $key => $raw) {
            $output[$key] = $this->substitute((string) $raw, $svgById);
        }

        return $output;
    }

    /**
     * First pass: scan every string for math spans and collect the UNIQUE
     * set (by content + display/inline-ness) that actually needs rendering,
     * so the same "$O(n \log n)$" repeated across ten questions only costs
     * one MathJax call, not ten.
     *
     * @return array<int, array{id: string, tex: string, display: bool}>
     */
    private function collectExpressions(array $strings): array
    {
        $byId = [];

        foreach ($strings as $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }

            preg_match_all(self::PATTERN, (string) $raw, $matches, PREG_SET_ORDER);

            foreach ($matches as $m) {
                $display = $m[1] !== '';
                $tex = trim($display ? $m[1] : $m[2]);

                if ($tex === '') {
                    continue;
                }

                $id = $this->idFor($tex, $display);
                $byId[$id] = ['id' => $id, 'tex' => $tex, 'display' => $display];
            }
        }

        return array_values($byId);
    }

    private function idFor(string $tex, bool $display): string
    {
        return substr(md5(($display ? 'D:' : 'I:') . $tex), 0, 16);
    }

    /**
     * @param array<int, array{id: string, tex: string, display: bool}> $expressions
     * @return array<string, string|null> id => svg markup (null if that one expression failed to render)
     */
    private function renderBatch(array $expressions): array
    {
        $process = new Process([$this->nodeBinary, $this->scriptPath]);
        $process->setInput(json_encode($expressions));
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::error('[LatexMathRenderer] Failed to spawn Node for math rendering: ' . $e->getMessage());
            return [];
        }

        if (!$process->isSuccessful()) {
            Log::error('[LatexMathRenderer] render-latex.cjs exited non-zero: ' . $process->getErrorOutput());
            return [];
        }

        $decoded = json_decode($process->getOutput(), true);
        if (!is_array($decoded)) {
            Log::error('[LatexMathRenderer] render-latex.cjs produced unparsable output.');
            return [];
        }

        $svgById = [];
        foreach ($decoded as $item) {
            $id = $item['id'] ?? null;
            if ($id === null) {
                continue;
            }
            if (!empty($item['error'])) {
                Log::warning("[LatexMathRenderer] MathJax failed to render an expression (id {$id}): " . $item['error']);
            }
            $svgById[$id] = $item['svg'] ?? null;
        }

        return $svgById;
    }

    /**
     * Second pass: walk the raw string once, escaping plain-text runs with
     * e() and replacing each math span with its rendered SVG (or a visible
     * monospace fallback showing the raw LaTeX source, if that particular
     * expression failed to render — never just silently dropping it).
     */
    private function substitute(string $raw, array $svgById): string
    {
        if ($raw === '') {
            return '';
        }

        preg_match_all(self::PATTERN, $raw, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        if (empty($matches)) {
            return e($raw);
        }

        $html = '';
        $cursor = 0;

        foreach ($matches as $m) {
            [$wholeMatch, $matchOffset] = $m[0];
            $display = $m[1][0] !== '';
            $tex = trim($display ? $m[1][0] : $m[2][0]);

            // Plain text since the last match, escaped normally.
            $html .= e(substr($raw, $cursor, $matchOffset - $cursor));

            if ($tex === '') {
                $html .= e($wholeMatch);
            } else {
                $id = $this->idFor($tex, $display);
                $svg = $svgById[$id] ?? null;

                if ($svg) {
                    $tag = $display ? 'div' : 'span';
                    $class = $display ? 'pdf-math-display' : 'pdf-math-inline';
                    $html .= "<{$tag} class=\"{$class}\">{$svg}</{$tag}>";
                } else {
                    // Rendering failed for this one expression — show the
                    // raw LaTeX source rather than silently eating it.
                    $html .= '<code class="pdf-math-fallback">' . e($display ? "\$\${$tex}\$\$" : "\${$tex}\$") . '</code>';
                }
            }

            $cursor = $matchOffset + strlen($wholeMatch);
        }

        $html .= e(substr($raw, $cursor));

        return $html;
    }
}
