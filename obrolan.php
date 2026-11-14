<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .chat-bg{background-color:#efeae2;background-image:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23d4cdc3' fill-opacity='0.3'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4z'/%3E%3C/g%3E%3C/svg%3E")}
    .dark .chat-bg{background-color:#0b141a}
    .bubble-out{background:#d9fdd3;color:#111b21}
    .dark .bubble-out{background:#005c4b;color:#e9edef}
    .bubble-in{background:#fff;color:#111b21}
    .dark .bubble-in{background:#202c33;color:#e9edef}
    .bubble-out{border-top-right-radius:0!important}
    .bubble-in{border-top-left-radius:0!important}
    .bubble-out::after{content:'';position:absolute;top:0;right:-8px;border-style:solid;border-width:0 0 10px 10px;border-color:transparent transparent transparent #d9fdd3}
    .dark .bubble-out::after{border-color:transparent transparent transparent #005c4b}
    .bubble-in::after{content:'';position:absolute;top:0;left:-8px;border-style:solid;border-width:0 10px 10px 0;border-color:transparent #fff transparent transparent}
    .dark .bubble-in::after{border-color:transparent #202c33 transparent transparent}
    #chat-box::-webkit-scrollbar, #room-list::-webkit-scrollbar{width:6px}
    #chat-box::-webkit-scrollbar-thumb, #room-list::-webkit-scrollbar-thumb{background:rgba(0,0,0,.2);border-radius:3px}
    .date-chip{background:#e1f2fb;color:#54656f}
    .dark .date-chip{background:#182229;color:#8696a0}
    .msg-hl{animation:hl 1.2s ease-out}
    @keyframes hl{0%{background:rgba(255,193,7,.4)}100%{background:transparent}}
    .quoted-bar{border-left:4px solid #00a884}
    .room-active{background:#f0f2f5}
    .dark .room-active{background:#2a3942}
    .attach-img{max-width:260px;max-height:300px;border-radius:8px;cursor:pointer;display:block}
    .attach-file{display:flex;align-items:center;gap:10px;padding:10px;background:rgba(0,0,0,.05);border-radius:8px;min-width:200px;text-decoration:none;color:inherit;margin-bottom:6px}
    .dark .attach-file{background:rgba(255,255,255,.08)}
    .attach-file-icon{width:40px;height:40px;flex-shrink:0;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:20px}
    .attach-file-info{min-width:0;flex:1}
    .attach-file-name{font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .attach-file-size{font-size:11px;opacity:.6}
    .upload-overlay{position:absolute;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;border-radius:8px;color:white;font-size:12px}
    .spinner-sm{border:2px solid rgba(255,255,255,.3);border-top-color:white;border-radius:50%;width:20px;height:20px;animation:spin 1s linear infinite;display:inline-block}
    @keyframes spin{to{transform:rotate(360deg)}}
    .caption-text{font-size:13px;margin-top:4px;word-break:break-word}
    #preview-modal{backdrop-filter:blur(8px)}
</style>

<div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex h-[88vh] overflow-hidden relative">

    <!-- SIDEBAR -->
    <aside id="sidebar" class="w-full md:w-80 shrink-0 border-r border-gray-200 dark:border-gray-700 flex flex-col bg-white dark:bg-[#111b21] absolute md:relative inset-0 z-20 transition-transform">
        <div class="bg-[#008069] dark:bg-[#202c33] text-white p-4">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <img src="<?= !empty($user['photo']) ? UPLOAD_URL.$user['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($user['name']).'&background=random&size=40' ?>" class="w-9 h-9 rounded-full object-cover">
                    <div class="min-w-0">
                        <div class="font-bold text-sm truncate"><?= e($user['name']) ?></div>
                        <div class="text-xs text-emerald-100"><?= in_array($user['role'], ['pembimbing','teacher']) ? 'Pembimbing' : 'Siswa' ?></div>
                    </div>
                </div>
                <a href="dashboard.php" class="hover:bg-white/10 p-2 rounded-full" title="Kembali">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
            </div>
            <div class="flex gap-1 bg-white/10 rounded-lg p-1 mb-3">
                <button data-room-tab="all" class="room-tab flex-1 px-2 py-1.5 text-xs font-semibold rounded-md bg-white text-[#008069]">Semua</button>
                <button data-room-tab="group" class="room-tab flex-1 px-2 py-1.5 text-xs font-semibold rounded-md text-white">Grup</button>
                <button data-room-tab="private" class="room-tab flex-1 px-2 py-1.5 text-xs font-semibold rounded-md text-white">Pribadi</button>
            </div>
            <div class="relative">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input id="room-search" type="text" placeholder="Cari chat..." class="w-full pl-9 pr-4 py-2 bg-white/20 text-white placeholder-emerald-100 rounded-lg text-sm focus:outline-none focus:bg-white/30">
            </div>
        </div>

        <div id="room-list" class="flex-1 overflow-y-auto">
            <div class="p-4 text-center text-sm text-gray-400">Memuat chat...</div>
        </div>

        <div class="p-3 border-t border-gray-200 dark:border-gray-700 flex gap-2">
            <button onclick="openCreateGroup()" class="flex-1 bg-[#008069] hover:bg-[#017561] text-white text-xs font-semibold py-2.5 rounded-lg flex items-center justify-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Grup Baru
            </button>
            <button onclick="openNewChat()" class="flex-1 bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-semibold py-2.5 rounded-lg flex items-center justify-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                Chat Baru
            </button>
        </div>
    </aside>

    <!-- AREA CHAT -->
    <div class="flex-1 flex flex-col min-w-0 relative">
        <div class="bg-[#008069] dark:bg-[#202c33] text-white px-4 py-3 flex items-center gap-2 shrink-0 shadow z-10">
            <button onclick="toggleSidebar()" class="md:hidden hover:bg-white/10 p-2 rounded-full">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <button onclick="toggleSidebar()" class="hidden md:flex hover:bg-white/10 p-2 rounded-full" title="Daftar chat">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="bg-white/20 p-2 rounded-full shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <h2 id="room-name" class="font-bold text-base leading-tight truncate">Chat</h2>
                <p id="room-subtitle" class="text-emerald-100 dark:text-gray-400 text-xs truncate">Pilih atau buat chat</p>
            </div>
            <button id="search-btn" class="hover:bg-white/10 p-2 rounded-full" title="Cari pesan">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </button>
            <button id="info-btn" class="hover:bg-white/10 p-2 rounded-full" title="Info room">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </button>
        </div>

        <div id="search-bar" class="hidden bg-white dark:bg-[#111b21] px-4 py-2 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
            <input id="search-input" type="text" placeholder="Cari pesan..." class="flex-1 px-4 py-2 bg-gray-100 dark:bg-gray-800 rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-[#008069]">
            <button id="search-close" class="text-gray-500 p-2 hover:bg-gray-100 rounded-full">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="flex-1 p-4 overflow-y-auto chat-bg flex flex-col gap-1" id="chat-box">
            <p class="text-center text-gray-500 dark:text-gray-400 text-sm mt-4" id="loading-msg">Memuat...</p>
        </div>

        <button id="scroll-bottom" class="hidden absolute bottom-28 right-6 bg-white dark:bg-[#202c33] shadow-lg rounded-full p-2.5 z-10 text-gray-600 dark:text-gray-300">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>

        <div id="reply-preview" class="hidden px-4 py-2 bg-white dark:bg-[#202c33] border-t border-gray-200 dark:border-gray-700 flex items-start gap-2">
            <div class="quoted-bar pl-3 flex-1 text-xs min-w-0">
                <div id="reply-to-name" class="font-bold text-[#008069] dark:text-emerald-400"></div>
                <div id="reply-to-text" class="text-gray-600 dark:text-gray-400 truncate"></div>
            </div>
            <button id="reply-close" class="text-gray-500 p-1 hover:bg-gray-100 rounded-full shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="p-2 bg-[#f0f2f5] dark:bg-[#202c33] shrink-0">
            <form id="chat-form" class="flex gap-1 items-end">
                <button type="button" id="attach-btn" class="text-gray-500 dark:text-gray-400 hover:text-[#008069] p-2.5 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                </button>
                <button type="button" id="emoji-btn" class="text-gray-500 dark:text-gray-400 hover:text-yellow-500 p-2.5 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </button>
                <textarea id="chat-input" rows="1" placeholder="Ketik pesan..." class="flex-1 px-4 py-2.5 bg-white dark:bg-[#2a3942] dark:text-white rounded-2xl focus:outline-none focus:ring-2 focus:ring-[#008069] resize-none max-h-32" disabled></textarea>
                <button type="submit" id="send-btn" class="bg-[#008069] hover:bg-[#017561] text-white w-11 h-11 rounded-full flex items-center justify-center shrink-0 disabled:opacity-50" disabled>
                    <svg class="w-5 h-5 -ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </button>
            </form>
            <div id="emoji-panel" class="hidden mt-2 p-2 bg-white dark:bg-[#2a3942] rounded-xl shadow-inner grid grid-cols-8 gap-1 text-xl max-h-40 overflow-y-auto"></div>
            <div id="attach-panel" class="hidden mt-2 p-2 bg-white dark:bg-[#2a3942] rounded-xl shadow-inner flex gap-2">
                <label class="flex-1 flex flex-col items-center gap-1 p-3 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
                    <svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span class="text-xs dark:text-gray-300">Gambar</span>
                    <input type="file" id="file-image" accept="image/*" class="hidden">
                </label>
                <label class="flex-1 flex flex-col items-center gap-1 p-3 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
                    <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span class="text-xs dark:text-gray-300">File</span>
                    <input type="file" id="file-doc" class="hidden">
                </label>
            </div>
        </div>
    </div>
</div>

<!-- PREVIEW IMAGE -->
<div id="preview-modal" class="hidden fixed inset-0 bg-black/95 z-[60] items-center justify-center p-4">
    <button onclick="closePreviewImage()" class="absolute top-4 right-4 text-white p-2 hover:bg-white/10 rounded-full z-10">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
    <img id="preview-img" class="max-w-full max-h-full object-contain rounded-lg">
</div>

<!-- MODAL BUAT GRUP -->
<div id="create-group-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-md shadow-2xl flex flex-col max-h-[85vh]">
        <div class="flex justify-between items-center px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h3 class="font-bold text-gray-800 dark:text-white">Buat Grup Baru</h3>
            <button onclick="closeCreateGroup()" class="text-gray-400 hover:text-gray-600 p-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-5 overflow-y-auto flex-1">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nama Grup *</label>
                <input type="text" id="group-name" placeholder="Contoh: Tim PKL Minggu 1" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-[#008069] outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Pilih Anggota</label>
                <div id="member-list" class="space-y-1 max-h-64 overflow-y-auto border border-gray-200 dark:border-gray-600 rounded-lg p-2">
                    <p class="text-sm text-gray-400 text-center py-2">Memuat...</p>
                </div>
            </div>
        </div>
        <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700 flex justify-end gap-2">
            <button onclick="closeCreateGroup()" class="px-4 py-2 text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg font-medium">Batal</button>
            <button onclick="submitCreateGroup()" class="bg-[#008069] hover:bg-[#017561] text-white px-5 py-2 rounded-lg font-medium">Buat Grup</button>
        </div>
    </div>
</div>

<!-- MODAL CHAT BARU -->
<div id="new-chat-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-md shadow-2xl flex flex-col max-h-[85vh]">
        <div class="flex justify-between items-center px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h3 class="font-bold text-gray-800 dark:text-white">Mulai Chat Baru</h3>
            <button onclick="closeNewChat()" class="text-gray-400 hover:text-gray-600 p-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-5 overflow-y-auto">
            <div id="users-list" class="space-y-1">
                <p class="text-sm text-gray-400 text-center py-4">Memuat...</p>
            </div>
        </div>
    </div>
</div>

<audio id="notif-sound" preload="auto"><source src="data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=" type="audio/wav"></audio>
<div id="context-menu" class="hidden fixed z-50 bg-white dark:bg-[#233138] rounded-lg shadow-xl overflow-hidden min-w-[180px]"></div>

<script>
const BASE_URL = '<?= BASE_URL ?>';
const chatBox = document.getElementById('chat-box');
const chatForm = document.getElementById('chat-form');
const chatInput = document.getElementById('chat-input');
const sendBtn = document.getElementById('send-btn');
const contextMenu = document.getElementById('context-menu');
const replyPreview = document.getElementById('reply-preview');
const scrollBtn = document.getElementById('scroll-bottom');
const sidebar = document.getElementById('sidebar');

let currentRoomId = 0;
let currentRoomName = '';
let currentRoomType = '';
let lastRenderedId = 0;
let lastDate = null;
let isAtBottom = true;
let replyTo = null;
let editingId = null;
let currentRoomTab = 'all';
let allRooms = [];
let allUsers = [];

function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

function formatBytes(b){
    if(!b || b == 0) return '0 B';
    if(b < 1024) return b + ' B';
    if(b < 1048576) return (b/1024).toFixed(1) + ' KB';
    if(b < 1073741824) return (b/1048576).toFixed(1) + ' MB';
    return (b/1073741824).toFixed(2) + ' GB';
}

function openPreviewImage(src){
    const modal = document.getElementById('preview-modal');
    document.getElementById('preview-img').src = src;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closePreviewImage(){
    const modal = document.getElementById('preview-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closePreviewImage();
});

function toggleSidebar(){
    if(window.innerWidth < 768){ sidebar.classList.toggle('hidden'); }
    else { sidebar.classList.toggle('md:w-0'); sidebar.classList.toggle('overflow-hidden'); }
}

function showEmptyState(){
    chatInput.disabled = true;
    sendBtn.disabled = true;
    document.getElementById('room-name').textContent = 'Chat';
    document.getElementById('room-subtitle').textContent = 'Pilih atau buat chat';
    chatBox.innerHTML = `
        <div class="flex flex-col items-center justify-center h-full text-center px-4 py-16">
            <div class="text-6xl mb-4">💬</div>
            <h3 class="text-lg font-bold text-gray-700 dark:text-gray-300 mb-2">Belum ada chat</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6 max-w-xs">Buat grup baru atau mulai chat pribadi dengan teman.</p>
            <div class="flex gap-2 flex-wrap justify-center">
                <button onclick="openCreateGroup()" class="bg-[#008069] hover:bg-[#017561] text-white px-4 py-2.5 rounded-lg font-medium text-sm flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>Buat Grup
                </button>
                <button onclick="openNewChat()" class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2.5 rounded-lg font-medium text-sm flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>Chat Baru
                </button>
            </div>
        </div>`;
}

async function loadRooms(){
    try{
        const res = await fetch(BASE_URL+'/api/obrolan_rooms.php', {credentials:'same-origin'});
        allRooms = await res.json();
        renderRooms();
        return allRooms;
    }catch(e){ console.error(e); return []; }
}

function renderRooms(){
    const q = document.getElementById('room-search').value.toLowerCase().trim();
    const list = document.getElementById('room-list');
    let filtered = allRooms.filter(r => currentRoomTab === 'all' || r.type === currentRoomTab);
    if(q) filtered = filtered.filter(r => (r.name||'').toLowerCase().includes(q));

    if(filtered.length === 0){
        list.innerHTML = `<div class="p-6 text-center text-sm text-gray-400">
            <div class="text-3xl mb-2">📭</div>
            Belum ada chat.<br>Buat grup atau mulai chat baru.
        </div>`;
        return;
    }
    list.innerHTML = filtered.map(r => {
        const active = r.id == currentRoomId ? 'room-active' : '';
        const photo = r.photo ? UPLOAD_URL+r.photo : `https://ui-avatars.com/api/?name=${encodeURIComponent(r.name||'Grup')}&background=random&size=40`;
        const icon = r.type === 'group' ? '👥' : '👤';
        const preview = r.last_msg ? `${r.last_sender||''}: ${r.last_msg.substring(0,30)}` : 'Belum ada pesan';
        return `<div onclick="switchRoom(${r.id}, '${esc(r.name||'Chat')}', '${r.type}')" class="flex items-center gap-3 p-3 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer border-b border-gray-100 dark:border-gray-700 ${active}">
            <img src="${photo}" class="w-10 h-10 rounded-full object-cover shrink-0">
            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-center gap-2">
                    <div class="font-semibold text-sm text-gray-800 dark:text-white truncate">${icon} ${esc(r.name||'Grup')}</div>
                    ${r.unread > 0 ? `<span class="bg-green-500 text-white text-xs rounded-full px-1.5 py-0.5 shrink-0">${r.unread}</span>` : ''}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 truncate">${esc(preview)}</div>
            </div>
        </div>`;
    }).join('');
}

function switchRoom(id, name, type){
    currentRoomId = id;
    currentRoomName = name || 'Chat';
    currentRoomType = type || 'group';
    lastRenderedId = 0; lastDate = null;
    chatBox.innerHTML = '<p class="text-center text-gray-500 text-sm mt-4" id="loading-msg">Memuat...</p>';
    document.getElementById('room-name').textContent = name || 'Chat';
    document.getElementById('room-subtitle').textContent = type === 'group' ? 'Grup' : 'Pribadi';
    chatInput.disabled = false;
    sendBtn.disabled = false;
    renderRooms();
    fetchMessages();
    if(window.innerWidth < 768) sidebar.classList.add('hidden');
}

async function fetchMessages(){
    if(!currentRoomId) return;
    try{
        const res = await fetch(`${BASE_URL}/api/obrolan_fetch.php?room_id=${currentRoomId}`, {credentials:'same-origin', cache:'no-store'});
        const text = await res.text();
        let messages;
        try { messages = JSON.parse(text); } catch(e){ console.error(text.substring(0,200)); document.getElementById('loading-msg')?.remove(); return; }
        document.getElementById('loading-msg')?.remove();

        if(!Array.isArray(messages)) return;
        if(messages.length === 0 && lastRenderedId === 0){
            chatBox.innerHTML = '<p class="text-center text-gray-500 dark:text-gray-400 text-sm mt-8">Belum ada pesan. Mulai percakapan! 👋</p>';
            return;
        }

        const newCount = messages.filter(m=>m.id>lastRenderedId && !m.is_me).length;
        if(newCount>0 && lastRenderedId>0){
            try{ document.getElementById('notif-sound').play(); }catch(e){}
        }

        let html = '', prevSender = null, newLastId = lastRenderedId;
        messages.forEach(msg => {
            if(msg.id <= lastRenderedId){ prevSender = msg.name; return; }
            html += renderMessage(msg, prevSender);
            prevSender = msg.name;
            newLastId = msg.id;
        });
        if(html){
            if(chatBox.querySelector('p.text-center')) chatBox.innerHTML = '';
            chatBox.insertAdjacentHTML('beforeend', html);
            lastRenderedId = newLastId;
            scrollToBottom();
        }

        fetch(`${BASE_URL}/api/obrolan_mark_read.php`, {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({room_id: currentRoomId})});
        loadRooms();
    }catch(e){ console.error(e); document.getElementById('loading-msg')?.remove(); }
}

function scrollToBottom(force=false){ if(force||isAtBottom) chatBox.scrollTop = chatBox.scrollHeight; }
chatBox.addEventListener('scroll', () => {
    isAtBottom = chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < 80;
    scrollBtn.classList.toggle('hidden', isAtBottom);
});
scrollBtn.onclick = () => { chatBox.scrollTop = chatBox.scrollHeight; isAtBottom = true; scrollBtn.classList.add('hidden'); };

function dateLabel(d){
    const dt = new Date(d), today = new Date(), yest = new Date(); yest.setDate(today.getDate()-1);
    const same=(a,b)=>a.toDateString()===b.toDateString();
    if(same(dt,today)) return 'Hari Ini';
    if(same(dt,yest)) return 'Kemarin';
    return dt.toLocaleDateString('id-ID',{day:'numeric',month:'long',year:'numeric'});
}

function renderMessage(msg, prevSender){
    const dateKey = new Date(msg.created_at_full).toDateString();
    let html = '';
    if(dateKey !== lastDate){
        html += `<div class="text-center my-3"><span class="date-chip text-[11px] px-3 py-1 rounded-lg shadow-sm">${dateLabel(msg.created_at_full)}</span></div>`;
        lastDate = dateKey; prevSender = null;
    }
    const showAvatar = !msg.is_me && prevSender !== msg.name;
    const bubbleClass = msg.is_me ? 'bubble-out' : 'bubble-in';
    const wrapperClass = msg.is_me ? 'ml-auto flex-row-reverse' : '';
    const ticks = msg.is_me ? `<svg class="w-3.5 h-3.5 inline-block ml-1 ${msg.is_read?'text-blue-500':'text-gray-400'}" viewBox="0 0 16 15" fill="currentColor"><path d="M15.01 3.316l-.478-.372a.365.365 0 00-.51.063L8.666 9.879a.32.32 0 01-.484.033l-.358-.325a.32.32 0 00-.484.032l-.378.483a.418.418 0 00.036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.366.366 0 00-.064-.512z"/></svg>` : '';

    let replyHtml = '';
    if(msg.reply_to_id && msg.reply_content){
        replyHtml = `<div class="quoted-bar bg-black/5 dark:bg-black/30 rounded pl-2 py-1 mb-1 text-xs"><div class="font-bold text-[#008069] dark:text-emerald-400">${esc(msg.reply_name||'User')}</div><div class="truncate text-gray-600 dark:text-gray-400">${esc(msg.reply_content)}</div></div>`;
    }

    let contentHtml = '';
    if(msg.deleted_at){
        contentHtml = `<i class="text-gray-500 text-sm">🚫 Pesan telah dihapus</i>`;
    }
    else if(msg.attachment_type === 'image'){
        contentHtml = `
            <div class="relative">
                <img src="${esc(msg.attachment)}" class="attach-img" onclick="openPreviewImage('${esc(msg.attachment)}')" loading="lazy">
            </div>`;
        if(msg.content) contentHtml += `<div class="caption-text">${esc(msg.content)}</div>`;
    }
    else if(msg.attachment_type === 'audio'){
        contentHtml = `
            <div class="flex items-center gap-2 bg-black/5 dark:bg-white/10 rounded-lg p-2 mb-1">
                <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center text-white">🎤</div>
                <audio controls src="${esc(msg.attachment)}" class="h-8" style="max-width:200px"></audio>
            </div>`;
        if(msg.content) contentHtml += `<div class="caption-text">${esc(msg.content)}</div>`;
    }
    else if(msg.attachment_type === 'file'){
        const ext = (msg.file_name || '').split('.').pop().toLowerCase();
        const fileIcons = {
            'pdf': ['📄', '#ef4444'],
            'doc': ['📝', '#3b82f6'], 'docx': ['📝', '#3b82f6'],
            'xls': ['📊', '#22c55e'], 'xlsx': ['📊', '#22c55e'],
            'ppt': ['📽️', '#f97316'], 'pptx': ['📽️', '#f97316'],
            'zip': ['🗜️', '#a855f7'], 'rar': ['🗜️', '#a855f7'],
            'txt': ['📃', '#6b7280'], 'csv': ['📊', '#22c55e']
        };
        const [icon, color] = fileIcons[ext] || ['📎', '#6b7280'];

        contentHtml = `
            <a href="${esc(msg.attachment)}" download="${esc(msg.file_name)}" class="attach-file">
                <div class="attach-file-icon" style="background:${color}20;color:${color}">${icon}</div>
                <div class="attach-file-info">
                    <div class="attach-file-name">${esc(msg.file_name || 'file')}</div>
                    <div class="attach-file-size">${formatBytes(msg.file_size)}</div>
                </div>
                <svg class="w-5 h-5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            </a>`;
        if(msg.content) contentHtml += `<div class="caption-text">${esc(msg.content)}</div>`;
    }
    else {
        contentHtml = `<div class="whitespace-pre-wrap break-words pr-14">${esc(msg.content)}</div>`;
    }

    const editedLabel = msg.edited_at ? `<span class="text-[10px] italic text-gray-500 ml-1">(diedit)</span>` : '';

    html += `<div class="flex items-end gap-1.5 max-w-[85%] md:max-w-[65%] ${wrapperClass} ${showAvatar?'mt-3':'mt-1'}" data-msg-id="${msg.id}" data-msg-name="${esc(msg.name)}" data-msg-content="${esc(msg.content)}" data-msg-ismine="${msg.is_me}" data-msg-deleted="${msg.deleted_at?1:0}">
        ${!msg.is_me ? `<div class="w-8 shrink-0">${showAvatar?`<img src="${esc(msg.photo)}" class="w-8 h-8 rounded-full object-cover">`:''}</div>` : ''}
        <div class="relative min-w-0">
            ${showAvatar?`<div class="text-[11px] font-semibold text-[#008069] dark:text-emerald-400 mb-1 px-1">${esc(msg.name)}</div>`:''}
            <div class="relative px-2.5 py-1.5 text-sm rounded-lg shadow-sm ${bubbleClass} msg-bubble" oncontextmenu="openMenu(event, this.parentElement.parentElement)">
                ${replyHtml}${contentHtml}
                <div class="absolute bottom-1 right-2 flex items-center gap-1 text-[10px] ${msg.is_me?'text-gray-600/70':'text-gray-500'}"><span>${esc(msg.created_at)}</span>${editedLabel}${ticks}</div>
            </div>
        </div>
    </div>`;
    return html;
}

chatInput.addEventListener('input', () => { chatInput.style.height='auto'; chatInput.style.height=Math.min(chatInput.scrollHeight,128)+'px'; });
chatInput.addEventListener('keydown', e => { if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); chatForm.requestSubmit(); } });

chatForm.addEventListener('submit', async e => {
    e.preventDefault();
    if(!currentRoomId) return;
    const content = chatInput.value.trim();
    if(!content && !editingId) return;

    if(editingId){
        const res = await fetch(BASE_URL+'/api/obrolan_edit.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({id:editingId, content})});
        const r = await res.json();
        if(r.status === 'success'){ editingId = null; chatInput.value=''; chatInput.style.height='auto'; cancelReply(); lastRenderedId=0; chatBox.innerHTML=''; lastDate=null; await fetchMessages(); scrollToBottom(true); }
        return;
    }

    sendBtn.disabled = true;
    try {
        const body = {content, room_id: currentRoomId};
        if(replyTo) body.reply_to_id = replyTo.id;
        const res = await fetch(BASE_URL+'/api/obrolan_send.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify(body)});
        const r = await res.json();
        if(r.status === 'success'){ chatInput.value=''; chatInput.style.height='auto'; cancelReply(); await fetchMessages(); scrollToBottom(true); }
    } catch(err){ console.error(err); }
    finally { sendBtn.disabled = false; chatInput.focus(); }
});

function setReply(el){
    replyTo = {id: el.dataset.msgId, name: el.dataset.msgName, content: el.dataset.msgContent || '[media]'};
    document.getElementById('reply-to-name').textContent = replyTo.name;
    document.getElementById('reply-to-text').textContent = replyTo.content;
    replyPreview.classList.remove('hidden');
    chatInput.focus();
}
function cancelReply(){ replyTo = null; editingId = null; replyPreview.classList.add('hidden'); }
document.getElementById('reply-close').onclick = cancelReply;

function openMenu(e, msgEl){
    e.preventDefault();
    const id = msgEl.dataset.msgId;
    const isMine = msgEl.dataset.msgIsmine === '1';
    if(msgEl.dataset.msgDeleted === '1') return;
    const items = [
        {label:'Balas', icon:'↩️', action: () => setReply(msgEl)},
        {label:'Salin', icon:'📋', action: () => navigator.clipboard.writeText(msgEl.dataset.msgContent)},
    ];
    if(isMine){
        items.push({label:'Edit', icon:'✏️', action: () => { editingId = id; chatInput.value = msgEl.dataset.msgContent; chatInput.focus(); document.getElementById('reply-to-name').textContent = '✏️ Edit'; document.getElementById('reply-to-text').textContent = msgEl.dataset.msgContent; replyPreview.classList.remove('hidden'); }});
        items.push({label:'Hapus', icon:'🗑️', action: async () => { if(!confirm('Hapus pesan ini?')) return; await fetch(BASE_URL+'/api/obrolan_delete.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({id})}); lastRenderedId=0; chatBox.innerHTML=''; lastDate=null; await fetchMessages(); }});
    }
    let html = items.map((item, i) => `<button class="menu-item w-full text-left px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center gap-2 dark:text-gray-200" data-idx="${i}">${item.icon} ${item.label}</button>`).join('');
    contextMenu.innerHTML = html;
    contextMenu.classList.remove('hidden');
    contextMenu.style.left = Math.min(e.clientX, window.innerWidth-200) + 'px';
    contextMenu.style.top = Math.min(e.clientY, window.innerHeight-200) + 'px';
    contextMenu.querySelectorAll('.menu-item').forEach(btn => { btn.onclick = () => { items[+btn.dataset.idx].action(); hideMenu(); }; });
}
function hideMenu(){ contextMenu.classList.add('hidden'); }
document.addEventListener('click', e => { if(!contextMenu.contains(e.target)) hideMenu(); });

['😀','😁','😂','🤣','😃','😄','😅','😆','😉','😊','😋','😎','😍','😘','🥰','😗','😙','😚','🙂','🤗','🤩','🤔','🤨','😐','😑','😶','🙄','😏','😣','😥','😮','🤐','😯','😪','😫','🥱','😴','😌','😛','😜','😝','🤤','😒','😓','😔','😕','🙃','🤑','😲','🙁','😖','😞','😟','😤','😢','😭','😦','😧','😨','😩','🤯','😬','😰','😱','🥵','🥶','😳','🤪','😵','🥴','😠','😡','🤬','😷','🤒','🤕','🤢','🤮','🤧','😇','🥳','🥺','🤠','🤡','🤥','🤫','🤭','🧐','🤓','👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','✋','🤚','🖐️','🖖','👋','🤝','🙏','💪','❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','🔥','⭐','✨','🎉','🎊','🎈','🎁','🏆','🥇','💯','✅','❌','⚡','💡','📌','📍','📎','🔗'].forEach(e => {
    const b = document.createElement('button');
    b.type='button'; b.textContent=e;
    b.className='hover:bg-gray-100 dark:hover:bg-gray-700 rounded p-1';
    b.onclick = () => { chatInput.value += e; chatInput.focus(); chatInput.dispatchEvent(new Event('input')); };
    document.getElementById('emoji-panel').appendChild(b);
});
document.getElementById('emoji-btn').onclick = () => { document.getElementById('emoji-panel').classList.toggle('hidden'); document.getElementById('attach-panel').classList.add('hidden'); };
document.getElementById('attach-btn').onclick = () => { document.getElementById('attach-panel').classList.toggle('hidden'); document.getElementById('emoji-panel').classList.add('hidden'); };

document.getElementById('file-image').onchange = e => uploadFile(e.target.files[0], 'image');
document.getElementById('file-doc').onchange = e => uploadFile(e.target.files[0], 'file');

async function uploadFile(file, type){
    if(!file || !currentRoomId) return;

    if (file.size > 10 * 1024 * 1024) {
        alert('File maksimal 10MB');
        return;
    }

    const caption = chatInput.value.trim();

    const tempId = 'temp_' + Date.now();
    const tempHtml = `
        <div class="flex items-end gap-1.5 max-w-[85%] md:max-w-[65%] ml-auto flex-row-reverse mt-3" id="${tempId}">
            <div class="relative min-w-0">
                <div class="relative px-2.5 py-1.5 text-sm rounded-lg shadow-sm bubble-out">
                    ${type === 'image'
                        ? `<img src="${URL.createObjectURL(file)}" class="attach-img opacity-70">`
                        : `<div class="attach-file opacity-70">
                            <div class="attach-file-icon" style="background:#3b82f620;color:#3b82f6">📎</div>
                            <div class="attach-file-info">
                                <div class="attach-file-name">${esc(file.name)}</div>
                                <div class="attach-file-size">${formatBytes(file.size)}</div>
                            </div>
                        </div>`}
                    ${caption ? `<div class="caption-text">${esc(caption)}</div>` : ''}
                    <div class="upload-overlay"><div class="spinner-sm"></div></div>
                </div>
            </div>
        </div>`;
    chatBox.insertAdjacentHTML('beforeend', tempHtml);
    chatBox.scrollTop = chatBox.scrollHeight;

    const fd = new FormData();
    fd.append('file', file);
    fd.append('type', type);
    fd.append('room_id', currentRoomId);
    if(caption) fd.append('caption', caption);
    if(replyTo) fd.append('reply_to_id', replyTo.id);

    document.getElementById('attach-panel').classList.add('hidden');
    sendBtn.disabled = true;

    try {
        const res = await fetch(BASE_URL+'/api/obrolan_upload.php', {method:'POST', body: fd, credentials:'same-origin'});
        const r = await res.json();

        document.getElementById(tempId)?.remove();

        if(r.status === 'success'){
            chatInput.value = '';
            chatInput.style.height = 'auto';
            cancelReply();
            lastRenderedId = 0;
            chatBox.innerHTML = '';
            lastDate = null;
            await fetchMessages();
            scrollToBottom(true);
        } else {
            alert('Upload gagal: ' + (r.message || 'Error'));
        }
    } catch(err){
        document.getElementById(tempId)?.remove();
        console.error(err);
        alert('Upload error. Cek koneksi.');
    }
    finally {
        sendBtn.disabled = false;
        chatInput.focus();
    }
}

document.getElementById('search-btn').onclick = () => { document.getElementById('search-bar').classList.toggle('hidden'); document.getElementById('search-input').focus(); };
document.getElementById('search-close').onclick = () => { document.getElementById('search-bar').classList.add('hidden'); document.getElementById('search-input').value=''; document.querySelectorAll('.msg-hl').forEach(e => e.classList.remove('msg-hl')); };
document.getElementById('search-input').addEventListener('input', e => {
    const q = e.target.value.toLowerCase().trim();
    document.querySelectorAll('.msg-bubble').forEach(b => b.classList.remove('msg-hl'));
    if(!q) return;
    document.querySelectorAll('[data-msg-id]').forEach(el => { if(el.dataset.msgContent.toLowerCase().includes(q)) el.querySelector('.msg-bubble').classList.add('msg-hl'); });
});

document.querySelectorAll('.room-tab').forEach(btn => {
    btn.onclick = () => {
        document.querySelectorAll('.room-tab').forEach(b => { b.classList.remove('bg-white','text-[#008069]'); b.classList.add('text-white'); });
        btn.classList.add('bg-white','text-[#008069]'); btn.classList.remove('text-white');
        currentRoomTab = btn.dataset.roomTab;
        renderRooms();
    };
});
document.getElementById('room-search').addEventListener('input', renderRooms);

async function openCreateGroup(){
    document.getElementById('create-group-modal').classList.remove('hidden');
    document.getElementById('group-name').value = '';
    const list = document.getElementById('member-list');
    list.innerHTML = '<p class="text-sm text-gray-400 text-center py-2">Memuat...</p>';
    try {
        const res = await fetch(BASE_URL+'/api/obrolan_users.php', {credentials:'same-origin'});
        allUsers = await res.json();
        list.innerHTML = allUsers.map(u => `<label class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
            <input type="checkbox" value="${u.id}" class="member-check w-4 h-4 text-[#008069] rounded">
            <img src="${esc(u.photo_url)}" class="w-8 h-8 rounded-full object-cover">
            <span class="text-sm font-medium text-gray-800 dark:text-gray-200">${esc(u.name)}</span>
            <span class="text-xs text-gray-400 ml-auto">${(u.role==='teacher'||u.role==='pembimbing')?'Pembimbing':'Siswa'}</span>
        </label>`).join('');
    } catch(e){ list.innerHTML = '<p class="text-sm text-red-500 text-center py-2">Gagal memuat</p>'; }
}
function closeCreateGroup(){ document.getElementById('create-group-modal').classList.add('hidden'); }

async function submitCreateGroup(){
    const name = document.getElementById('group-name').value.trim();
    const members = Array.from(document.querySelectorAll('.member-check:checked')).map(c => parseInt(c.value));
    if(!name){ alert('Nama grup wajib diisi'); return; }
    if(members.length === 0){ alert('Pilih minimal 1 anggota'); return; }
    try {
        const res = await fetch(BASE_URL+'/api/obrolan_create_group.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({name, members})});
        const r = await res.json();
        if(r.status === 'success'){ closeCreateGroup(); await loadRooms(); switchRoom(r.room_id, name, 'group'); }
        else alert('Gagal: '+(r.message||''));
    } catch(e){ alert('Koneksi error'); }
}

async function openNewChat(){
    document.getElementById('new-chat-modal').classList.remove('hidden');
    const list = document.getElementById('users-list');
    list.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">Memuat...</p>';
    try {
        const res = await fetch(BASE_URL+'/api/obrolan_users.php', {credentials:'same-origin'});
        allUsers = await res.json();
        list.innerHTML = allUsers.map(u => `<div onclick="startPrivate(${u.id}, '${esc(u.name)}')" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
            <img src="${esc(u.photo_url)}" class="w-10 h-10 rounded-full object-cover">
            <div class="flex-1 min-w-0">
                <div class="font-semibold text-sm text-gray-800 dark:text-white truncate">${esc(u.name)}</div>
                <div class="text-xs text-gray-400">${(u.role==='teacher'||u.role==='pembimbing')?'Pembimbing':'Siswa'}</div>
            </div>
        </div>`).join('');
    } catch(e){ list.innerHTML = '<p class="text-sm text-red-500 text-center py-4">Gagal memuat</p>'; }
}
function closeNewChat(){ document.getElementById('new-chat-modal').classList.add('hidden'); }

async function startPrivate(userId, name){
    try {
        const res = await fetch(BASE_URL+'/api/obrolan_create_private.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({user_id: userId})});
        const r = await res.json();
        if(r.status === 'success'){ closeNewChat(); await loadRooms(); switchRoom(r.room_id, name, 'private'); }
        else alert('Gagal: '+(r.message||''));
    } catch(e){ alert('Koneksi error'); }
}

document.getElementById('info-btn').onclick = () => {
    if(!currentRoomId){ return; }
    alert('Room: ' + currentRoomName + ' (' + currentRoomType + ')');
};

async function init(){
    const rooms = await loadRooms();
    if(rooms.length > 0){
        switchRoom(rooms[0].id, rooms[0].name || 'Chat', rooms[0].type);
    } else {
        showEmptyState();
    }
}
init();
setInterval(() => { if(currentRoomId) fetchMessages(); }, 3000);
setInterval(loadRooms, 5000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>