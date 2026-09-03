window.addEventListener('error', function (e) {
    console.error('[TOS chat] Uncaught error:', e.message, e.filename, e.lineno);
});

(function () {
    console.log('[TOS chat] script starting');

    const thread = document.getElementById('chat-thread');
    const inputBar = document.getElementById('chat-input-bar');
    const textInput = document.getElementById('chat-text-input');
    const attachBtn = document.getElementById('chat-attach-btn');
    const fileInput = document.getElementById('chat-file-input');

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (!csrfToken) {
        console.warn('[TOS chat] No CSRF token found — add a <meta name="csrf-token"> tag to your layout <head>, or form submission later will fail.');
    }

    // URLs are injected via data-* attributes on #chat-shell (see chat.blade.php),
    // since this is a plain .js file and can't run Blade's route()/url() helpers.
    const shellEl = document.getElementById('chat-shell');
    const storeUrl = shellEl?.dataset.storeUrl || '/tos';
    const tosBaseUrl = shellEl?.dataset.tosBaseUrl || '/tos';

    // ---- Conversation state ----
    let step = 'course';           // current question being answered
    let course = '';
    let totalItems = 50;
    let lessons = [];              // finalized lessons: {title, objectives: [{text, weight}], pdfFile}
    let tosId = null;

    // ---- Parses one pasted block of lessons into structured lesson objects ----
    // Expected format, blank line between lessons. Each outcome (ILO) carries
    // its own hours, matching the real CvSU TOS grain — a topic can have
    // several ILOs, each with its own instructional-time allocation:
    //   Lesson: <title>
    //   - Weight: <hours> | <outcome 1>
    //   - Weight: <hours> | <outcome 2>
    function parseLessonsBlock(text) {
        const blocks = text.split(/\n(?=\s*Lesson\s*:)/i).map(function (b) { return b.trim(); }).filter(Boolean);
        const parsed = [];
        const errors = [];

        blocks.forEach(function (block, idx) {
            const lines = block.split('\n').map(function (l) { return l.trim(); }).filter(function (l) { return l !== ''; });
            let title = null;
            const objectives = [];
            const badLines = [];

            lines.forEach(function (line) {
                const titleMatch = line.match(/^Lesson\s*:\s*(.+)$/i);
                if (titleMatch) { title = titleMatch[1].trim(); return; }

                const cleaned = line.replace(/^[-*•]\s*/, '');
                const outcomeMatch = cleaned.match(/^Weight\s*:\s*([\d.]+)\s*\|\s*(.+)$/i);
                if (outcomeMatch) {
                    objectives.push({ weight: parseFloat(outcomeMatch[1]), text: outcomeMatch[2].trim() });
                } else if (cleaned) {
                    badLines.push(cleaned);
                }
            });

            if (!title) { errors.push('Block ' + (idx + 1) + ': missing a "Lesson: <title>" line.'); return; }
            if (badLines.length > 0) {
                errors.push('"' + title + '": each outcome line needs its own hours, like "- Weight: 3 | ' + badLines[0] + '".');
                return;
            }
            if (objectives.length === 0) { errors.push('"' + title + '": no learning outcomes found — add at least one "- Weight: <hours> | <outcome>" line.'); return; }
            const invalidWeight = objectives.find(function (o) { return !o.weight || o.weight <= 0; });
            if (invalidWeight) { errors.push('"' + title + '": every outcome needs a positive hours value.'); return; }

            parsed.push({ title: title, objectives: objectives });
        });

        if (blocks.length === 0) {
            errors.push('I couldn\'t find any lessons — make sure each one starts with a line like "Lesson: <title>".');
        }

        return { lessons: parsed, errors: errors };
    }

    function scrollToBottom() {
        thread.scrollTop = thread.scrollHeight;
    }

    function addBotBubble(text, { quickReplies = null, html = null } = {}) {
        const row = document.createElement('div');
        row.className = 'msg-row bot';
        const bubble = document.createElement('div');
        bubble.className = html ? 'msg-bubble result-card' : 'msg-bubble';
        if (html) {
            bubble.innerHTML = html;
            window.renderMath(bubble);
        } else {
            bubble.textContent = text;
        }
        row.appendChild(bubble);

        if (quickReplies) {
            const qrWrap = document.createElement('div');
            qrWrap.className = 'msg-quick-replies';
            quickReplies.forEach(({ label, value }) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'quick-reply-btn';
                btn.textContent = label;
                btn.dataset.value = value;
                btn.addEventListener('click', function () {
                    qrWrap.querySelectorAll('button').forEach(b => b.disabled = true);
                    handleAnswer(value, label);
                });
                qrWrap.appendChild(btn);
            });
            row.appendChild(qrWrap);
        }

        thread.appendChild(row);
        scrollToBottom();
        return row;
    }

    function addUserBubble(text) {
        const row = document.createElement('div');
        row.className = 'msg-row user';
        const bubble = document.createElement('div');
        bubble.className = 'msg-bubble';
        bubble.textContent = text;
        row.appendChild(bubble);
        thread.appendChild(row);
        scrollToBottom();
    }

    function showTyping() {
        const row = document.createElement('div');
        row.className = 'msg-row bot';
        row.id = 'typing-row';
        row.innerHTML = '<div class="msg-bubble"><span class="typing-indicator"><span></span><span></span><span></span></span></div>';
        thread.appendChild(row);
        scrollToBottom();
    }
    function hideTyping() {
        const el = document.getElementById('typing-row');
        if (el) el.remove();
    }

    function botSay(text, opts) {
        opts = opts || {};
        showTyping();
        return new Promise(function (resolve) {
            setTimeout(function () {
                hideTyping();
                addBotBubble(text, opts);
                resolve();
            }, 300);
        });
    }

    function setInputMode(mode) {
        // mode: 'text' | 'number' | 'file' | 'hidden'
        if (mode === 'hidden') {
            inputBar.style.display = 'none';
            attachBtn.style.display = 'none';
            return;
        }
        inputBar.style.display = 'flex';
        attachBtn.style.display = mode === 'file' ? 'inline-block' : 'none';
        textInput.placeholder = mode === 'file' ? 'Tap 📎 to attach your PDF(s)…' : 'Type your answer…';
        textInput.focus();
    }

    // ---- Question prompts ----
    function askTotalItems() {
        return botSay('Got it — "' + course + '". How many total exam items should the exam have? (5–200, e.g. 50)').then(function () {
            step = 'total_items';
            setInputMode('number');
        });
    }
    function askLessonsBlock() {
        return botSay(
            'Now send me all your lessons in one message — one block per lesson, blank line between them. ' +
            'Each learning outcome gets its own hours (this is how the official TOS splits instructional time per outcome, not per lesson):\n\n' +
            'Lesson: <title>\n- Weight: <hours> | <learning outcome 1>\n- Weight: <hours> | <learning outcome 2>\n\n' +
            'Example:\n\n' +
            'Lesson: Binary Search Trees\n- Weight: 2 | Students will differentiate a BST from a balanced tree.\n- Weight: 1 | Students will design an algorithm to balance an unbalanced tree.\n\n' +
            'Lesson: AVL Trees\n- Weight: 2 | Students will explain rotation operations.\n\n' +
            'Paste as many lessons as you want — I\'ll read them all at once.'
        ).then(function () {
            step = 'lessons_block';
            setInputMode('text');
            textInput.rows = 6;
        });
    }
    function askLessonsPdfs() {
        const list = lessons.map(function (l, i) { return (i + 1) + '. ' + l.title; }).join('\n');
        return botSay(
            'Got it — ' + lessons.length + ' lesson(s):\n' + list + '\n\n' +
            'Now tap 📎 and select all ' + lessons.length + ' PDF(s) together, in that same order — the 1st file you pick goes to lesson 1, and so on.'
        ).then(function () {
            step = 'lessons_pdfs';
            setInputMode('file');
        });
    }

    // ---- Central dispatcher: every user answer (typed or quick-reply) goes through here ----
    function handleAnswer(value, displayLabel) {
        if (displayLabel) addUserBubble(displayLabel);

        if (step === 'course') {
            course = String(value).trim();
            if (!course) return botSay('I need a course name to continue — what course is this for?');
            return askTotalItems();
        }

        if (step === 'total_items') {
            const n = parseInt(value, 10);
            if (!n || n < 5 || n > 200) return botSay('Please give me a number between 5 and 200.');
            totalItems = n;
            return askLessonsBlock();
        }

        if (step === 'lessons_block') {
            const text = String(value).trim();
            if (!text) return botSay('Paste your lessons using the format above — each one needs a title and at least one outcome with its own hours.');

            const result = parseLessonsBlock(text);
            if (result.errors.length) {
                return botSay('I ran into some issues:\n' + result.errors.join('\n') + '\n\nPlease fix and resend the whole block.');
            }

            lessons = result.lessons;
            return askLessonsPdfs();
        }

        if (step === 'lessons_pdfs') {
            return botSay('Please attach the PDFs with the 📎 button rather than typing — I need ' + lessons.length + ' file(s), in lesson order.');
        }

        if (step === 'post_build') {
            if (value === 'generate') return generateExam();
            step = 'idle';
            setInputMode('hidden');
            return botSay('No problem — refresh this page to start a new TOS whenever you\'re ready.');
        }

        // Fallback — nothing to do with free text once idle
        return Promise.resolve();
    }

    function handleFilesAttached(fileList) {
        const files = Array.from(fileList);

        if (files.length !== lessons.length) {
            return botSay(
                'I count ' + files.length + ' file(s), but there ' + (lessons.length === 1 ? 'is' : 'are') + ' ' +
                lessons.length + ' lesson(s). Please select exactly ' + lessons.length + ' PDF(s) together, in lesson order.'
            );
        }

        files.forEach(function (file, i) { lessons[i].pdfFile = file; });

        const summary = files.map(function (file, i) {
            return (i + 1) + '. ' + file.name + ' → "' + lessons[i].title + '"';
        }).join('\n');
        addUserBubble('📎 ' + files.length + ' file(s) attached:\n' + summary);

        return buildTos();
    }

    function buildTos() {
        setInputMode('hidden');
        return botSay('Building your Table of Specification\u2026 this can take a moment.').then(function () {
            const formData = new FormData();
            formData.append('course', course);
            formData.append('total_items', totalItems);
            lessons.forEach(function (lesson, i) {
                // Backend (TosController::classifyAndBuildTos) validates a flat
                // lessons.*.weight (single number) and lessons.*.objectives_text
                // (one outcome per line) — it has no lessons[i][objectives][j][...]
                // shape, so that nested per-objective format was silently ignored,
                // leaving both required fields empty. Collapse to what it expects:
                // lesson weight = sum of its outcomes' weights, objectives_text =
                // newline-joined outcome text (per-outcome weight is dropped here,
                // same as the manual form — the backend has no per-outcome weight).
                const lessonWeight = lesson.objectives.reduce(function (sum, o) { return sum + o.weight; }, 0);
                const objectivesText = lesson.objectives.map(function (o) { return o.text; }).join('\n');

                formData.append('lessons[' + i + '][title]', lesson.title);
                formData.append('lessons[' + i + '][weight]', lessonWeight);
                formData.append('lessons[' + i + '][objectives_text]', objectivesText);
                formData.append('lessons[' + i + '][pdf]', lesson.pdfFile);
            });

            return fetch(storeUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: formData,
            }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (data) {
                    if (!response.ok) {
                        const messages = data && data.errors ? Object.values(data.errors).flat().join(' ') : 'Something went wrong.';
                        return botSay('I ran into a problem building the TOS: ' + messages).then(function () {
                            setInputMode('hidden');
                        });
                    }

                    tosId = data.tos_id;
                    return botSay(data.status || 'TOS built!')
                        .then(function () { return botSay('', { html: data.html }); })
                        .then(function () {
                            return botSay('Want me to generate the exam now?', {
                                quickReplies: [
                                    { label: 'Generate the exam', value: 'generate' },
                                    { label: 'Not yet', value: 'later' },
                                ],
                            });
                        })
                        .then(function () {
                            step = 'post_build';
                            setInputMode('hidden');
                        });
                });
            }).catch(function (err) {
                console.error('[TOS chat] buildTos failed:', err);
                return botSay('Network error while building the TOS — please try again.');
            });
        });
    }

    // ---- Turns a raw "<Lesson Title>: API call failed: { ...json... }" error
    // string into something a teacher can actually read, instead of dumping
    // the provider's raw JSON response into the chat.
    function friendlyLessonError(raw) {
        const label = String(raw).split(':')[0].replace(/^"|"$/g, '').trim() || 'This lesson';
        const jsonMatch = String(raw).match(/\{[\s\S]*\}/);
        let code = null;
        let status = null;

        if (jsonMatch) {
            try {
                const parsed = JSON.parse(jsonMatch[0]);
                code = parsed?.error?.code ?? null;
                status = parsed?.error?.status ?? null;
            } catch (e) {
                // Not parseable JSON — fall through to the generic message below.
            }
        }

        if (status === 'UNAVAILABLE' || code === 503) {
            return '⚠️ "' + label + '" — the AI service is overloaded right now (a temporary capacity spike on the free tier). This usually clears up within a minute — tap "Retry This Lesson" below to try again.';
        }
        if (status === 'RESOURCE_EXHAUSTED' || code === 429) {
            return '⚠️ "' + label + '" — hit the free-tier rate limit. Wait about a minute, then tap "Retry This Lesson" below.';
        }
        if (code) {
            return '⚠️ "' + label + '" — the AI service returned an error (code ' + code + '). Tap "Retry This Lesson" below to try again.';
        }
        return '⚠️ "' + label + '" — something went wrong generating this lesson\'s questions. Tap "Retry This Lesson" below to try again.';
    }

    function generateExam() {
        return botSay('Generating questions for every lesson\u2026 this can take a minute or two.').then(function () {
            return fetch(tosBaseUrl + '/' + tosId + '/generate-exam', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: new URLSearchParams(),
            }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (data) {
                    if (!response.ok && !data) {
                        return botSay('Something went wrong generating the exam — please try again.');
                    }

                    return botSay(data.status || 'Exam generated!')
                        .then(function () { return botSay('', { html: data.html }); })
                        .then(function () {
                            if (data.errors && data.errors.length) {
                                const friendly = data.errors.map(friendlyLessonError).join('\n\n');
                                return botSay(friendly);
                            }
                        })
                        .then(function () {
                            step = 'idle';
                            setInputMode('hidden');
                            return botSay('That\'s your exam! Refresh this page to start a new TOS.');
                        });
                });
            }).catch(function (err) {
                console.error('[TOS chat] generateExam failed:', err);
                return botSay('Network error while generating the exam — please try again.');
            });
        });
    }

    function retryLesson(actionUrl) {
        return fetch(actionUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: new URLSearchParams(),
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (data) {
                if (!response.ok && !data) {
                    return botSay('Something went wrong retrying this lesson — please try again.');
                }

                if (data && data.errors && data.errors.length) {
                    const friendly = data.errors.map(friendlyLessonError).join('\n\n');
                    return botSay(friendly).then(function () {
                        if (data.html) return botSay('', { html: data.html });
                    });
                }

                return botSay(data.status || 'Lesson retried.').then(function () {
                    if (data.html) return botSay('', { html: data.html });
                });
            });
        }).catch(function (err) {
            console.error('[TOS chat] retryLesson failed:', err);
            return botSay('Network error while retrying this lesson — please try again.');
        });
    }

    // ---- Wire up input handlers ----
    inputBar.addEventListener('submit', function (e) {
        e.preventDefault();
        const val = textInput.value;
        if (val.trim() === '') return;
        textInput.value = '';
        textInput.rows = 1;
        handleAnswer(val, val);
    });

    textInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            inputBar.requestSubmit();
        }
    });

    attachBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files.length > 0) {
            handleFilesAttached(fileInput.files);
            fileInput.value = '';
        }
    });

    // The results-card bubbles (rendered via innerHTML from the server's
    // _results.blade.php partial) can contain their own <form> elements —
    // "Generate Exam from All Lesson PDFs" and per-lesson "Retry This
    // Lesson" — that were built for a normal page, not the chat. Without
    // this listener the browser submits them as a real navigation, which
    // looks like the page "going back" or refreshing. Intercept and route
    // them through the same fetch()-based functions the chat buttons use.
    thread.addEventListener('submit', function (e) {
        const el = e.target;

        if (el.id === 'generate-exam-form') {
            e.preventDefault();
            const btn = el.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
            generateExam();
            return;
        }

        if (el.matches('[data-lesson-retry-form]')) {
            e.preventDefault();
            const btn = el.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                const label = btn.querySelector('.btn-label');
                if (label) label.textContent = 'Retrying\u2026';
            }
            retryLesson(el.dataset.action);
        }
    });

    // ---- Sidebar: past TOS history ----
    function loadHistory() {
        const sidebar = document.getElementById('chat-history-sidebar');
        const list = document.getElementById('history-list');
        if (!sidebar || !list) return;

        const historyUrl = sidebar.dataset.historyUrl;
        if (!historyUrl) return;

        fetch(historyUrl, {
            headers: { 'Accept': 'application/json' },
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            const items = (data && data.items) || [];
            if (items.length === 0) {
                list.innerHTML = '<p class="history-empty">No past TOS yet — build your first one!</p>';
                return;
            }
            list.innerHTML = '';
            items.forEach(function (item) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'history-item';
                btn.dataset.tosId = item.id;
                btn.innerHTML =
                    '<span class="history-course">' + escapeHtml(item.course || 'Untitled') + '</span>' +
                    '<span class="history-meta">' + escapeHtml((item.total_items || '?') + ' items · ' + (item.created_at || '')) + '</span>';
                btn.addEventListener('click', function () {
                    loadTosIntoChat(item.id, btn);
                });
                list.appendChild(btn);
            });
        }).catch(function (err) {
            console.error('[TOS chat] Failed to load history:', err);
            list.innerHTML = '<p class="history-empty">Couldn\'t load history.</p>';
        });
    }

    function setActiveHistoryItem(clickedBtn) {
        document.querySelectorAll('.history-item').forEach(function (el) {
            el.classList.remove('active');
        });
        if (clickedBtn) clickedBtn.classList.add('active');
    }

    // Loads a past TOS's results directly into the chat thread — no page
    // navigation. Reuses the same tosBaseUrl + id, just asks for JSON.
    function loadTosIntoChat(id, clickedBtn) {
        setActiveHistoryItem(clickedBtn);
        thread.innerHTML = '';
        setInputMode('hidden');
        showTyping();

        fetch(tosBaseUrl + '/' + id, {
            headers: { 'Accept': 'application/json' },
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            hideTyping();
            tosId = data.tos_id;
            course = data.course || course;
            addBotBubble('Here\'s your Table of Specification for "' + (data.course || 'this course') + '":');
            addBotBubble('', { html: data.html });

            if (data.has_exam) {
                step = 'idle';
                setInputMode('hidden');
            } else {
                addBotBubble('Want me to generate the exam now?', {
                    quickReplies: [
                        { label: 'Generate the exam', value: 'generate' },
                        { label: 'Not yet', value: 'later' },
                    ],
                });
                step = 'post_build';
                setInputMode('hidden');
            }
        }).catch(function (err) {
            hideTyping();
            console.error('[TOS chat] Failed to load TOS:', err);
            addBotBubble('Sorry, I couldn\'t load that TOS — please try again.');
        });
    }

    // Resets the whole conversation back to a blank slate, same as a
    // fresh page load, without actually reloading the page.
    function newChat() {
        setActiveHistoryItem(null);
        thread.innerHTML = '';
        step = 'course';
        course = '';
        totalItems = 50;
        lessons = [];
        tosId = null;
        botSay("Hi! I'll help you build a Table of Specification and generate an exam. What course is this for?")
            .then(function () {
                step = 'course';
                setInputMode('text');
            });
    }

    document.getElementById('new-chat-btn')?.addEventListener('click', newChat);

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    loadHistory();

    // ---- Kick off the conversation ----
    console.log('[TOS chat] script loaded, starting conversation');
    botSay("Hi! I'll help you build a Table of Specification and generate an exam. What course is this for?")
        .then(function () {
            step = 'course';
            setInputMode('text');
        })
        .catch(function (err) {
            console.error('[TOS chat] Failed to start conversation:', err);
        });
})();