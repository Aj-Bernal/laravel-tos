{{-- resources/views/tos/index.blade.php
     Single URL for the whole flow. $tos is null on first load (/tos/create)
     or populated (/tos/{tos}) for a direct/shared link — either way, every
     subsequent action (create, generate exam, retry) happens via fetch()
     and swaps #results-container in place, updating the address bar with
     history.pushState only (no real navigation, no new tab, no reload). --}}
@extends('layouts.app')

@section('content')

<div id="flash-container">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $error)
                <p style="margin: 0.15rem 0;">{{ $error }}</p>
            @endforeach
        </div>
    @endif
</div>

{{-- ===================== Create panel ===================== --}}
<div id="create-panel" style="{{ isset($tos) ? 'display:none;' : '' }}">

    <p class="cvsu-intro">
        Add one entry per lesson (5-10 PDFs is fine). Give each lesson a weight
        reflecting how much class time you spent on it &mdash; exam items are
        distributed across lessons proportional to that weight, then balanced
        across Bloom's Taxonomy levels within each lesson.
    </p>

    <div class="bloom-legend">
        <span class="bloom-chip"><span class="dot" style="background: var(--bloom-remembering)"></span>Remembering</span>
        <span class="bloom-chip"><span class="dot" style="background: var(--bloom-understanding)"></span>Understanding</span>
        <span class="bloom-chip"><span class="dot" style="background: var(--bloom-applying)"></span>Applying</span>
        <span class="bloom-chip"><span class="dot" style="background: var(--bloom-analyzing)"></span>Analyzing</span>
        <span class="bloom-chip"><span class="dot" style="background: var(--bloom-evaluating)"></span>Evaluating</span>
        <span class="bloom-chip"><span class="dot" style="background: var(--bloom-creating)"></span>Creating</span>
    </div>

    <div class="spec-sheet">
        <form id="tos-form" data-action="{{ route('tos.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="field-group">
                <label for="course">Course</label>
                <input type="text" id="course" name="course" required class="field-blank" placeholder="e.g. Data Structures and Algorithms">
            </div>

            <div class="field-group" style="margin-bottom: 2rem;">
                <label for="total_items">Total Exam Items</label>
                <input type="number" id="total_items" name="total_items" min="5" max="200" value="50" class="field-blank field-narrow">
            </div>

            <h2 class="section-title">Lessons</h2>

            <div id="lesson-panels"></div>

            <button type="button" class="btn-add-lesson" id="add-lesson-btn">+ Add Lesson</button>

            <div>
                <button type="submit" class="btn-seal" id="submit-btn">
                    <span id="submit-btn-label">Classify Outcomes &amp; Build TOS</span>
                </button>
                <p class="field-hint" id="submit-wait-hint" style="display:none; margin-top:0.75rem;">
                    This can take a little while with several PDFs &mdash; please don't navigate away.
                </p>
            </div>
        </form>
    </div>

    <template id="lesson-panel-template">
        <div class="lesson-panel" data-lesson-panel>
            <div class="lesson-panel-header">
                <span class="lesson-panel-number" data-lesson-number>Lesson 1</span>
                <button type="button" class="btn-remove-lesson" data-remove-lesson>Remove</button>
            </div>

            <div class="lesson-weight-row">
                <div class="field-group">
                    <label>Lesson Title</label>
                    <input type="text" data-field="title" class="field-blank" placeholder="e.g. Binary Search Trees" required>
                </div>
                <div class="field-group" style="max-width: 160px;">
                    <label>Weight (hours)</label>
                    <input type="number" data-field="weight" class="field-blank" placeholder="e.g. 3" min="0.1" step="0.1" required>
                </div>
            </div>

            <div class="field-group">
                <label>Course Learning Outcomes <span style="font-weight:400; color: var(--ink-soft); font-family: var(--font-body); font-size:0.85rem;">(one per line)</span></label>
                <textarea data-field="objectives_text" rows="5" required class="field-ruled"
                          placeholder="Students will be able to differentiate between a binary search tree and a balanced tree.&#10;Students will design an algorithm to balance an unbalanced tree."></textarea>
            </div>

            <div class="field-group" style="margin-bottom: 0;">
                <label>Lecture Material (PDF)</label>
                <input type="file" data-field="pdf" class="field-blank" accept="application/pdf,image/jpeg,image/png" required>
                <p class="field-hint" data-file-confirm style="display:none;"></p>
            </div>
        </div>
    </template>
</div>

{{-- ===================== Results panel ===================== --}}
<div id="results-container">
    @isset($tos)
        @include('tos._results', ['tos' => $tos, 'itemPlacement' => $itemPlacement ?? []])
    @endisset
</div>

{{-- ===================== Shared loading overlay ===================== --}}
<div id="loading-overlay" style="display:none;">
    <div class="loading-card">
        <div class="loading-spinner" aria-hidden="true"></div>
        <p class="loading-title" id="loading-title">Working&hellip;</p>
        <p class="loading-sub" id="loading-sub">Please don't navigate away &mdash; this page will update in place.</p>
    </div>
</div>

<style>
    #loading-overlay {
        position: fixed;
        inset: 0;
        background: rgba(30, 42, 34, 0.55);
        backdrop-filter: blur(2px);
        z-index: 999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
    }
    .loading-card {
        background: #fff;
        border-radius: 6px;
        padding: 2.25rem 2rem;
        max-width: 380px;
        text-align: center;
        box-shadow: 0 12px 40px rgba(0,0,0,0.25);
    }
    .loading-spinner {
        width: 42px;
        height: 42px;
        margin: 0 auto 1.25rem;
        border: 4px solid var(--paper-line);
        border-top-color: var(--cvsu-green-deep);
        border-radius: 50%;
        animation: tos-spin 0.85s linear infinite;
    }
    @keyframes tos-spin { to { transform: rotate(360deg); } }
    .loading-title {
        font-family: var(--font-display);
        font-weight: 600;
        color: var(--cvsu-green-deep);
        margin: 0 0 0.5rem;
        font-size: 1.05rem;
    }
    .loading-sub {
        font-size: 0.85rem;
        color: var(--ink-soft);
        margin: 0;
    }
    @media (prefers-reduced-motion: reduce) {
        .loading-spinner { animation: none; }
    }
</style>

<script>
(function () {
    const createPanel = document.getElementById('create-panel');
    const resultsContainer = document.getElementById('results-container');
    const flashContainer = document.getElementById('flash-container');
    const overlay = document.getElementById('loading-overlay');
    const loadingTitle = document.getElementById('loading-title');

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value;

    function showOverlay(title) {
        loadingTitle.textContent = title;
        overlay.style.display = 'flex';
    }
    function hideOverlay() {
        overlay.style.display = 'none';
    }

    function showFlash(type, messages) {
        const list = Array.isArray(messages) ? messages : [messages];
        flashContainer.innerHTML = `<div class="alert alert-${type}">` +
            list.map(m => `<p style="margin:0.15rem 0;">${m}</p>`).join('') +
            `</div>`;
        flashContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function clearFlash() {
        flashContainer.innerHTML = '';
    }

    // Swaps in a fresh results fragment and puts the browser in "viewing a TOS" state,
    // WITHOUT a real navigation — pushState only changes the address bar.
    function renderResults(html, tosId) {
        resultsContainer.innerHTML = html;
        window.renderMath(resultsContainer);
        createPanel.style.display = 'none';
        if (tosId) {
            const newUrl = '{{ url('/tos') }}/' + tosId;
            if (window.location.pathname !== newUrl.replace(/^https?:\/\/[^/]+/, '')) {
                history.pushState({ tosId }, '', newUrl);
            }
        }
    }

    async function postForm(url, formData, waitTitle) {
        showOverlay(waitTitle);
        clearFlash();
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: formData,
            });

            const data = await response.json().catch(() => null);

            if (!response.ok) {
                const messages = data?.errors
                    ? Object.values(data.errors).flat()
                    : [data?.message || 'Something went wrong. Please try again.'];
                showFlash('error', messages);
                return false;
            }

            if (data.html) {
                renderResults(data.html, data.tos_id);
            }
            if (data.status) {
                showFlash('success', [data.status]);
            }
            return true;
        } catch (err) {
            showFlash('error', ['Network error — please check your connection and try again.']);
            return false;
        } finally {
            hideOverlay();
        }
    }

    // ---------- Create form ----------
    let lessonCount = 0;
    const panelsContainer = document.getElementById('lesson-panels');
    const template = document.getElementById('lesson-panel-template');
    const addBtn = document.getElementById('add-lesson-btn');
    const form = document.getElementById('tos-form');
    const submitBtn = document.getElementById('submit-btn');
    const submitLabel = document.getElementById('submit-btn-label');
    const submitHint = document.getElementById('submit-wait-hint');

    function renumberPanels() {
        panelsContainer.querySelectorAll('[data-lesson-panel]').forEach((panel, idx) => {
            panel.querySelector('[data-lesson-number]').textContent = 'Lesson ' + (idx + 1);
        });
    }

    function addLessonPanel() {
        const clone = template.content.cloneNode(true);
        const panel = clone.querySelector('[data-lesson-panel]');
        const index = lessonCount++;

        panel.querySelectorAll('[data-field]').forEach((field) => {
            field.setAttribute('name', 'lessons[' + index + '][' + field.getAttribute('data-field') + ']');
        });

        const fileInput = panel.querySelector('[data-field="pdf"]');
        const fileConfirm = panel.querySelector('[data-file-confirm]');
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files[0]) {
                const file = fileInput.files[0];
                fileConfirm.textContent = '✓ ' + file.name + ' (' + (file.size / (1024 * 1024)).toFixed(1) + ' MB)';
                fileConfirm.style.display = 'block';
                fileConfirm.style.color = 'var(--cvsu-green-deep)';
            } else {
                fileConfirm.style.display = 'none';
            }
        });

        panel.querySelector('[data-remove-lesson]').addEventListener('click', function () {
            const titleField = panel.querySelector('[data-field="title"]');
            if (titleField.value.trim() !== '' && !confirm('Remove this lesson? Anything entered for it will be lost.')) {
                return;
            }
            panel.remove();
            renumberPanels();
        });

        panelsContainer.appendChild(clone);
        renumberPanels();
    }

    addBtn.addEventListener('click', addLessonPanel);
    addLessonPanel();
    addLessonPanel();

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        if (panelsContainer.querySelectorAll('[data-lesson-panel]').length === 0) {
            showFlash('error', ['Please add at least one lesson.']);
            return;
        }

        submitBtn.disabled = true;
        submitLabel.textContent = 'Building your TOS\u2026';
        submitHint.style.display = 'block';

        const formData = new FormData(form);
        const ok = await postForm(form.dataset.action, formData, 'Classifying outcomes & building your TOS\u2026');

        submitBtn.disabled = false;
        submitLabel.textContent = 'Classify Outcomes & Build TOS';
        submitHint.style.display = 'none';

        if (ok) {
            form.reset();
            panelsContainer.innerHTML = '';
            lessonCount = 0;
            addLessonPanel();
            addLessonPanel();
        }
    });

    // ---------- Delegated handlers for content inside #results-container ----------
    // (generate-exam form and lesson-retry forms are re-created every time
    // results are swapped in, so we listen on the container, not the forms.)
    resultsContainer.addEventListener('submit', async function (e) {
        const el = e.target;

        if (el.id === 'generate-exam-form') {
            e.preventDefault();
            const btn = el.querySelector('button[type="submit"]');
            btn.disabled = true;
            document.getElementById('generate-wait-hint')?.style.setProperty('display', 'block');
            await postForm(el.dataset.action, new FormData(el), 'Generating your exam\u2026');
            return;
        }

        if (el.matches('[data-lesson-retry-form]')) {
            e.preventDefault();
            const btn = el.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.querySelector('.btn-label').textContent = 'Retrying\u2026';
            await postForm(el.dataset.action, new FormData(el), 'Retrying this lesson\u2026');
        }
    });

    // Back/forward button support: browser navigation between a pushed
    // TOS URL and /tos/create just re-fetches — still no full page reload
    // for the interactions themselves.
    window.addEventListener('popstate', function () {
        window.location.reload();
    });
})();
</script>

@endsection