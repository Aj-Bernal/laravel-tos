{{-- resources/views/tos/chat.blade.php
     Conversational front-end for the exact same backend (tos.store,
     tos.generate-exam, tos.generate-lesson-exam) — the teacher answers
     one question at a time in a chat thread instead of filling a form.
     Dark CvSU-themed shell. Styles/script live in separate files:
       public/css/tos-chat.css
       public/js/tos-chat.js --}}
@extends('layouts.app')

@section('content')

<div id="chat-page">
    <div id="chat-page-inner">

    <aside id="chat-history-sidebar" data-history-url="{{ route('tos.history') }}" data-tos-base-url="{{ url('/tos') }}">
        <button type="button" id="new-chat-btn">+ New Chat</button>
        <div class="history-header">Past TOS</div>
        <div id="history-list" class="history-list">
            <p class="history-empty">Loading…</p>
        </div>
    </aside>

    <div id="chat-shell"
         data-store-url="{{ route('tos.store') }}"
         data-tos-base-url="{{ url('/tos') }}">

        <div id="chat-topbar">
            <div class="chat-topbar-left">
                <img src="{{ asset('images/cvsu-logo.jpg') }}" alt="CvSU seal" class="cvsu-badge-img">
                <span class="chat-app-name">TOS &amp; Exam Generator</span>
            </div>
            <div class="chat-topbar-right">
                <span class="status-dot" aria-hidden="true"></span>
                <span class="status-label">System online</span>
            </div>
        </div>

        <div id="chat-thread" aria-live="polite"></div>

        <form id="chat-input-bar">
            <button type="button" id="chat-attach-btn" title="Attach PDF" style="display:none;">📎</button>
            <input type="file" id="chat-file-input" accept="application/pdf,image/jpeg,image/png" multiple style="display:none;">
            <textarea id="chat-text-input" rows="1" placeholder="Type your answer…" autocomplete="off"></textarea>
            <button type="submit" id="chat-send-btn">Send</button>
        </form>
    </div>

    </div>
</div>

@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/tos-chat.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/tos-chat.js') }}" defer></script>
@endpush