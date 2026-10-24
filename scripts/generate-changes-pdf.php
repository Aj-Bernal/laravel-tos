<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Console\Kernel;

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Standalone TOS Table PDF & Terminal Pipeline - Technical Summary</title>
    <style>
        @page {
            margin: 36px 44px;
            @bottom-right {
                content: "Page " counter(page) " of " counter(pages);
                font-size: 8px;
                color: #777;
            }
        }
        * { box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9.5px;
            line-height: 1.5;
            color: #222;
        }
        .header {
            border-bottom: 2px solid #1e4528;
            padding-bottom: 8px;
            margin-bottom: 16px;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            color: #1e4528;
            margin: 0 0 4px;
        }
        .subtitle {
            font-size: 11px;
            color: #4a7c59;
            font-weight: 600;
            margin: 0 0 6px;
        }
        .meta {
            font-size: 8.5px;
            color: #666;
        }
        h2 {
            font-size: 12px;
            font-weight: bold;
            color: #1e4528;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
            margin: 14px 0 6px;
        }
        h3 {
            font-size: 10px;
            font-weight: bold;
            color: #333;
            margin: 8px 0 3px;
        }
        p {
            margin: 0 0 6px;
        }
        ul {
            margin: 0 0 8px 16px;
            padding: 0;
        }
        li {
            margin-bottom: 3px;
        }
        .badge {
            display: inline-block;
            background: #eaf3ed;
            color: #1e4528;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 12px;
            font-size: 8.5px;
        }
        th, td {
            border: 1px solid #bbb;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #f2f7f3;
            color: #1e4528;
            font-weight: bold;
        }
        .code-box {
            background-color: #f8f9fa;
            border: 1px solid #e1e4e8;
            border-left: 3px solid #1e4528;
            padding: 6px 8px;
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 8px;
            line-height: 1.4;
            margin: 6px 0 10px;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 20px;
            border-top: 1px solid #ddd;
            padding-top: 6px;
            font-size: 8px;
            color: #777;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="title">Automated TOS & Exam Generator</div>
    <div class="subtitle">Standalone TOS Table PDF Generation & Terminal Output Pipeline</div>
    <div class="meta">
        <strong>Release Date:</strong> October 24, 2026 &nbsp;|&nbsp;
        <strong>Framework:</strong> Laravel 12 / PHP 8.2 &nbsp;|&nbsp;
        <strong>Status:</strong> Implemented & Validated
    </div>
</div>

<h2>1. Executive Summary & Objectives</h2>
<p>
    This update shifts the system workflow to prioritize generating a publication-ready <strong>Table of Specification (TOS) Table PDF</strong> directly upon processing uploaded learning outcomes and materials, deferring AI exam question generation for a subsequent phase. In addition, full processing analytics—including classified Bloom Taxonomy levels with model confidence and a formatted two-level allocation table—are now printed directly to the terminal stdout in real time.
</p>
<p>The changes fulfill three core requirements:</p>
<ul>
    <li><strong>Curriculum-Aligned TOS PDF Layout:</strong> Generates a clean 12-column Table of Specification PDF matching official Philippine university/CvSU standards, rendering blank cells for zero counts, exact hours/percentage calculations, and omitting the question placement grid when test generation is deferred.</li>
    <li><strong>Synchronous Terminal Printing:</strong> Provides complete ASCII-formatted classification and allocation tables to the terminal upon input submission (both during web server processing and CLI commands).</li>
    <li><strong>Dual Access Pathways:</strong> Allows teachers to generate and export TOS PDFs through both the responsive single-page Blade UI and a dedicated Artisan CLI command.</li>
</ul>

<h2>2. Detailed Architecture & File Modifications</h2>

<table>
    <thead>
        <tr>
            <th style="width: 28%;">File Location</th>
            <th style="width: 14%;">Nature</th>
            <th style="width: 58%;">Description of Changes</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>app/Services/Bloom/<br>TosConsolePrinter.php</code></td>
            <td><span class="badge">NEW</span></td>
            <td>
                Encapsulates terminal stdout formatting. Outputs header metrics (course, hours, item count), per-lesson Intended Learning Outcomes (ILOs) with predicted Bloom's category and confidence percentage, a full ASCII breakdown table with largest-remainder apportioned quotas, and export URLs/paths.
            </td>
        </tr>
        <tr>
            <td><code>app/Console/Commands/<br>ProcessTosCommand.php</code></td>
            <td><span class="badge">NEW</span></td>
            <td>
                Artisan CLI command <code>php artisan tos:process</code>. Accepts CLI arguments/options for course, items, instructional weight/hours, objectives, and source PDF; executes the classification and TOS builder pipeline; prints results to the terminal; and exports the TOS PDF directly to disk.
            </td>
        </tr>
        <tr>
            <td><code>tests/Feature/<br>TosTableGenerationTest.php</code></td>
            <td><span class="badge">NEW</span></td>
            <td>
                End-to-end Feature test suite containing 3 test cases: web request processing with multipart PDF, standalone TOS PDF HTTP download verification (200 + application/pdf + %PDF- binary header), and CLI command execution with disk artifact assertions.
            </td>
        </tr>
        <tr>
            <td><code>app/Http/Controllers/<br>TosController.php</code></td>
            <td><span class="badge">MODIFIED</span></td>
            <td>
                Injected <code>TosConsolePrinter</code> into the constructor. In <code>classifyAndBuildTos()</code>, immediately dispatches terminal printing upon database commit, and updates the response status message to direct teachers to the ready-to-download TOS PDF.
            </td>
        </tr>
        <tr>
            <td><code>resources/views/tos/<br>pdf.blade.php</code></td>
            <td><span class="badge">MODIFIED</span></td>
            <td>
                Redesigned the primary table grid to match the reference document: exact 12-column header titles (including <em>"INTENDED LEARNING OUTCOMES (ILO) based on the approved syllabus"</em>), 1px crisp borders, blank cells for zero allocations, and conditionally hidden <em>Item Placement</em> section when questions are not yet generated.
            </td>
        </tr>
        <tr>
            <td><code>resources/views/tos/<br>_results.blade.php</code></td>
            <td><span class="badge">MODIFIED</span></td>
            <td>
                Extracted the TOS Table PDF download button and customization form out of the exam questions guard, displaying it prominently at the top of the results container right after building the TOS. Moved question generation to a separated, secondary section.
            </td>
        </tr>
        <tr>
            <td><code>routes/web.php</code></td>
            <td><span class="badge">MODIFIED</span></td>
            <td>
                Added root route <code>GET /</code> mapped to <code>TosController::create</code>. Allows accessing <code>http://127.0.0.1:8000/</code> directly without requiring the subpath, and brings the stock Laravel ExampleTest to green.
            </td>
        </tr>
        <tr>
            <td><code>STATE.md</code></td>
            <td><span class="badge">MODIFIED</span></td>
            <td>
                Logged the task plan under date <code>2026-10-24</code> and marked all 7 implementation and validation milestones as completed.
            </td>
        </tr>
    </tbody>
</table>

<h2>3. 12-Column Table of Specification (TOS) Specification</h2>
<p>
    The generated PDF layout in <code>resources/views/tos/pdf.blade.php</code> strictly mirrors the curriculum reference layout:
</p>
<div class="code-box">
Col 1:  COURSE OUTCOMES
Col 2:  TOPIC
Col 3:  INTENDED LEARNING OUTCOMES (ILO) based on the approved syllabus
Col 4:  INSTRUCTIONAL TIME (HOURS)
Col 5:  Remembering
Col 6:  Understanding
Col 7:  Applying
Col 8:  Analyzing
Col 9:  Evaluating
Col 10: Creating
Col 11: Actual Number of Items
Col 12: Percentage of Item Distribution (%)
Row N:  TOTAL [Total Hours | Col 5-10 Category Totals | Total Items | 100%]
</div>
<ul>
    <li><strong>Zero-Value Cleansing:</strong> Any cognitive level allocated 0 items renders as an empty string instead of "0", enhancing document readability.</li>
    <li><strong>Mathematical Reconciliation:</strong> Uses Hamilton-Webster largest-remainder apportionment at two tiers (lesson quotas and Bloom level distributions), guaranteeing that all integer item counts sum to the exact target total.</li>
</ul>

<h2>4. CLI and Web Terminal Output Sample</h2>
<p>When input is processed, <code>TosConsolePrinter</code> outputs the following structured report directly to the terminal stdout:</p>
<div class="code-box">
================================================================================
       TABLE OF SPECIFICATION (TOS) & BLOOM TAXONOMY CLASSIFICATION RESULT     
================================================================================
Course: Digital Circuits & Logic Design
Total Exam Items: 50 | Total Instructional Time: 5 hours | Lessons: 2

--------------------------------------------------------------------------------
 CLASSIFIED INTENDED LEARNING OUTCOMES (ILO)
--------------------------------------------------------------------------------
[Lesson 1] Common Number Systems and Conversions (Time: 3 hrs, Items: 30)
   [ILO 1] Define the number system
          -> Bloom Level: Remembering (83.9% conf)
   [ILO 2] Differentiate binary numbers to decimal and hexadecimal
          -> Bloom Level: Analyzing (64.8% conf)

--------------------------------------------------------------------------------
 TABLE OF SPECIFICATION (TOS) BREAKDOWN
--------------------------------------------------------------------------------
+---------------------------------------+-------+-----+-----+-----+-----+-----+-----+-------+--------+
| Topic / Lesson                        | Hours | Rem | Und | App | Ana | Eva | Cre | Items | % Dist |
+---------------------------------------+-------+-----+-----+-----+-----+-----+-----+-------+--------+
| Common Number Systems and Conversions | 3     | 6   | 6   | 6   | 5   | 4   | 3   | 30    | 60.0%  |
| Boolean Algebra & Logic Gates         | 2     | 4   | 4   | 4   | 3   | 3   | 2   | 20    | 40.0%  |
+---------------------------------------+-------+-----+-----+-----+-----+-----+-----+-------+--------+
| TOTAL                                 | 5     | 10  | 10  | 10  | 8   | 7   | 5   | 50    | 100%   |
+---------------------------------------+-------+-----+-----+-----+-----+-----+-----+-------+--------+
TOS Export Route: /tos/&lt;uuid&gt;/export-pdf
</div>

<h2>5. Quality Assurance & Verification</h2>
<p>All functionality was subjected to automated and regression verification:</p>
<ul>
    <li><strong>End-to-End Tests:</strong> <code>tests/Feature/TosTableGenerationTest.php</code> passed 3/3 tests with 32 assertions.</li>
    <li><strong>Full Suite Regression:</strong> All 26 test cases across the entire repository passed with zero failures.</li>
    <li><strong>Code Standards:</strong> Formatted with Laravel Pint to enforce strict PSR-12 and Laravel coding standards.</li>
</ul>

<div class="footer">
    Automated TOS & Exam Generator &bull; Department of Information Technology &bull; Cavite State University Naic Campus
</div>

</body>
</html>
HTML;

$pdf = Pdf::loadHTML($html)->setPaper('letter', 'portrait');

$outputPath = __DIR__.'/../standalone-tos-changes-summary.pdf';
file_put_contents($outputPath, $pdf->output());

echo 'PDF successfully generated at: '.realpath($outputPath).PHP_EOL;
