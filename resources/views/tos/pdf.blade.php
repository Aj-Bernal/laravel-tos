<!DOCTYPE html>
{{--
    resources/views/tos/pdf.blade.php

    Renders the official CvSU Naic Arts and Sciences Department Table of
    Specification via DomPDF (see TosController::exportPdf,
    ->setPaper('legal', 'landscape')). Structure mirrors the department's
    actual paper form:
      - university letterhead + Midterms/Finals checkbox
      - main grid: Course Outcomes (spans all rows) | Topic | Intended
        Learning Outcomes | Instructional Time (Hours) | 6 Bloom's-level
        columns | Actual Number of Items | Percentage of Item
        Distribution (%) -- in that column order, percentage LAST.
      - Item Placement grid: Test Part x Easy/Moderate/Hard
      - three-signature footer (Prepared by / Reviewed by / Approved by)

    Expects:
      $tos                -- TableOfSpecification, with lessons.objectives,
                             lessons.examQuestions eager-loaded
      $itemPlacementGrid   -- from ItemPlacementService::groupGrid():
                             ['I' => ['Easy' => [...], 'Moderate' => [...], 'Hard' => [...]], ...]
      $meta                -- optional overrides (department, semester,
                             academic_year, exam_period, course_outcome,
                             prepared_by(_title), reviewed_by(_title),
                             approved_by(_title)); anything omitted renders
                             as a blank line, same as a blank paper form.

    Row-level numbers (Hours, per-level counts, Actual Items) come
    straight from each Lesson (one row per lesson = one row per
    topic/ILO group, matching TosBuilderService's per-lesson allocation).
    Nothing here recomputes those numbers.
--}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>TOS - {{ $tos->course }}</title>
    <style>
        @page { margin: 26px 30px; }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            color: #111;
        }

        .letterhead { text-align: center; margin-bottom: 4px; }
        .letterhead .republic { font-size: 8.5px; margin: 0; }
        .letterhead .university { font-size: 14px; font-weight: bold; margin: 1px 0; }
        .letterhead .formerly { font-size: 8px; font-style: italic; margin: 0; }
        .letterhead .address, .letterhead .site { font-size: 8px; margin: 0; }

        .dept-block { text-align: center; margin: 8px 0 4px; }
        .dept-block .dept { font-size: 10px; font-weight: bold; margin: 0; }
        .dept-block .doc-title { font-size: 13px; font-weight: bold; letter-spacing: 0.08em; margin: 2px 0; }

        .course-block { text-align: center; margin-bottom: 4px; }
        .course-block .course-line { font-size: 9.5px; font-weight: bold; margin: 0; }
        .course-block .sem-line { font-size: 9px; margin: 1px 0 6px; }

        .exam-period { text-align: center; margin-bottom: 10px; font-size: 9px; }
        .exam-period .box {
            display: inline-block;
            width: 9px; height: 9px;
            border: 1px solid #222;
            text-align: center;
            line-height: 9px;
            font-size: 8px;
            margin-right: 3px;
            position: relative;
            top: 1px;
        }
        .exam-period .opt { margin: 0 14px; }

        h2.section-title {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin: 12px 0 5px;
            padding-bottom: 2px;
            border-bottom: 1.5px solid #222;
        }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th, table.grid td {
            border: 1px solid #444;
            padding: 4px 5px;
            text-align: center;
            vertical-align: middle;
        }
        table.grid th { background: #e8e8e8; font-weight: bold; font-size: 8.5px; }
        table.grid td.text-left { text-align: left; }
        table.grid tfoot td { font-weight: bold; background: #f2f2f2; }
        table.grid ul.ilo-list { margin: 0; padding-left: 12px; text-align: left; }
        table.grid ul.ilo-list li { margin-bottom: 3px; }

        table.grid td.item-codes { text-align: left; font-size: 8.5px; line-height: 1.4; }
        table.grid td.empty-cell { color: #aaa; }

        .col-part { width: 55px; }

        .placement-note {
            font-size: 7.5px;
            color: #555;
            margin: 4px 0 0;
        }

        table.signatures { width: 100%; border-collapse: collapse; margin-top: 30px; }
        table.signatures td { width: 33.33%; vertical-align: top; padding: 0 10px; }
        table.signatures .sig-label { font-weight: bold; margin-bottom: 22px; }
        table.signatures .sig-line { border-top: 1px solid #222; padding-top: 2px; }
        table.signatures .sig-name { font-weight: bold; }
        table.signatures .sig-role { font-size: 8px; color: #333; }
    </style>
</head>
<body>

@php
    $levels = \App\Services\Bloom\BloomClassifierService::LEVELS;
    $tiers = \App\Services\Bloom\ItemPlacementService::DIFFICULTY_TIERS;

    $totalWeight = $tos->lessons->sum('weight') ?: 1;
    $grandTotalItems = $tos->lessons->sum('item_quota');
    $rowCount = max($tos->lessons->count(), 1);

    $tierTotals = ['Easy' => 0, 'Moderate' => 0, 'Hard' => 0];
    foreach ($tos->distribution ?? [] as $level => $d) {
        $tierTotals[$tiers[$level] ?? 'Moderate'] += $d['item_count'] ?? 0;
    }

    $examPeriod = $meta['exam_period'] ?? null;
@endphp

<div class="letterhead">
    <p class="republic">Republic of the Philippines</p>
    <p class="university">CAVITE STATE UNIVERSITY NAIC</p>
    <p class="formerly">(Formerly Cavite College of Fisheries)</p>
    <p class="address">Bucana Malaki, Naic, Cavite</p>
    <p class="site">www.cvsu-naic.edu.ph</p>
</div>

<div class="dept-block">
    <p class="dept">{{ strtoupper($meta['department'] ?? 'Arts and Sciences Department') }}</p>
    <p class="doc-title">TABLE OF SPECIFICATION</p>
</div>

<div class="course-block">
    <p class="course-line">{{ $tos->course }}</p>
    <p class="sem-line">
        {{ !empty($meta['semester']) ? strtoupper($meta['semester']) : '' }}{{ !empty($meta['academic_year']) ? ', A.Y. '.$meta['academic_year'] : '' }}
    </p>
</div>

<div class="exam-period">
    <span class="opt"><span class="box">{{ $examPeriod === 'Midterms' ? '&#10003;' : '' }}</span>Midterms</span>
    <span class="opt"><span class="box">{{ $examPeriod === 'Finals' ? '&#10003;' : '' }}</span>Finals</span>
</div>

{{-- ============ Main TOS grid ============ --}}
<table class="grid">
    <thead>
        <tr>
            <th rowspan="2" style="width: 13%;">Course Outcomes</th>
            <th rowspan="2" style="width: 13%;">Topic</th>
            <th rowspan="2" style="width: 20%;">Intended Learning Outcomes (ILO)</th>
            <th rowspan="2" style="width: 6%;">Instructional Time (Hours)</th>
            <th colspan="6">Bloom's Taxonomy Level</th>
            <th rowspan="2" style="width: 6%;">Actual Number of Items</th>
            <th rowspan="2" style="width: 7%;">Percentage of Item Distribution (%)</th>
        </tr>
        <tr>
            @foreach ($levels as $level)
                <th>{{ $level }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($tos->lessons as $lesson)
            <tr>
                @if ($loop->first)
                    <td class="text-left" rowspan="{{ $rowCount }}">{{ $meta['course_outcome'] ?? '' }}</td>
                @endif
                <td class="text-left">{{ $lesson->title }}</td>
                <td class="text-left">
                    @if ($lesson->objectives->isNotEmpty())
                        <ul class="ilo-list">
                            @foreach ($lesson->objectives as $obj)
                                <li>{{ $obj->objective_text }}</li>
                            @endforeach
                        </ul>
                    @endif
                </td>
                <td>{{ rtrim(rtrim(number_format($lesson->weight, 2), '0'), '.') }}</td>
                @foreach ($levels as $level)
                    <td>{{ $lesson->level_distribution[$level] ?? 0 }}</td>
                @endforeach
                <td><strong>{{ $lesson->item_quota }}</strong></td>
                <td>{{ number_format(($lesson->weight / $totalWeight) * 100, 1) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3">TOTAL</td>
            <td>{{ rtrim(rtrim(number_format($totalWeight, 2), '0'), '.') }}</td>
            @foreach ($levels as $level)
                <td>{{ $tos->distribution[$level]['item_count'] ?? 0 }}</td>
            @endforeach
            <td>{{ $grandTotalItems }}</td>
            <td>100</td>
        </tr>
    </tfoot>
</table>

{{-- ============ Item Placement: Test Part (rows) x Easy/Moderate/Hard (columns) ============ --}}
<h2 class="section-title">Item Placement</h2>
<table class="grid">
    <thead>
        <tr>
            <th class="col-part">Test Part</th>
            <th>Easy ({{ $tierTotals['Easy'] }} items)</th>
            <th>Moderate ({{ $tierTotals['Moderate'] }} items)</th>
            <th>Hard ({{ $tierTotals['Hard'] }} items)</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($itemPlacementGrid as $part => $byTier)
            <tr>
                <td><strong>{{ $part }}</strong></td>
                @foreach (['Easy', 'Moderate', 'Hard'] as $tier)
                    <td class="item-codes {{ empty($byTier[$tier]) ? 'empty-cell' : '' }}">
                        {{ !empty($byTier[$tier]) ? implode(', ', $byTier[$tier]) : '-' }}
                    </td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="4" class="empty-cell">No exam items have been generated for this TOS yet.</td>
            </tr>
        @endforelse
    </tbody>
</table>
<p class="placement-note">
    Easy = Remembering, Understanding &middot; Moderate = Applying, Analyzing &middot; Hard = Evaluating, Creating.
    Item codes reference the lesson letter and item number (e.g. "B4" = Lesson B, item 4).
</p>

{{-- ============ Signatures ============ --}}
<table class="signatures">
    <tr>
        <td>
            <div class="sig-label">Prepared by:</div>
            <div class="sig-line">
                <div class="sig-name">{{ $meta['prepared_by'] ?? '' }}</div>
                <div class="sig-role">{{ $meta['prepared_by_title'] ?? '' }}</div>
            </div>
        </td>
        <td>
            <div class="sig-label">Reviewed by:</div>
            <div class="sig-line">
                <div class="sig-name">{{ $meta['reviewed_by'] ?? '' }}</div>
                <div class="sig-role">{{ $meta['reviewed_by_title'] ?? 'Chairperson, ASD' }}</div>
            </div>
        </td>
        <td>
            <div class="sig-label">Approved by:</div>
            <div class="sig-line">
                <div class="sig-name">{{ $meta['approved_by'] ?? '' }}</div>
                <div class="sig-role">{{ $meta['approved_by_title'] ?? 'Campus Administrator' }}</div>
            </div>
        </td>
    </tr>
</table>

</body>
</html>