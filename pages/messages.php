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
                    <form class="chat-footer" id="chatForm" onsubmit="sendMessage(event)">
                        <input type="text" class="chat-input" id="chatInput" placeholder="Write a message..." autocomplete="off" required>
                        <button type="submit" class="chat-send-btn">
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

        html += `
            <div class="chat-item ${isActive}" onclick="openConversation(${conv.conversation_id})" id="conv-item-${conv.conversation_id}">
                <div class="avatar" style="width: 45px; height: 45px; font-size: 1.1rem;">${initials}</div>
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
            // Find user details from sidebar if possible to set header quickly
            const activeItem = document.getElementById(`conv-item-${conversationId}`);
            if (activeItem) {
                const name = activeItem.querySelector('.chat-item-name').textContent;
                document.getElementById('headerName').textContent = name;
                document.getElementById('headerAvatar').textContent = name.substring(0, 1).toUpperCase();
            } else if (data.partner_name) {
                // If not in list yet, use the name returned by the API
                document.getElementById('headerName').textContent = data.partner_name;
                document.getElementById('headerAvatar').textContent = data.partner_name.substring(0, 1).toUpperCase();
            } else {
                document.getElementById('headerName').textContent = 'Partner';
                document.getElementById('headerAvatar').textContent = 'P';
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

        html += `
            <div class="message-group ${groupClass}" data-id="${msg.message_id}">
                <div class="message-bubble ${bubbleClass}">
                    ${msg.message_text}
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

async function sendMessage(event) {
    event.preventDefault();
    const chatInput = document.getElementById('chatInput');
    const text = chatInput.value.trim();
    if (text === '') return;

    chatInput.value = '';
    chatInput.focus();

    try {
        const response = await fetch('../api/messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: activeConversationId,
                message_text: text
            })
        });
        
        const data = await response.json();
        if (data.status === 'success') {
            // Append message directly or trigger reload
            loadMessages(activeConversationId);
            fetchConversations();
        }
    } catch (error) {
        console.error('Error sending message:', error);
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
                conversation_id: conversationId
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
