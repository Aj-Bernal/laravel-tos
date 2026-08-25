<?php

return [
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
    ],
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    // Used by PdfTextExtractorService::ocrExtract() for scanned/image-only
    // lesson PDFs. Bare command names ('pdftoppm', 'tesseract') work if the
    // binary is on PATH; set the *_PATH env vars to an absolute path if it
    // isn't (e.g. a Windows install not added to PATH).
    'poppler' => [
        'pdftoppm_path' => env('POPPLER_PDFTOPPM_PATH', 'pdftoppm'),
    ],

    'tesseract' => [
        'binary_path' => env('TESSERACT_PATH', 'tesseract'),
    ],

    // Used by LatexMathRenderer to shell out to scripts/render-latex.cjs
    // (MathJax TeX->SVG, no browser) for the exam PDF export — DomPDF can't
    // run the KaTeX auto-render JS the web results view uses, so this is
    // the server-side equivalent. Bare 'node' works if it's on PATH (it
    // already needs to be, for `npm run build`/`npm run dev`); set
    // NODE_BINARY_PATH if it isn't.
    'mathjax' => [
        'node_binary' => env('NODE_BINARY_PATH', 'node'),
        'script_path' => base_path('scripts/render-latex.cjs'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];