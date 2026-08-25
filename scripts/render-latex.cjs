#!/usr/bin/env node
/**
 * scripts/render-latex.cjs
 *
 * Converts a batch of LaTeX strings to standalone SVG using MathJax's
 * pure-Node TeX->SVG pipeline (no browser, no KaTeX-in-DOM — the whole
 * reason this script exists is that DomPDF can't run JS, so the browser-side
 * KaTeX auto-render used in the web results view (see layouts/app.blade.php)
 * never runs when generating a PDF; this reproduces the same math as static
 * SVG that DomPDF CAN embed).
 *
 * Invoked from PHP via App\Services\Latex\LatexMathRenderer, one process per
 * PDF export (all unique expressions in the exam batched into a single call,
 * not one process per question — spawning Node is the expensive part here).
 *
 * Usage:
 *   node scripts/render-latex.cjs < input.json > output.json
 *
 * input.json:  [{"id": "a1b2c3", "tex": "O(n \\log n)", "display": false}, ...]
 * output.json: [{"id": "a1b2c3", "svg": "<svg ...>...</svg>", "error": null}, ...]
 *              (on failure for a given expression: "svg": null, "error": "<message>",
 *              so one bad LaTeX string can't fail the whole batch)
 */

const { mathjax } = require('mathjax-full/js/mathjax.js');
const { TeX } = require('mathjax-full/js/input/tex.js');
const { SVG } = require('mathjax-full/js/output/svg.js');
const { liteAdaptor } = require('mathjax-full/js/adaptors/liteAdaptor.js');
const { RegisterHTMLHandler } = require('mathjax-full/js/handlers/html.js');
const { AllPackages } = require('mathjax-full/js/input/tex/AllPackages.js');

const adaptor = liteAdaptor();
RegisterHTMLHandler(adaptor);

// fontCache: 'none' — MathJax normally shares glyph <defs> across a single
// document via a font cache, but each of our SVGs ends up spliced into a
// completely different part of the PDF (different question, different
// page), not one shared document. 'none' makes every SVG fully
// self-contained (its own <defs>), which costs a little file size but is
// the only mode safe to embed independently like this.
const svgOutput = new SVG({ fontCache: 'none' });
const texInput = new TeX({ packages: AllPackages });
const doc = mathjax.document('', { InputJax: texInput, OutputJax: svgOutput });

function renderOne(tex, display) {
    const node = doc.convert(tex, { display: !!display, em: 16, ex: 8 });
    const container = adaptor.outerHTML(node); // <mjx-container ...><svg ...>...</svg></mjx-container>

    const svgMatch = container.match(/<svg[\s\S]*<\/svg>/);
    if (!svgMatch) {
        throw new Error('MathJax produced no <svg> output for this expression.');
    }
    let svg = svgMatch[0];

    // Carry the container's computed vertical-align onto the <svg> tag
    // itself so inline math sits on the surrounding text's baseline instead
    // of the SVG's own bottom edge — DomPDF has no idea what <mjx-container>
    // is, so that positioning would otherwise be lost when we discard it.
    const styleMatch = container.match(/<mjx-container[^>]*style="([^"]*)"/);
    if (styleMatch && styleMatch[1]) {
        const escaped = styleMatch[1].replace(/"/g, '&quot;');
        svg = svg.replace(/<svg /, `<svg style="${escaped}" `);
    }

    return svg;
}

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
    let items;
    try {
        items = JSON.parse(input || '[]');
    } catch (e) {
        process.stderr.write('Invalid JSON input: ' + e.message + '\n');
        process.exit(1);
    }

    const results = items.map(({ id, tex, display }) => {
        try {
            return { id, svg: renderOne(tex, display), error: null };
        } catch (e) {
            return { id, svg: null, error: e.message };
        }
    });

    process.stdout.write(JSON.stringify(results));
});
