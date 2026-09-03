<!DOCTYPE html>
{{--
    resources/views/tos/exam-pdf.blade.php

    The actual printable exam paper — one continuous, numbered test built
    from every generated question across all lessons, as opposed to
    tos/pdf.blade.php which prints the TOS blueprint document (the grid
    of item counts), not the questions themselves.

    Math notation ($...$/$$...$$) has ALREADY been converted to inline SVG
    by LatexMathRenderer before this view ever runs — see
    TosController::exportExamPdf(). Every *_html key below is pre-rendered,
    already-escaped-or-safe HTML; this view just places it, it does no
    escaping or math handling of its own. See LatexMathRenderer::substitute()
    for the .pdf-math-inline / .pdf-math-display / .pdf-math-fallback
    classes it emits, styled below.

    Expects:
      $tos           — TableOfSpecification (for course name / header)
      $examData      — [{ title, letter, questions: [{
                           item_number, bloom_level, question_type,
                           question_html, options_html, correct_answer,
                           is_true, correction_html, accepted_answers_html
                       }, ...] }, ...]
      $showAnswerKey — bool; false renders a clean student copy (no
                       correct answers/rationale shown), true renders the
                       teacher's answer-key copy. Same data either way —
                       nothing sensitive is stripped from $examData itself,
                       this view just chooses whether to print it.
--}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Exam - {{ $tos->course }}</title>
    <style>
        @page { margin: 30px 36px; }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px;
            color: #111;
            line-height: 1.45;
        }

        .exam-header { text-align: center; margin-bottom: 6px; }
        .exam-header .course { font-size: 14px; font-weight: bold; margin: 0; }
        .exam-header .subtitle {
            font-size: 10px;
            margin: 2px 0 0;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .exam-header .answer-key-flag {
            color: #8a1f2b;
            font-weight: bold;
        }

        .id-block {
            width: 100%;
            border-collapse: collapse;
            margin: 14px 0 18px;
            font-size: 10px;
        }
        .id-block td { padding: 3px 4px; }
        .id-block .label { font-weight: bold; white-space: nowrap; }
        .id-block .fill { border-bottom: 1px solid #999; width: 100%; }

        h2.lesson-heading {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin: 18px 0 8px;
            padding-bottom: 3px;
            border-bottom: 1.5px solid #222;
        }

        .question-block {
            margin-bottom: 12px;
            page-break-inside: avoid;
        }
        .question-block .q-head { font-weight: bold; }
        .question-block .item-code {
            color: #666;
            font-weight: normal;
            font-size: 9px;
        }

        ul.options {
            list-style: none;
            margin: 5px 0 0;
            padding: 0;
        }
        ul.options li { margin-bottom: 3px; }
        ul.options li.correct-option {
            font-weight: bold;
        }
        ul.options li .opt-letter {
            display: inline-block;
            width: 16px;
            font-weight: bold;
        }

        .tf-block { margin: 5px 0 0; }
        .tf-choice { display: inline-block; margin-right: 18px; }
        .tf-choice.correct-choice { font-weight: bold; }
        .correction-line {
            margin-top: 4px;
            font-style: italic;
        }

        .enum-lines { margin: 6px 0 0; }
        .enum-line {
            border-bottom: 1px solid #999;
            height: 16px;
            margin-bottom: 6px;
            max-width: 320px;
        }
        .accepted-answers {
            margin-top: 4px;
            font-weight: bold;
        }

        .draw-box {
            margin: 6px 0 0;
            border: 1px solid #999;
            min-height: 130px;
            max-height: 130px;
        }
        .draw-box.answer-key {
            min-height: 0;
            max-height: none;
            border: 1px dashed #999;
            padding: 6px 8px;
        }
        .trace-steps { margin: 0; padding-left: 14px; }
        .trace-steps li { margin-bottom: 2px; }

        .rationale {
            margin-top: 4px;
            font-size: 9.5px;
            color: #444;
            font-style: italic;
        }

        /* --- Math (see LatexMathRenderer::substitute) --- */
        .pdf-math-inline { display: inline-block; }
        .pdf-math-inline svg { height: 1em; }
        .pdf-math-display {
            display: block;
            text-align: center;
            margin: 6px 0;
        }
        .pdf-math-fallback {
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 9px;
            background: #f2f2f2;
            padding: 1px 3px;
        }
    </style>
</head>
<body>

<div class="exam-header">
    <p class="course">{{ $tos->course }}</p>
    <p class="subtitle">
        Examination
        @if ($showAnswerKey)
            &middot; <span class="answer-key-flag">Answer Key</span>
        @endif
    </p>
</div>

@unless ($showAnswerKey)
<table class="id-block">
    <tr>
        <td class="label">Name:</td>
        <td class="fill"></td>
        <td class="label">Score:</td>
        <td class="fill" style="width: 20%;"></td>
    </tr>
    <tr>
        <td class="label">Section:</td>
        <td class="fill"></td>
        <td class="label">Date:</td>
        <td class="fill"></td>
    </tr>
</table>
@endunless

@foreach ($examData as $lesson)
    <h2 class="lesson-heading">{{ $lesson['title'] }}</h2>

    @foreach ($lesson['questions'] as $q)
        <div class="question-block">
            <div class="q-head">
                {{ $q['item_number'] }}.
                <span class="item-code">[{{ $lesson['letter'] }}{{ $q['item_number'] }} &middot; {{ $q['bloom_level'] }}]</span>
                {!! $q['question_html'] !!}
            </div>

            @if ($q['question_type'] === 'multiple_choice')
                <ul class="options">
                    @foreach ($q['options_html'] as $letter => $optionHtml)
                        <li @class(['correct-option' => $showAnswerKey && $letter === $q['correct_answer']])>
                            <span class="opt-letter">{{ $letter }}.</span> {!! $optionHtml !!}
                        </li>
                    @endforeach
                </ul>
            @elseif ($q['question_type'] === 'modified_true_false')
                <div class="tf-block">
                    <span class="tf-choice {{ $showAnswerKey && $q['is_true'] ? 'correct-choice' : '' }}">TRUE</span>
                    <span class="tf-choice {{ $showAnswerKey && !$q['is_true'] ? 'correct-choice' : '' }}">FALSE</span>
                </div>
                @if ($showAnswerKey && !$q['is_true'] && $q['correction_html'])
                    <div class="correction-line">Correction: {!! $q['correction_html'] !!}</div>
                @endif
            @elseif ($q['question_type'] === 'enumeration')
                <div class="enum-lines">
                    @for ($i = 0; $i < max(count($q['accepted_answers_html']), 1); $i++)
                        <div class="enum-line"></div>
                    @endfor
                </div>
                @if ($showAnswerKey && !empty($q['accepted_answers_html']))
                    <div class="accepted-answers">
                        Accepted answers: {!! implode(', ', $q['accepted_answers_html']) !!}
                    </div>
                @endif
            @elseif ($q['question_type'] === 'algorithm_trace')
                @if ($showAnswerKey)
                    <div class="draw-box answer-key">
                        <strong>Model answer / grading trace:</strong>
                        <ol class="trace-steps">
                            @foreach ($q['accepted_answers_html'] as $step)
                                <li>{!! $step !!}</li>
                            @endforeach
                        </ol>
                    </div>
                @else
                    {{-- Blank space for the student to draw the diagram on paper. --}}
                    <div class="draw-box"></div>
                @endif
            @endif

            @if ($showAnswerKey && !empty($q['rationale_html']))
                <div class="rationale">{!! $q['rationale_html'] !!}</div>
            @endif
        </div>
    @endforeach
@endforeach

</body>
</html>