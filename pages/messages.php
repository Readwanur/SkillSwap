<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = intval($_SESSION['user_id']);
$page_title = 'Conversations';

$start_with_user_id = isset($_GET['start_with_user_id']) ? intval($_GET['start_with_user_id']) : 0;
$active_conversation_id = 0;

if ($start_with_user_id > 0 && $start_with_user_id !== $user_id) {
    // Check if recipient exists
    $recipient_res = $conn->query("SELECT name FROM users WHERE user_id = $start_with_user_id");
    if ($recipient_res && $recipient_res->num_rows > 0) {
        // Check if conversation exists
        $check_conv = $conn->query("
            SELECT cm1.conversation_id 
            FROM conversation_members cm1
            INNER JOIN conversation_members cm2 ON cm1.conversation_id = cm2.conversation_id
            WHERE cm1.user_id = $user_id AND cm2.user_id = $start_with_user_id
            LIMIT 1
        ");
        if ($check_conv && $check_conv->num_rows > 0) {
            $active_conversation_id = intval($check_conv->fetch_assoc()['conversation_id']);
            // Ensure the conversation is unhidden for this user when explicitly started
            $conn->query("UPDATE conversation_members SET is_hidden = 0 WHERE conversation_id = $active_conversation_id AND user_id = $user_id");
        } else {
            // Create new conversation
            $conn->begin_transaction();
            try {
                $conn->query("INSERT INTO conversations () VALUES ()");
                $active_conversation_id = $conn->insert_id;
                
                $conn->query("INSERT INTO conversation_members (conversation_id, user_id) VALUES ($active_conversation_id, $user_id)");
                $conn->query("INSERT INTO conversation_members (conversation_id, user_id) VALUES ($active_conversation_id, $start_with_user_id)");
                
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $active_conversation_id = 0;
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Chat Portal Styles */
.chat-container {
    display: flex;
    height: 70vh;
    min-height: 550px;
    max-height: 800px;
    background: var(--bg-card);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--border-light);
    overflow: hidden;
    margin-top: 15px;
    margin-bottom: 30px;
}

.chat-sidebar {
    width: 350px;
    border-right: 1px solid var(--border-light);
    display: flex;
    flex-direction: column;
    background: var(--bg-secondary);
    transition: transform 0.3s ease;
}

.chat-sidebar-header {
    padding: 16px;
    border-bottom: 1px solid var(--border-light);
    background: var(--bg-primary);
}

.chat-sidebar-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0;
    color: var(--primary);
}

.chat-list {
    flex: 1;
    overflow-y: auto;
}

.chat-item {
    display: flex;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid var(--border-light);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    gap: 12px;
    animation: fadeInItem 0.5s ease backwards;
}

.chat-item:nth-child(1) { animation-delay: 0.05s; }
.chat-item:nth-child(2) { animation-delay: 0.1s; }
.chat-item:nth-child(3) { animation-delay: 0.15s; }
.chat-item:nth-child(4) { animation-delay: 0.2s; }
.chat-item:nth-child(5) { animation-delay: 0.25s; }
.chat-item:nth-child(n+6) { animation-delay: 0.3s; }

@keyframes fadeInItem {
    from { opacity: 0; transform: translateX(-10px); }
    to { opacity: 1; transform: translateX(0); }
}

.chat-item:hover {
    background: var(--bg-hover);
    padding-left: 20px;
}

.chat-item.active {
    background: var(--surface-container-low);
    border-left: 4px solid var(--primary);
    padding-left: 20px;
}

.chat-item-details {
    flex: 1;
    min-width: 0; /* for truncation */
}

.chat-item-header {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 4px;
}

.chat-item-name {
    font-family: var(--font-headline);
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-item-time {
    font-size: 0.75rem;
    color: var(--text-muted);
    white-space: nowrap;
}

.chat-item-preview {
    font-size: 0.85rem;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-item.active .chat-item-preview {
    color: var(--text-primary);
    font-weight: 500;
}

.chat-item-unread {
    background: var(--danger);
    color: #ffffff;
    font-size: 0.7rem;
    font-weight: 700;
    border-radius: 12px;
    padding: 2px 6px;
    min-width: 18px;
    text-align: center;
    flex-shrink: 0;
}

.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: var(--bg-primary);
}

.chat-main-header {
    padding: 16px;
    border-bottom: 1px solid var(--border-light);
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    gap: 12px;
}

.chat-main-back {
    display: none;
    background: none;
    border: none;
    font-size: 1.2rem;
    cursor: pointer;
    color: var(--primary);
    padding: 5px;
}

.chat-main-user {
    flex: 1;
    min-width: 0;
}

.chat-main-name {
    font-family: var(--font-headline);
    font-weight: 700;
    font-size: 1.05rem;
    color: var(--primary);
    margin: 0;
}

.chat-main-status {
    font-size: 0.75rem;
    color: var(--success);
    font-weight: 600;
}

.chat-body {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
    background: var(--surface-container-low);
}

.message-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-width: 75%;
    animation: messagePop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) backwards;
}

@keyframes messagePop {
    from { opacity: 0; transform: translateY(15px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.message-group.sent {
    align-self: flex-end;
    align-items: flex-end;
    transform-origin: bottom right;
}

.message-group.received {
    align-self: flex-start;
    align-items: flex-start;
    transform-origin: bottom left;
}

.message-bubble {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    font-size: 0.95rem;
    line-height: 1.4;
    word-break: break-word;
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.message-bubble:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.message-bubble.sent {
    background: var(--primary);
    color: #ffffff;
    border-bottom-right-radius: 2px;
}

.message-bubble.received {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border-bottom-left-radius: 2px;
    border: 1px solid var(--border-light);
}

.message-time {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 2px;
    padding: 0 4px;
}

.chat-footer {
    padding: 16px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-light);
    display: flex;
    gap: 12px;
    align-items: center;
}

.chat-input {
    flex: 1;
    border: 1px solid var(--border-color);
    border-radius: 24px;
    padding: 12px 20px;
    font-size: 0.95rem;
    outline: none;
    background: var(--bg-primary);
    color: var(--text-primary);
    transition: var(--transition);
}

.chat-input:focus {
    border-color: var(--primary);
    background: var(--bg-secondary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}

.chat-send-btn {
    background: var(--primary);
    color: #ffffff;
    border: none;
    border-radius: 50%;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
    flex-shrink: 0;
    box-shadow: var(--shadow-sm);
}

.chat-send-btn:hover {
    background: var(--primary-light);
    transform: scale(1.05);
}

.chat-send-btn svg {
    margin-left: 2px;
}

.chat-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    padding: 40px;
    text-align: center;
}

.chat-empty-icon {
    font-size: 3rem;
    margin-bottom: 15px;
    opacity: 0.6;
}

/* Responsive Styles */
@media (max-width: 768px) {
    .chat-container {
        height: calc(100vh - 120px);
        margin-top: 0;
        margin-bottom: 0;
        border-radius: 0;
        border: none;
    }
    
    .chat-sidebar {
        width: 100%;
        flex-shrink: 0;
    }
    
    .chat-main {
        width: 100%;
        flex-shrink: 0;
        display: none;
    }
    
    .chat-container.thread-active .chat-sidebar {
        display: none;
    }
    
    .chat-container.thread-active .chat-main {
        display: flex;
    }
    
    .chat-main-back {
        display: block;
    }
}

/* Mic Recording Animation */
.recording-active {
    color: var(--danger) !important;
    animation: pulseMic 1.5s infinite;
}
@keyframes pulseMic {
    0% { transform: scale(1); }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); }
}
</style>

<div class="page-wrapper" style="padding-top: 20px; padding-bottom: 20px;">
    <div class="container">
        
        <div class="chat-container <?php echo $active_conversation_id > 0 ? 'thread-active' : ''; ?>" id="chatContainer">
            <!-- Sidebar -->
            <div class="chat-sidebar">
                <div class="chat-sidebar-header">
                    <h3 class="chat-sidebar-title">Inbox</h3>
                </div>
                <div class="chat-list" id="chatList">
                    <!-- Loaded via AJAX -->
                    <div style="padding: 20px; text-align: center; color: var(--text-muted);">
                        Loading conversations...
                    </div>
                </div>
            </div>

            <!-- Main Chat Area -->
            <div class="chat-main">
                <div id="chatMainContent" style="display: none; height: 100%; flex-direction: column;">
                    <!-- Chat Header -->
                    <div class="chat-main-header">
                        <button class="chat-main-back" onclick="backToInbox()">&larr;</button>
                        <div class="avatar" id="headerAvatar" style="width: 40px; height: 40px; font-size: 1rem;">U</div>
                        <div class="chat-main-user">
                            <h4 class="chat-main-name" id="headerName">Loading...</h4>
                            <span class="chat-main-status">Active Partner</span>
                        </div>
                    </div>

                    <!-- Chat Body -->
                    <div class="chat-body" id="chatBody">
                        <!-- Messages loaded via AJAX -->
                    </div>

                    <!-- Chat Footer / Input -->
                    <form class="chat-footer" id="chatForm" onsubmit="handleSend(event)">
                        <!-- Cancel Button (Hidden initially) -->
                        <button type="button" class="chat-cancel-btn" id="cancelBtn" onclick="cancelRecording()" style="display: none; background: none; border: none; color: var(--danger); cursor: pointer; padding: 0 10px; transition: color 0.3s;" title="Cancel Recording">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="15" y1="9" x2="9" y2="15"></line>
                                <line x1="9" y1="9" x2="15" y2="15"></line>
                            </svg>
                        </button>

                        <input type="text" class="chat-input" id="chatInput" placeholder="Write a message..." autocomplete="off">
                        
                        <!-- Mic Button (Toggles Start/Pause/Resume) -->
                        <button type="button" class="chat-mic-btn" id="micBtn" onclick="toggleRecording()" style="background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0 10px; transition: color 0.3s;" title="Record Voice Message">
                            <svg id="micIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"></path>
                                <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                                <line x1="12" y1="19" x2="12" y2="23"></line>
                                <line x1="8" y1="23" x2="16" y2="23"></line>
                            </svg>
                        </button>
                        
                        <button type="submit" class="chat-send-btn" id="sendBtn" title="Send Message">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                        </button>
                    </form>
                </div>

                <!-- Chat Empty State -->
                <div class="chat-empty" id="chatEmptyState">
                    <div class="chat-empty-icon"><i data-lucide="message-square" class="lucide-sm"></i></div>
                    <h3>Select a conversation</h3>
                    <p>Pick someone from your inbox or visit their profile to start coordinating your next skill swap.</p>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
let currentUserId = <?php echo $user_id; ?>;
let activeConversationId = <?php echo $active_conversation_id; ?>;
let activeRecipientId = 0;
let pollingInterval = null;
let lastMessageIdSeen = 0;
let lastConversationsJSON = '';

document.addEventListener('DOMContentLoaded', function() {
    // Initial fetch of conversations list
    fetchConversations().then(() => {
        if (activeConversationId > 0) {
            openConversation(activeConversationId);
        }
    });

    // Start background polling for conversation list
    setInterval(fetchConversations, 4000);

    // Setup active thread polling
    setInterval(pollActiveThread, 3000);
});

function formatLastSeen(dateStr) {
    const date = new Date(dateStr.replace(/-/g, '/'));
    const today = new Date();
    
    const isToday = date.getDate() === today.getDate() &&
                    date.getMonth() === today.getMonth() &&
                    date.getFullYear() === today.getFullYear();
    
    const timeFormat = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    
    if (isToday) {
        return 'Last seen today at ' + timeFormat;
    } else {
        const dateFormat = date.toLocaleDateString([], { month: 'short', day: 'numeric' });
        const yearFormat = date.getFullYear() !== today.getFullYear() ? `, ${date.getFullYear()}` : '';
        return 'Last seen ' + dateFormat + yearFormat + ' at ' + timeFormat;
    }
}

async function fetchConversations() {
    try {
        const response = await fetch('../api/messages.php');
        const data = await response.json();
        
        if (data.status === 'success') {
            const currentJSON = JSON.stringify(data.conversations);
            // Only re-render if the conversations data has changed to prevent animation loop
            if (currentJSON !== lastConversationsJSON) {
                lastConversationsJSON = currentJSON;
                renderConversationsList(data.conversations);
            }
        }
    } catch (error) {
        console.error('Error fetching conversations:', error);
    }
}

function renderConversationsList(conversations) {
    const chatList = document.getElementById('chatList');
    if (conversations.length === 0) {
        chatList.innerHTML = `
            <div style="padding: 30px; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                No active conversations yet.
            </div>
        `;
        return;
    }

    let html = '';
    conversations.forEach(conv => {
        const isActive = conv.conversation_id === activeConversationId ? 'active' : '';
        const unreadBadge = conv.unread_count > 0 ? `<span class="chat-item-unread">${conv.unread_count}</span>` : '';
        const initials = conv.other_user_name.substring(0, 1).toUpperCase();
        
        // Formulate last message display
        let lastMsg = conv.last_message_text || 'No messages yet';
        if (conv.last_message_sender_id === currentUserId) {
            lastMsg = 'You: ' + lastMsg;
        }

        let timeStr = '';
        if (conv.last_message_time) {
            const date = new Date(conv.last_message_time.replace(/-/g, '/'));
            timeStr = date.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ', ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        let avatarHtml = conv.has_photo === 1 
            ? `<img src="../api/user_photo.php?user_id=${conv.other_user_id}" style="width:45px; height:45px; border-radius:50%; object-fit:cover;" alt="${conv.other_user_name}">` 
            : `<div class="avatar" style="width: 45px; height: 45px; font-size: 1.1rem; border-radius:50%; display:flex; align-items:center; justify-content:center; background:var(--bg-secondary); color:var(--text-primary); font-weight:bold;">${initials}</div>`;

        let onlineDot = conv.is_online ? `<div style="position:absolute; bottom:2px; right:2px; width:12px; height:12px; background-color:#22c55e; border-radius:50%; border:2px solid var(--bg-card);"></div>` : '';
        let avatarContainer = `<div style="position:relative; width:45px; height:45px; flex-shrink:0;">${avatarHtml}${onlineDot}</div>`;

        html += `
            <div class="chat-item ${isActive}" onclick="openConversation(${conv.conversation_id})" id="conv-item-${conv.conversation_id}">
                ${avatarContainer}
                <div class="chat-item-details">
                    <div class="chat-item-header">
                        <span class="chat-item-name">${conv.other_user_name}</span>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="chat-item-time">${timeStr}</span>
                            <button onclick="hideInbox(${conv.conversation_id}, event)" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem; padding:0 2px; line-height:1; transition:color 0.2s ease;" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text-muted)'" title="Hide conversation">&times;</button>
                        </div>
                    </div>
                    <div class="flex justify-between items-center" style="gap:5px;">
                        <span class="chat-item-preview">${lastMsg}</span>
                        ${unreadBadge}
                    </div>
                </div>
            </div>
        `;
    });

    chatList.innerHTML = html;
}

async function openConversation(conversationId) {
    activeConversationId = conversationId;
    
    // Hide empty state and show main area
    document.getElementById('chatEmptyState').style.display = 'none';
    const mainContent = document.getElementById('chatMainContent');
    mainContent.style.display = 'flex';
    
    // Highlight list item
    document.querySelectorAll('.chat-item').forEach(item => item.classList.remove('active'));
    const activeItem = document.getElementById(`conv-item-${conversationId}`);
    if (activeItem) {
        activeItem.classList.add('active');
        // Clear its unread badge locally for quick feedback
        const badge = activeItem.querySelector('.chat-item-unread');
        if (badge) badge.remove();
    }
    
    document.getElementById('chatContainer').classList.add('thread-active');
    
    // Fetch header details and messages
    await loadMessages(conversationId);
}

async function loadMessages(conversationId) {
    try {
        const response = await fetch(`../api/messages.php?conversation_id=${conversationId}`);
        const data = await response.json();
        
        if (data.status === 'success') {
            // Update header with partner name and photo
            if (data.partner_name) {
                document.getElementById('headerName').textContent = data.partner_name;
                let avatarElem = document.getElementById('headerAvatar');
                if (data.partner_has_photo) {
                    avatarElem.outerHTML = `<img id="headerAvatar" src="../api/user_photo.php?user_id=${data.partner_id}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; flex-shrink: 0;" alt="${data.partner_name}">`;
                } else {
                    let initials = data.partner_name.substring(0, 1).toUpperCase();
                    avatarElem.outerHTML = `<div class="avatar" id="headerAvatar" style="width: 40px; height: 40px; font-size: 1.1rem; border-radius:50%; display:flex; align-items:center; justify-content:center; background:var(--bg-secondary); color:var(--text-primary); font-weight:bold; flex-shrink: 0;">${initials}</div>`;
                }
                
                // Update status
                const statusElem = document.querySelector('.chat-main-status');
                if (data.is_online) {
                    statusElem.innerHTML = `<span style="color:#22c55e; font-weight:600;">&bull; Online</span>`;
                } else {
                    let timeStr = 'Offline';
                    if (data.last_active) {
                        timeStr = formatLastSeen(data.last_active);
                    }
                    statusElem.innerHTML = `<span style="color:var(--text-muted);">${timeStr}</span>`;
                }
            } else {
                document.getElementById('headerName').textContent = 'Partner';
                document.getElementById('headerAvatar').outerHTML = `<div class="avatar" id="headerAvatar" style="width: 40px; height: 40px; font-size: 1.1rem; border-radius:50%; display:flex; align-items:center; justify-content:center; background:var(--bg-secondary); color:var(--text-primary); font-weight:bold; flex-shrink: 0;">P</div>`;
                document.querySelector('.chat-main-status').textContent = 'Offline';
            }

            renderMessages(data.messages);
            scrollToBottom();
            
            // Mark navbar badge as read if we have one (or decrement)
            // The next page load or polling will update it correctly.
        }
    } catch (error) {
        console.error('Error loading messages:', error);
    }
}

function renderMessages(messages) {
    const chatBody = document.getElementById('chatBody');
    if (messages.length === 0) {
        chatBody.innerHTML = `
            <div style="margin: auto; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                No messages yet. Send a message to start the conversation!
            </div>
        `;
        lastMessageIdSeen = 0;
        return;
    }

    let html = '';
    messages.forEach(msg => {
        const isSent = msg.sender_id === currentUserId;
        const groupClass = isSent ? 'sent' : 'received';
        const bubbleClass = isSent ? 'sent' : 'received';
        
        const date = new Date(msg.sent_at.replace(/-/g, '/'));
        const timeStr = date.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ', ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        let messageContent = msg.message_text;
        if (msg.message_type === 'audio' && msg.media_url) {
            const bubbleColor = isSent ? 'var(--primary)' : 'var(--bg-secondary)';
            const textColor = isSent ? '#fff' : 'var(--text-primary)';
            const waveBaseColor = isSent ? 'rgba(255,255,255,0.4)' : 'var(--border-color)';
            const waveActiveColor = isSent ? '#fff' : 'var(--primary)';
            const btnBg = isSent ? 'rgba(255,255,255,0.2)' : 'var(--bg-hover)';
            
            messageContent = `
                <div class="custom-audio-player" style="display: inline-flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 18px; background: ${bubbleColor}; color: ${textColor}; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <button type="button" class="play-pause-btn" onclick="toggleAudio(this)" style="background: ${btnBg}; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: inherit; flex-shrink: 0; transition: transform 0.1s;">
                        <svg class="icon-play" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 2px;">
                            <polygon points="5 3 19 12 5 21 5 3"></polygon>
                        </svg>
                        <svg class="icon-pause" style="display:none;" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="6" y="4" width="4" height="16"></rect>
                            <rect x="14" y="4" width="4" height="16"></rect>
                        </svg>
                    </button>
                    
                    <div class="waveform-container" onclick="seekAudio(event, this)" style="width: 138px; height: 28px; position: relative; cursor: pointer; flex-shrink: 0;">
                        <audio src="..${msg.media_url}" ontimeupdate="updateAudioProgress(this)" onloadedmetadata="setAudioDuration(this)" onended="audioEnded(this)" style="display:none;"></audio>
                        
                        <!-- Base bars -->
                        <div class="waveform-base" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; gap: 2px;">
                            ${generateWaveformBarsHtml(28, waveBaseColor)}
                        </div>
                        
                        <!-- Progress bars -->
                        <div class="waveform-progress-container" style="position: absolute; top: 0; left: 0; width: 0%; height: 100%; overflow: hidden; pointer-events: none;">
                            <div class="waveform-active" style="width: 138px; height: 100%; display: flex; align-items: center; gap: 2px;">
                                ${generateWaveformBarsHtml(28, waveActiveColor)}
                            </div>
                        </div>
                    </div>
                    
                    <span class="audio-duration" style="font-size: 0.75rem; font-family: monospace; font-weight: 600; min-width: 35px; text-align: right;">0:00</span>
                </div>
            `;
        }

        html += `
            <div class="message-group ${groupClass}" data-id="${msg.message_id}">
                <div class="message-bubble ${bubbleClass}" style="${msg.message_type === 'audio' ? 'background:transparent; padding:0; box-shadow:none; border:none;' : ''}">
                    ${messageContent}
                </div>
                <span class="message-time">${timeStr}</span>
            </div>
        `;
        
        if (msg.message_id > lastMessageIdSeen) {
            lastMessageIdSeen = msg.message_id;
        }
    });

    chatBody.innerHTML = html;
}

async function pollActiveThread() {
    if (activeConversationId <= 0) return;
    
    try {
        const response = await fetch(`../api/messages.php?conversation_id=${activeConversationId}`);
        const data = await response.json();
        
        if (data.status === 'success') {
            // Update header status in case they came online/went offline
            const statusElem = document.querySelector('.chat-main-status');
            if (data.is_online) {
                statusElem.innerHTML = `<span style="color:#22c55e; font-weight:600;">&bull; Online</span>`;
            } else {
                let timeStr = 'Offline';
                if (data.last_active) {
                    timeStr = formatLastSeen(data.last_active);
                }
                statusElem.innerHTML = `<span style="color:var(--text-muted);">${timeStr}</span>`;
            }

            // Check if there are new messages
            const messages = data.messages;
            if (messages.length > 0) {
                const latestMsg = messages[messages.length - 1];
                if (latestMsg.message_id > lastMessageIdSeen) {
                    renderMessages(messages);
                    scrollToBottom();
                }
            }
        }
    } catch (error) {
        console.error('Error polling thread:', error);
    }
}

async function handleSend(event) {
    event.preventDefault();
    if (isRecording) {
        // Stop recording and send audio
        stopRecording(false);
    } else {
        // Send text message
        sendMessage();
    }
}

async function sendMessage() {
    const chatInput = document.getElementById('chatInput');
    const text = chatInput.value.trim();
    if (text === '') return;

    chatInput.value = '';
    chatInput.focus();

    const formData = new FormData();
    formData.append('conversation_id', activeConversationId);
    formData.append('message_text', text);
    formData.append('csrf_token', window.csrfToken);

    try {
        const response = await fetch('../api/messages.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        if (data.status === 'success') {
            loadMessages(activeConversationId);
            fetchConversations();
        }
    } catch (error) {
        console.error('Error sending message:', error);
    }
}

// Voice Recording Logic
let mediaRecorder;
let audioChunks = [];
let isRecording = false;
let isRecordingCanceled = false;

async function toggleRecording() {
    if (!isRecording) {
        startRecording();
    } else {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.pause();
            document.getElementById('micIcon').outerHTML = `
                <svg id="micIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                </svg>`;
            document.getElementById('chatInput').placeholder = "Recording paused... Mic to resume, Send to finish.";
            document.getElementById('micBtn').classList.remove('recording-active');
        } else if (mediaRecorder && mediaRecorder.state === 'paused') {
            mediaRecorder.resume();
            document.getElementById('micIcon').outerHTML = `
                <svg id="micIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="6" y="4" width="4" height="16"></rect>
                    <rect x="14" y="4" width="4" height="16"></rect>
                </svg>`;
            document.getElementById('chatInput').placeholder = "Recording... Mic to pause, Send to finish.";
            document.getElementById('micBtn').classList.add('recording-active');
        }
    }
}

async function startRecording() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        
        const options = {
            mimeType: 'audio/webm;codecs=opus',
            audioBitsPerSecond: 16000
        };
        
        if (MediaRecorder.isTypeSupported(options.mimeType)) {
            mediaRecorder = new MediaRecorder(stream, options);
        } else {
            mediaRecorder = new MediaRecorder(stream, { audioBitsPerSecond: 16000 });
        }
        
        audioChunks = [];
        isRecordingCanceled = false;

        mediaRecorder.ondataavailable = event => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };

        mediaRecorder.onstop = () => {
            if (!isRecordingCanceled) {
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                sendAudioMessage(audioBlob);
            }
            
            // Stop all tracks to release microphone
            stream.getTracks().forEach(track => track.stop());
            resetMicUI();
        };

        mediaRecorder.start();
        isRecording = true;
        
        // Update UI for recording state
        document.getElementById('cancelBtn').style.display = 'block';
        document.getElementById('micBtn').classList.add('recording-active');
        document.getElementById('micIcon').outerHTML = `
            <svg id="micIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="6" y="4" width="4" height="16"></rect>
                <rect x="14" y="4" width="4" height="16"></rect>
            </svg>`;
        document.getElementById('chatInput').placeholder = "Recording... Mic to pause, Send to finish.";
        document.getElementById('chatInput').disabled = true;
    } catch (err) {
        console.error("Error accessing microphone:", err);
        alert("Please allow microphone access to record audio messages.");
    }
}

function stopRecording(cancel = false) {
    if (mediaRecorder && mediaRecorder.state !== "inactive") {
        isRecordingCanceled = cancel;
        mediaRecorder.stop();
        isRecording = false;
    }
}

function cancelRecording() {
    stopRecording(true);
}

function resetMicUI() {
    document.getElementById('cancelBtn').style.display = 'none';
    document.getElementById('micBtn').classList.remove('recording-active');
    document.getElementById('micIcon').outerHTML = `
        <svg id="micIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"></path>
            <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
            <line x1="12" y1="19" x2="12" y2="23"></line>
            <line x1="8" y1="23" x2="16" y2="23"></line>
        </svg>`;
    document.getElementById('chatInput').placeholder = "Write a message...";
    document.getElementById('chatInput').disabled = false;
    document.getElementById('chatInput').focus();
}

async function sendAudioMessage(blob) {
    if (activeConversationId <= 0) return;

    const formData = new FormData();
    formData.append('conversation_id', activeConversationId);
    formData.append('audio_file', blob, 'voice_message.webm');
    formData.append('csrf_token', window.csrfToken);

    try {
        const response = await fetch('../api/messages.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        if (data.status === 'success') {
            loadMessages(activeConversationId);
            fetchConversations();
        } else {
            console.error('Error sending audio message:', data.error);
        }
    } catch (error) {
        console.error('Error sending audio message:', error);
    }
}

function scrollToBottom() {
    const chatBody = document.getElementById('chatBody');
    chatBody.scrollTop = chatBody.scrollHeight;
}

function backToInbox() {
    document.getElementById('chatContainer').classList.remove('thread-active');
    activeConversationId = 0;
}

async function hideInbox(conversationId, event) {
    event.stopPropagation();
    if (!confirm('Are you sure you want to hide this conversation? The history will be preserved if you chat again.')) return;
    
    try {
        const response = await fetch('../api/messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'hide_inbox',
                conversation_id: conversationId,
                csrf_token: window.csrfToken
            })
        });
        const data = await response.json();
        if (data.status === 'success') {
            if (activeConversationId === conversationId) {
                backToInbox();
            }
            fetchConversations();
        }
    } catch (error) {
        console.error('Error hiding inbox:', error);
    }
}

// Custom Audio Player Logic
function generateWaveformBarsHtml(count, color) {
    let html = '';
    // Pre-defined pseudo-random heights to create a consistent waveform
    const heights = [6, 12, 18, 14, 8, 16, 20, 24, 18, 10, 14, 22, 20, 12, 8, 18, 24, 16, 10, 14, 20, 18, 12, 8, 16, 22, 14, 10];
    for (let i = 0; i < count; i++) {
        let h = heights[i % heights.length];
        html += `<div style="width: 3px; height: ${h}px; background-color: ${color}; border-radius: 2px; flex-shrink: 0;"></div>`;
    }
    return html;
}

function toggleAudio(btn) {
    const container = btn.closest('.custom-audio-player');
    const audio = container.querySelector('audio');
    const iconPlay = btn.querySelector('.icon-play');
    const iconPause = btn.querySelector('.icon-pause');
    
    // Pause all other audios on the page
    document.querySelectorAll('.custom-audio-player audio').forEach(a => {
        if (a !== audio && !a.paused) {
            a.pause();
            const otherBtn = a.closest('.custom-audio-player').querySelector('.play-pause-btn');
            otherBtn.querySelector('.icon-play').style.display = 'block';
            otherBtn.querySelector('.icon-pause').style.display = 'none';
        }
    });

    if (audio.paused) {
        audio.play();
        iconPlay.style.display = 'none';
        iconPause.style.display = 'block';
    } else {
        audio.pause();
        iconPlay.style.display = 'block';
        iconPause.style.display = 'none';
    }
}

function updateAudioProgress(audio) {
    const container = audio.closest('.custom-audio-player');
    const progressContainer = container.querySelector('.waveform-progress-container');
    const durationElem = container.querySelector('.audio-duration');
    
    let duration = audio.duration;
    // Fallback if duration is Infinity (common in some webm recordings until end is reached)
    if (duration === Infinity || isNaN(duration)) {
        duration = 60; // fallback max
    }
    
    if (duration > 0) {
        const percent = Math.min((audio.currentTime / duration) * 100, 100);
        progressContainer.style.width = `${percent}%`;
        
        // Show current time while playing
        const mins = Math.floor(audio.currentTime / 60);
        const secs = Math.floor(audio.currentTime % 60).toString().padStart(2, '0');
        durationElem.textContent = `${mins}:${secs}`;
    }
}

function setAudioDuration(audio) {
    const container = audio.closest('.custom-audio-player');
    const durationElem = container.querySelector('.audio-duration');
    let duration = audio.duration;
    
    if (duration && duration !== Infinity && !isNaN(duration)) {
        const mins = Math.floor(duration / 60);
        const secs = Math.floor(duration % 60).toString().padStart(2, '0');
        durationElem.textContent = `${mins}:${secs}`;
    } else {
        // If webm duration is Infinity, display 0:00 until played
        durationElem.textContent = "0:00";
    }
}

function audioEnded(audio) {
    const container = audio.closest('.custom-audio-player');
    const btn = container.querySelector('.play-pause-btn');
    const progressContainer = container.querySelector('.waveform-progress-container');
    
    btn.querySelector('.icon-play').style.display = 'block';
    btn.querySelector('.icon-pause').style.display = 'none';
    progressContainer.style.width = '0%';
    setAudioDuration(audio); // reset to total duration text
}

function seekAudio(event, waveformContainer) {
    const audio = waveformContainer.querySelector('audio');
    let duration = audio.duration;
    if (!duration || duration === Infinity || isNaN(duration)) {
        return; // Cannot seek reliably without known duration
    }
    
    const rect = waveformContainer.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const percent = Math.max(0, Math.min(1, clickX / rect.width));
    
    audio.currentTime = percent * duration;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
