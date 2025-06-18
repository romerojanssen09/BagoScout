document.addEventListener('DOMContentLoaded', function() {
    // Initialize Ably with better error handling
    let ably = null;
    let ablyInitialized = false;
    let currentChannel = null;
    let currentConversationId = null;
    let currentReceiverId = null;
    
    // Try to initialize Ably
    initializeAbly();
    
    function initializeAbly() {
        try {
            // Check if Ably is already initialized and connected
            if (ably && ably.connection.state === 'connected') {
                console.log('Ably already initialized and connected');
                ablyInitialized = true;
                
                // Subscribe to current conversation if one is selected
                if (currentConversationId) {
                    console.log("Subscribing to current conversation (already connected):", currentConversationId);
                    subscribeToChannel(currentConversationId);
                }
                return;
            }
            
            // If there's an existing client but not connected, try to reconnect
            if (ably) {
                try {
                    console.log('Attempting to reconnect existing Ably client');
                    ably.connection.connect();
                    return;
                } catch (e) {
                    console.error('Failed to reconnect existing client, creating new one:', e);
                    // Continue to create a new client
                }
            }
            
            console.log('Initializing Ably client');
            
            // Get the token from the server
            fetch('/bagoscout/api/ably-auth.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Failed to get Ably token: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data.success || !data.token) {
                        throw new Error('Invalid response from auth server: ' + JSON.stringify(data));
                    }
                    
                    console.log('Ably auth successful, received token');
                    console.log('Client ID from server:', data.clientId);
                    
                    // Initialize Ably with the key
                    ably = new Ably.Realtime({
                        key: data.key, // Using the provided key
                        clientId: 'user-' + data.user_id,
                        echoMessages: false
                    });
                    
                    setupAblyListeners();
                    
                    // Subscribe to current conversation if one is selected
                    if (currentConversationId) {
                        console.log("Subscribing to current conversation after initialization:", currentConversationId);
                        subscribeToChannel(currentConversationId);
                    }
                })
                .catch(error => {
                    console.error('Failed to initialize Ably:', error);
                    showError('Failed to initialize messaging service. Please try refreshing the page.');
                    
                    // Try to reinitialize after a delay
                    setTimeout(initializeAbly, 5000);
                });
        } catch (error) {
            console.error('Failed to initialize Ably:', error);
            showError('Failed to initialize messaging service. Please try refreshing the page.');
            
            // Try to reinitialize after a delay
            setTimeout(initializeAbly, 5000);
        }
    }
    
    // Set up Ably connection listeners
    function setupAblyListeners() {
        if (!ably) return;
        
        ably.connection.on('connected', () => {
            console.log('Connected to Ably');
            ablyInitialized = true;
            
            // Subscribe to conversation if one is selected
            if (currentConversationId) {
                subscribeToChannel(currentConversationId);
            }
        });
        
        ably.connection.on('failed', (err) => {
            console.error('Ably connection failed:', err);
            ablyInitialized = false;
            showError('Failed to connect to messaging service. Please try refreshing the page.');
        });
        
        ably.connection.on('disconnected', () => {
            console.log('Disconnected from Ably, attempting to reconnect...');
            // Don't set ablyInitialized to false here, as Ably will try to reconnect automatically
            
            // Try to reconnect after a short delay
            setTimeout(() => {
                if (ably && ably.connection.state !== 'connected') {
                    console.log('Attempting to reconnect to Ably...');
                    ably.connection.connect();
                }
            }, 3000);
        });
        
        ably.connection.on('suspended', () => {
            console.log('Ably connection suspended, will retry automatically');
            
            // Try to reconnect after a delay
            setTimeout(() => {
                if (ably && ably.connection.state !== 'connected') {
                    console.log('Attempting to reconnect to Ably from suspended state...');
                    ably.connection.connect();
                }
            }, 10000);
        });
        
        ably.connection.on('closed', () => {
            console.log('Connection to Ably closed');
            ablyInitialized = false;
            
            // Try to reconnect after a delay
            setTimeout(initializeAbly, 5000);
        });
        
        ably.connection.on('error', (err) => {
            console.error('Ably connection error:', err);
            
            // Try to reconnect if there's an error
            setTimeout(() => {
                if (ably && ably.connection.state !== 'connected') {
                    console.log('Attempting to reconnect to Ably after error...');
                    ably.connection.connect();
                }
            }, 5000);
        });
    }
    
    // Show error message
    function showError(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded fixed top-4 right-4 z-50';
        errorDiv.innerHTML = `
            <strong class="font-bold">Error:</strong>
            <span class="block sm:inline">${message}</span>
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                    <title>Close</title>
                    <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                </svg>
            </span>
        `;
        document.body.appendChild(errorDiv);
        
        // Add click event to close
        const closeBtn = errorDiv.querySelector('svg');
        closeBtn.addEventListener('click', function() {
            errorDiv.remove();
        });
        
        // Auto remove after 10 seconds
        setTimeout(function() {
            if (document.body.contains(errorDiv)) {
                errorDiv.remove();
            }
        }, 10000);
    }
    
    // Check if receiver parameter exists in URL
    const urlParams = new URLSearchParams(window.location.search);
    const receiverId = urlParams.get('receiver');
    
    // Elements
    const conversationList = document.getElementById('conversation-list');
    const messagesContainer = document.getElementById('messages-container');
    const messagesList = document.getElementById('messages-list');
    const messageForm = document.getElementById('message-form');
    const messageInput = document.getElementById('message-input');
    const noConversationView = document.getElementById('no-conversation-selected');
    const conversationView = document.getElementById('conversation-view');
    const conversationName = document.getElementById('conversation-name');
    const conversationStatus = document.getElementById('conversation-status');
    const conversationAvatar = document.getElementById('conversation-avatar');
    const conversationAvatarImg = document.getElementById('conversation-avatar-img');
    const conversationAvatarPlaceholder = document.getElementById('conversation-avatar-placeholder');
    const conversationSearch = document.getElementById('conversation-search');
    const fileUpload = document.getElementById('file-upload');
    const fileName = document.getElementById('file-name');
    const mobileMenuToggle = document.querySelectorAll('#mobile-menu-toggle');
    const leftSidebar = document.querySelector('.flex.flex-col.md\\:flex-row.h-full > div:first-child');
    
    // Mobile menu toggle
    if (mobileMenuToggle) {
        mobileMenuToggle.forEach(toggle => {
            toggle.addEventListener('click', () => {
            leftSidebar.classList.toggle('hidden');
            leftSidebar.classList.toggle('md:block');
            });
        });
    }
    
    // Initialize send button with the correct selector
    const sendButton = document.querySelector('#message-form button[type="submit"]');
    
    // Handle form submission
    if (messageForm) {
        messageForm.addEventListener('submit', function(e) {
            e.preventDefault();
            sendMessage();
        });
    } else {
        console.error('Message form not found');
    }
    
    // Handle send button click
    if (sendButton) {
        sendButton.addEventListener('click', function(e) {
            e.preventDefault();
            sendMessage();
        });
    } else {
        console.error('Send button not found');
    }
    
    // Handle enter key press
    messageInput.addEventListener('keydown', function(e) {
        // Send message on Enter key (without shift)
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    
    // File upload event
    fileUpload.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            fileName.textContent = e.target.files[0].name;
            const fileUploadContainer = document.getElementById('file-upload-container');
            if (fileUploadContainer) {
                fileUploadContainer.classList.remove('hidden');
            }
        } else {
            fileName.textContent = '';
            const fileUploadContainer = document.getElementById('file-upload-container');
            if (fileUploadContainer) {
                fileUploadContainer.classList.add('hidden');
            }
        }
    });

    // Clear file button
    const clearFileBtn = document.getElementById('clear-file');
    if (clearFileBtn) {
        clearFileBtn.addEventListener('click', () => {
            fileUpload.value = '';
            fileName.textContent = '';
            const fileUploadContainer = document.getElementById('file-upload-container');
            if (fileUploadContainer) {
                fileUploadContainer.classList.add('hidden');
            }
        });
    }
    
    // If receiver parameter exists, handle it immediately
    if (receiverId) {
        handleReceiverParameter(receiverId);
    } else {
        // Otherwise, load all conversations
        loadConversations();
    }
    
    // Handle receiver parameter
    function handleReceiverParameter(receiverId) {
        // First check if we have an existing conversation with this user
        fetch(`/bagoscout/api/get-user.php?user_id=${receiverId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to fetch user data');
                }
                return response.json();
            })
            .then(userData => {
                if (userData.success) {
                    const user = userData.user;
                    
                    // Now check if we have an existing conversation
                    fetch('/bagoscout/api/conversations.php')
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Failed to fetch conversations');
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) {
                                renderConversations(data.conversations);
                                
                                // Find if conversation with this receiver already exists
                                const existingConversation = data.conversations.find(
                                    conv => conv.participant_id == receiverId
                                );
                                
                                if (existingConversation) {
                                    // Open existing conversation
                                    openConversation(existingConversation.conversation_id, existingConversation.participant_id, existingConversation.participant_name);
                                } else {
                                    // Create new conversation
                                    createNewConversation(user.id, user.name);
                                }
                            } else {
                                // No conversations yet, create a new one
                                createNewConversation(user.id, user.name);
                            }
                        })
                        .catch(error => {
                            console.error('Error loading conversations:', error);
                            // If there's an error loading conversations, try to create a new one
                            createNewConversation(user.id, user.name);
                        });
                } else {
                    console.error('User not found');
                    conversationList.innerHTML = `<div class="p-4 text-center text-red-500">
                        <p>User not found</p>
                    </div>`;
                    loadConversations();
                }
            })
            .catch(error => {
                console.error('Error fetching user:', error);
                conversationList.innerHTML = `<div class="p-4 text-center text-red-500">
                    <p>Failed to load user</p>
                </div>`;
                loadConversations();
            });
    }
    
    // Load conversations
    function loadConversations() {
        fetch('/bagoscout/api/conversations.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to fetch conversations');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    renderConversations(data.conversations);
                } else {
                    conversationList.innerHTML = `<div class="p-4 text-center text-gray-500">
                        <p>No conversations found</p>
                    </div>`;
                }
            })
            .catch(error => {
                console.error('Error loading conversations:', error);
                conversationList.innerHTML = `<div class="p-4 text-center text-red-500">
                    <p>Failed to load conversations</p>
                </div>`;
            });
    }
    
    // Render conversations
    function renderConversations(conversations) {
        if (conversations.length === 0) {
            conversationList.innerHTML = `<div class="p-4 text-center text-gray-500">
                <p>No conversations yet</p>
            </div>`;
            return;
        }
        
        conversationList.innerHTML = '';
        conversations.forEach(conversation => {
            const lastMessageTime = conversation.last_message_time ? new Date(conversation.last_message_time) : new Date();
            const formattedTime = formatMessageTime(lastMessageTime);
            
            const conversationEl = document.createElement('div');
            conversationEl.className = 'p-4 border-b border-gray-200 hover:bg-gray-50 cursor-pointer';
            conversationEl.dataset.conversationId = conversation.id;
            conversationEl.dataset.participantId = conversation.participant_id;
            conversationEl.dataset.participantName = conversation.participant_name;
            
            conversationEl.innerHTML = `
                <div class="flex items-center">
                    <div class="w-10 h-10 rounded-full bg-gray-300 flex-shrink-0 mr-3">
                        ${conversation.participant_avatar 
                            ? `<img src="${conversation.participant_avatar}" alt="${conversation.participant_name}" class="w-full h-full rounded-full object-cover">`
                            : `<div class="w-full h-full rounded-full flex items-center justify-center text-white font-semibold bg-green-500">
                                ${getInitials(conversation.participant_name)}
                              </div>`
                        }
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-baseline">
                            <h3 class="text-sm font-semibold text-gray-800 truncate">${conversation.participant_name}</h3>
                            <span class="text-xs text-gray-500">${formattedTime}</span>
                        </div>
                        <p class="text-sm text-gray-600 truncate" id="last-message">${conversation.last_message || 'No messages yet'}</p>
                    </div>
                    ${conversation.unread_count > 0 ? 
                        `<div class="ml-2 bg-green-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">
                            ${conversation.unread_count}
                        </div>` : ''}
                </div>
            `;
            
            conversationEl.addEventListener('click', function() {
                openConversation(
                    conversation.id,
                    conversation.participant_id,
                    conversation.participant_name
                );
            });
            
            conversationList.appendChild(conversationEl);
        });
    }
    
    // Open conversation
    function openConversation(conversationId, participantId, participantName) {
        console.log('Opening conversation:', conversationId, participantId, participantName);
        
        // Create otherUser object
        const otherUser = {
            id: participantId,
            name: participantName
        };
        
        // Select the conversation
        selectConversation(conversationId, otherUser);
    }
    
    // Setup call buttons
    function setupCallButtons(recipientId, recipientName = '') {
        const videoCallBtn = document.getElementById('video-call-btn');
        if (videoCallBtn) {
            // Remove any existing event listeners
            const newVideoCallBtn = videoCallBtn.cloneNode(true);
            videoCallBtn.parentNode.replaceChild(newVideoCallBtn, videoCallBtn);
            
            // Add click event listener directly
            newVideoCallBtn.addEventListener('click', function() {
                console.log('Video call button clicked for recipient:', recipientId, recipientName);
                
                // Check if call-handler.js is loaded
                if (typeof handleVideoCallClick === 'function') {
                    // If the function exists, call it directly
                    handleVideoCallClick(recipientId, recipientName);
                } else {
                    // Otherwise dispatch a custom event
                    document.dispatchEvent(new CustomEvent('recipientSelected', {
                        detail: {
                            recipientId: recipientId,
                            recipientName: recipientName
                        }
                    }));
                    
                    // Fallback to direct call page opening if needed
                    const callId = generateCallId(document.body.dataset.userId, recipientId);
                    const callPageUrl = '/bagoscout/pages/auth-user/seeker/call.php';
                    const callUrl = `${callPageUrl}?type=video&user=${recipientId}&initiator=true&id=${callId}`;
                    
                    // Open call in new tab
                    window.open(callUrl, '_blank');
                }
            });
        }
    }
    
    // Generate a consistent call ID from two user IDs
    function generateCallId(userId1, userId2) {
        // Sort the IDs to ensure consistency regardless of who initiates
        const sortedIds = [userId1, userId2].sort();
        return `call-${sortedIds[0]}-${sortedIds[1]}-${Date.now()}`;
    }
    
    // Create new conversation
    function createNewConversation(userId, userName) {
        fetch('/bagoscout/api/create-conversation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                participant_id: userId
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to create conversation');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Refresh conversations
                loadConversations();
                
                // Open the new conversation
                openConversation(data.conversation_id, userId, userName);
            } else {
                console.error('Failed to create conversation:', data.message);
            }
        })
        .catch(error => {
            console.error('Error creating conversation:', error);
        });
    }
    
    // Load messages for a conversation
    function loadMessages(conversationId) {
        if (!conversationId) return;
        
        // Show loading indicator
        messagesList.innerHTML = `
            <div class="flex justify-center items-center h-full">
                <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-blue-500"></div>
            </div>
        `;
        
        // Fetch messages from API
        fetch(`/bagoscout/api/messages.php?action=get_messages&conversation_id=${conversationId}`)
            .then(response => response.json())
            .then(data => {
                console.log(data);
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load messages');
                }
                
                // Render messages
                renderMessages(data.messages);
                
                // Mark conversation as read after loading messages
                markConversationAsRead(conversationId);
                
                // Update conversation in the list to remove unread indicator
                const conversationEl = document.querySelector(`[data-conversation-id="${conversationId}"]`);
                if (conversationEl) {
                    const unreadBadge = conversationEl.querySelector('.unread-badge');
                    if (unreadBadge) {
                        unreadBadge.classList.add('hidden');
                    }
                }
            })
            .catch(error => {
                console.error('Error loading messages:', error);
                messagesList.innerHTML = `
                    <div class="flex justify-center items-center h-full">
                        <div class="text-red-500">Failed to load messages. Please try again.</div>
                    </div>
                `;
            });
    }

    function formatMessageDate(date) {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const messageDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    
        const timeString = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    
        const diffTime = today - messageDay;
        const diffDays = diffTime / (1000 * 60 * 60 * 24);
    
        if (diffDays === 0) {
            return `Today at ${timeString}`;
        } else if (diffDays === 1) {
            return `Yesterday at ${timeString}`;
        } else if (date.getFullYear() === now.getFullYear()) {
            return `${date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} at ${timeString}`;
        } else {
            return `${date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })} at ${timeString}`;
        }
    }    
    
    // Render messages
    function renderMessages(messages) {
        messagesList.innerHTML = '';
        
        // Get current user ID from the DOM
        const currentUserId = document.body.dataset.userId;
        
        messages.forEach(message => {
            // Format date for messages
            const messageDate = new Date(message.created_at);
            
            // Create message element
            const messageEl = document.createElement('div');
            messageEl.dataset.messageId = message.id;
            messageEl.dataset.senderId = message.sender_id;

            // Check if this is a system message (for calls, etc.)
            const isSystemMessage = message.is_system == 1;
            
            if (isSystemMessage) {
                // System message styling (centered, different background)
                messageEl.className = 'flex justify-center mb-4';
                
                // Format system message - detect if it's a call message
                const messageContent = message.message || message.content || '';
                let iconHtml = '';
                let bgColorClass = 'bg-gray-100';
                let textColorClass = 'text-gray-600';
                let callInfoHtml = '';
                
                // Add specific styling based on the system message content
                if (messageContent.includes('call') || messageContent.includes('Call')) {
                    // Extract call details if available
                    let callDetails = message.call_details || null;
                    
                    // It's a call related message
                    if (messageContent.includes('missed') || messageContent.includes('didn\'t answer')) {
                        // Missed call
                        iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>';
                        bgColorClass = 'bg-red-50';
                        textColorClass = 'text-red-700';
                        callInfoHtml = '<span class="font-medium">Missed Call</span>';
                    } else if (messageContent.includes('declined') || messageContent.includes('rejected')) {
                        // Declined/rejected call
                        iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>';
                        bgColorClass = 'bg-orange-50';
                        textColorClass = 'text-orange-700';
                        callInfoHtml = '<span class="font-medium">Call Declined</span>';
                    } else if (messageContent.includes('ended')) {
                        // Call ended with duration
                        iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>';
                        bgColorClass = 'bg-blue-50';
                        textColorClass = 'text-blue-700';
                        
                        // Use call details if available
                        if (callDetails && callDetails.status === 'ended' && callDetails.duration > 0) {
                            const formattedDuration = callDetails.formatted_duration || formatCallDuration(callDetails.duration);
                            const callType = callDetails.type === 'video' ? 'Video Call' : 'Audio Call';
                            callInfoHtml = `<span class="font-medium">${callType}</span> • <span class="font-medium">${formattedDuration}</span>`;
                        } else {
                            callInfoHtml = '<span class="font-medium">Call Ended</span>';
                        }
                    } else if (messageContent.includes('accepted') || messageContent.includes('answered')) {
                        // Call accepted
                        iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                        bgColorClass = 'bg-green-50';
                        textColorClass = 'text-green-700';
                        callInfoHtml = '<span class="font-medium">Call Answered</span>';
                    } else {
                        // Generic call message or initiated call
                        const callType = callDetails && callDetails.type === 'video' ? 'Video' : 'Audio';
                        iconHtml = callType === 'Video' ? 
                            '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>' :
                            '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>';
                        bgColorClass = 'bg-blue-50';
                        textColorClass = 'text-blue-700';
                        
                        if (callDetails && callDetails.type) {
                            const callTypeText = callDetails.type === 'video' ? 'Video Call' : 'Audio Call';
                            callInfoHtml = `<span class="font-medium">${callTypeText} Initiated</span>`;
                        } else {
                            callInfoHtml = '<span class="font-medium">Call</span>';
                        }
                    }
                    
                    // Get user information for the call
                    let initiatorName = '';
                    let recipientName = '';
                    
                    if (callDetails) {
                        const isInitiator = callDetails.initiator_id == currentUserId;
                        // Get other user name from the conversation header or use a generic name
                        const conversationName = document.getElementById('conversation-name');
                        const otherUserName = conversationName ? conversationName.textContent : 'User';
                        
                        if (isInitiator) {
                            initiatorName = 'You';
                            recipientName = otherUserName;
                        } else {
                            initiatorName = otherUserName;
                            recipientName = 'You';
                        }
                    }
                    
                    // Create enhanced call history UI with better visual representation
                    messageEl.innerHTML = `
                        <div class="max-w-xs lg:max-w-md my-3">
                            <div class="${bgColorClass} ${textColorClass} border border-gray-200 rounded-lg px-4 py-3 text-center shadow-sm">
                                <div class="flex items-center justify-center">
                                    ${iconHtml}
                                    <div class="text-sm font-medium">${callInfoHtml}</div>
                                </div>
                                ${callDetails ? `
                                <div class="mt-2 text-xs">
                                    <span class="font-medium">${initiatorName}</span> → <span class="font-medium">${recipientName}</span>
                                </div>
                                ` : ''}
                                <div class="text-xs text-gray-500 mt-2">
                                    ${formatMessageTime(new Date(message.created_at || new Date()))}
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Append the message element to the messages list
                    messagesList.appendChild(messageEl);
                } else {
                    // Non-call system message
                    messageEl.innerHTML = `
                        <div class="max-w-xs lg:max-w-md my-2">
                            <div class="${bgColorClass} ${textColorClass} border border-gray-200 rounded-lg px-3 py-2 text-center shadow-sm">
                                <div class="flex items-center justify-center text-sm">
                                    ${iconHtml}
                                    <span>${messageContent}</span>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    ${formatMessageTime(message.created_at ? new Date(message.created_at) : new Date())}
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Append the message element to the messages list
                    messagesList.appendChild(messageEl);
                }
            } else {
                // Regular message processing - determine if current user is the sender
                const isSender = message.sender_id == document.body.dataset.userId;
                messageEl.className = isSender ? 'flex justify-end mb-4' : 'flex justify-start mb-4';
                
                // Format file attachment if exists
                let fileAttachment = '';
                if (message.file_path && message.file_name) {
                    const filePath = message.file_path.startsWith('/') 
                        ? '/bagoscout' + message.file_path 
                        : '/bagoscout/' + message.file_path;
                    const isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(message.file_name);
                    
                    if (isImage) {
                        fileAttachment = `
                            <div class="mt-2">
                                <a href="${filePath}" target="_blank" class="block">
                                    <img src="${filePath}" alt="${message.file_name}" class="max-w-xs max-h-48 rounded-md">
                                </a>
                            </div>
                        `;
                    } else {
                        fileAttachment = `
                            <div class="mt-2 flex items-center border border-gray-200 rounded-md p-2 bg-gray-50">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <a href="${filePath}" target="_blank" class="text-blue-500 hover:underline">${message.file_name}</a>
                            </div>
                        `;
                    }
                }
                
                // Check if message is edited
                const editedIndicator = message.is_edited ? '<div class="text-xs italic text-gray-500 mt-1">(edited)</div>' : '';
                
                // For sender messages, add read status indicator
                let readIndicator = '';
                if (isSender) {
                    // Always use double check for read messages (is_read=1)
                    // This fixes the issue where messages still show single check after page reload
                    if (message.is_read === 1 || message.is_read === true || message.is_read === '1') {
                        readIndicator = `
                            <span class="ml-1 ${message.is_read ? 'text-blue-500' : 'text-gray-400'}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 6L9.7 16.3l-2.1-2.1"></path>
                                    <path d="M18 12L9.7 22.3l-2.1-2.1"></path>
                                </svg>
                            </span>
                        `;
                    } else {
                        readIndicator = `
                            <span class="ml-1 text-gray-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 6L9 17l-5-5"></path>
                                </svg>
                            </span>
                        `;
                    }
                }
                
                // Message actions dropdown for sender messages
                const messageActions = isSender ? `
                    <div class="absolute right-1 top-1 hidden group-hover:flex space-x-1">
                        <button class="p-1 bg-blue-400 rounded-full hover:bg-blue-600 text-white edit-message-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                            </svg>
                        </button>
                        <button class="p-1 bg-red-400 rounded-full hover:bg-red-600 text-white delete-message-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                ` : '';
                
                messageEl.innerHTML = isSender ? `
                    <div class="max-w-xs lg:max-w-md">
                        <div class="bg-blue-500 text-white rounded-lg rounded-tr-none py-2 px-4 relative group">
                            <div class="text-sm">${message.message || message.content}</div>
                        ${fileAttachment}
                            ${editedIndicator}
                            ${messageActions}
                        </div>
                        <div class="text-xs text-gray-500 mt-1 text-right">
                            ${formatMessageTime(messageDate)}${readIndicator}
                        </div>
                    </div>
                ` : `
                    <div class="max-w-xs lg:max-w-md">
                        <div class="bg-gray-200 text-gray-800 rounded-lg rounded-tl-none py-2 px-4">
                            <div class="text-sm">${message.message || message.content}</div>
                        ${fileAttachment}
                            ${editedIndicator}
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            ${formatMessageTime(messageDate)}
                        </div>
                    </div>
                `;
                
                messagesList.appendChild(messageEl);
                
                // Add event listeners for message actions
                if (isSender) {
                    const editBtn = messageEl.querySelector('.edit-message-btn');
                    const deleteBtn = messageEl.querySelector('.delete-message-btn');
                    
                    if (editBtn) {
                        editBtn.addEventListener('click', () => {
                            editMessage(message.id, message.message || message.content);
                        });
                    }
                    
                    if (deleteBtn) {
                        deleteBtn.addEventListener('click', () => {
                            deleteMessage(message.id);
                        });
                    }
                }
            }
            
            // Scroll to bottom of messages
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        });
        
        // Scroll to bottom of messages
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    // Helper function to add a message to the UI
    function addMessageToUI(message, isFromCurrentUser) {
        // Create message element
        const messageEl = document.createElement('div');
        messageEl.dataset.messageId = message.id;
        messageEl.dataset.senderId = message.sender_id;
        
        // Get current user ID from the DOM
        const currentUserId = document.body.dataset.userId;
        
        // Check if this is a system message
        const isSystemMessage = message.is_system == 1;
        
        if (isSystemMessage) {
            // System message styling (centered, different background)
            messageEl.className = 'flex justify-center mb-4';
            
            // Format system message - detect if it's a call message
            const messageContent = message.message || message.content || '';
            let iconHtml = '';
            let bgColorClass = 'bg-gray-100';
            let textColorClass = 'text-gray-600';
            let callInfoHtml = '';
            
            // Add specific styling based on the system message content
            if (messageContent.includes('call') || messageContent.includes('Call')) {
                // Extract call details if available
                let callDetails = message.call_details || null;
                
                // It's a call related message
                if (messageContent.includes('missed') || messageContent.includes('didn\'t answer')) {
                    // Missed call
                    iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>';
                    bgColorClass = 'bg-red-50';
                    textColorClass = 'text-red-700';
                    callInfoHtml = '<span class="font-medium">Missed Call</span>';
                } else if (messageContent.includes('declined') || messageContent.includes('rejected')) {
                    // Declined/rejected call
                    iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>';
                    bgColorClass = 'bg-orange-50';
                    textColorClass = 'text-orange-700';
                    callInfoHtml = '<span class="font-medium">Call Declined</span>';
                } else if (messageContent.includes('ended')) {
                    // Call ended with duration
                    iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>';
                    bgColorClass = 'bg-blue-50';
                    textColorClass = 'text-blue-700';
                    
                    // Use call details if available
                    if (callDetails && callDetails.status === 'ended' && callDetails.duration > 0) {
                        const formattedDuration = callDetails.formatted_duration || formatCallDuration(callDetails.duration);
                        const callType = callDetails.type === 'video' ? 'Video Call' : 'Audio Call';
                        callInfoHtml = `<span class="font-medium">${callType}</span> • <span class="font-medium">${formattedDuration}</span>`;
                    } else {
                        callInfoHtml = '<span class="font-medium">Call Ended</span>';
                    }
                } else if (messageContent.includes('accepted') || messageContent.includes('answered')) {
                    // Call accepted
                    iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                    bgColorClass = 'bg-green-50';
                    textColorClass = 'text-green-700';
                    callInfoHtml = '<span class="font-medium">Call Answered</span>';
                } else {
                    // Generic call message or initiated call
                    const callType = callDetails && callDetails.type === 'video' ? 'Video' : 'Audio';
                    iconHtml = callType === 'Video' ? 
                        '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>' :
                        '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>';
                    bgColorClass = 'bg-blue-50';
                    textColorClass = 'text-blue-700';
                    
                    if (callDetails && callDetails.type) {
                        const callTypeText = callDetails.type === 'video' ? 'Video Call' : 'Audio Call';
                        callInfoHtml = `<span class="font-medium">${callTypeText} Initiated</span>`;
                    } else {
                        callInfoHtml = '<span class="font-medium">Call</span>';
                    }
                }
                
                // Get user information for the call
                let initiatorName = '';
                let recipientName = '';
                
                if (callDetails) {
                    const isInitiator = callDetails.initiator_id == currentUserId;
                    // Get other user name from the conversation header or use a generic name
                    const conversationName = document.getElementById('conversation-name');
                    const otherUserName = conversationName ? conversationName.textContent : 'User';
                    
                    if (isInitiator) {
                        initiatorName = 'You';
                        recipientName = otherUserName;
                    } else {
                        initiatorName = otherUserName;
                        recipientName = 'You';
                    }
                }
                
                // Create enhanced call history UI with better visual representation
                messageEl.innerHTML = `
                    <div class="max-w-xs lg:max-w-md my-3">
                        <div class="${bgColorClass} ${textColorClass} border border-gray-200 rounded-lg px-4 py-3 text-center shadow-sm">
                            <div class="flex items-center justify-center">
                                ${iconHtml}
                                <div class="text-sm font-medium">${callInfoHtml}</div>
                            </div>
                            ${callDetails ? `
                            <div class="mt-2 text-xs">
                                <span class="font-medium">${initiatorName}</span> → <span class="font-medium">${recipientName}</span>
                            </div>
                            ` : ''}
                            <div class="text-xs text-gray-500 mt-2">
                                ${formatMessageTime(new Date(message.created_at || new Date()))}
                            </div>
                        </div>
                    </div>
                `;
            } else {
                // Non-call system message
                messageEl.innerHTML = `
                    <div class="max-w-xs lg:max-w-md my-2">
                        <div class="${bgColorClass} ${textColorClass} border border-gray-200 rounded-lg px-3 py-2 text-center shadow-sm">
                            <div class="flex items-center justify-center text-sm">
                                ${iconHtml}
                                <span>${messageContent}</span>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                ${formatMessageTime(message.created_at ? new Date(message.created_at) : new Date())}
                            </div>
                        </div>
                    </div>
                `;
            }
        } else {
            // Regular message styling
            messageEl.className = isFromCurrentUser ? 'flex justify-end mb-4' : 'flex justify-start mb-4';
            
            // Format file attachment if present
            let fileAttachment = '';
            if (message.file_path && message.file_name) {
                const filePath = message.file_path.startsWith('/') 
                    ? '/bagoscout' + message.file_path 
                    : '/bagoscout/' + message.file_path;
                const isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(message.file_name);
                
                if (isImage) {
                    fileAttachment = `
                        <div class="mt-2">
                            <a href="${filePath}" target="_blank" class="block">
                                <img src="${filePath}" alt="${message.file_name}" class="max-w-xs max-h-48 rounded-md">
                            </a>
                        </div>
                    `;
                } else {
                    fileAttachment = `
                        <div class="mt-2 flex items-center border border-gray-200 rounded-md p-2 bg-gray-50">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <a href="${filePath}" target="_blank" class="text-blue-500 hover:underline">${message.file_name}</a>
                        </div>
                    `;
                }
            }
            
            // Get message content from either message or content field
            const messageContent = message.message || message.content || '';
            
            // Check if message was edited
            const editedIndicator = message.is_edited ? 
                '<div class="edited-indicator text-xs text-gray-500 mt-1">(edited)</div>' : '';
            
            // Format message based on sender
            if (isFromCurrentUser) {
                // Check icon - single for sent, double for read
                const readIcon = message.is_read ? 
                    // Double check for read messages
                    `<svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6L9.7 16.3l-2.1-2.1"></path>
                        <path d="M18 12L9.7 22.3l-2.1-2.1"></path>
                    </svg>` : 
                    // Single check for sent messages
                    `<svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6L9 17l-5-5"></path>
                    </svg>`;
                    
                // Add message actions dropdown for user's own messages
                const messageActions = `
                    <div class="message-actions absolute right-0 top-0 hidden group-hover:block">
                        <button class="p-1 rounded-full hover:bg-blue-600 text-white edit-message-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                            </svg>
                        </button>
                        <button class="p-1 rounded-full hover:bg-blue-600 text-white delete-message-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                `;
        
                messageEl.innerHTML = `
                    <div class="max-w-xs lg:max-w-md">
                        <div class="bg-blue-500 text-white rounded-lg rounded-tr-none py-2 px-4 relative group">
                            <div class="text-sm">${messageContent}</div>
                        ${fileAttachment}
                            ${editedIndicator}
                            ${messageActions}
                        </div>
                        <div class="text-xs text-gray-500 mt-1 text-right">
                            ${formatMessageTime(new Date(message.created_at || new Date()))}
                            <span class="ml-1 read-indicator">
                                ${readIcon}
                            </span>
                        </div>
                    </div>
                `;
            } else {
                messageEl.innerHTML = `
                    <div class="max-w-xs lg:max-w-md">
                        <div class="bg-gray-200 text-gray-800 rounded-lg rounded-tl-none py-2 px-4">
                            <div class="text-sm">${messageContent}</div>
                        ${fileAttachment}
                            ${editedIndicator}
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            ${formatMessageTime(new Date(message.created_at || new Date()))}
                        </div>
                    </div>
                `;
            }
            
            // Add event listeners for message actions
            if (!isSystemMessage && isFromCurrentUser) {
                const editBtn = messageEl.querySelector('.edit-message-btn');
                const deleteBtn = messageEl.querySelector('.delete-message-btn');
                
                if (editBtn) {
                    editBtn.addEventListener('click', () => {
                        editMessage(message.id, messageContent);
                    });
                }
                
                if (deleteBtn) {
                    deleteBtn.addEventListener('click', () => {
                        deleteMessage(message.id);
                    });
                }
            }
        }
        
        // Add to messages list and scroll to bottom
        messagesList.appendChild(messageEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    // Sending messages
    function sendMessage() {
        // Get message content
        const messageContent = messageInput.value.trim();
        
        // Check if message is empty and no file is selected
        if (!messageContent && !fileUpload.files.length) {
            return;
        }
        
        // Get send button
        const sendBtn = document.querySelector('#message-form button[type="submit"]');
        
        // Show sending status message
        const statusMessage = document.createElement('div');
        statusMessage.className = 'text-center text-xs text-gray-500 my-2 sending-status';
        statusMessage.innerHTML = `
            <svg class="animate-spin h-4 w-4 inline mr-1 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Sending message...
        `;
        messagesList.appendChild(statusMessage);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        // Disable send button and show loading state
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.innerHTML = `
                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            `;
        }
        
        // Create form data for file upload
        const formData = new FormData();
        formData.append('message', messageContent);
        formData.append('content', messageContent); // Add both for backward compatibility
        formData.append('conversation_id', currentConversationId);
        formData.append('receiver_id', currentReceiverId);
        formData.append('action', 'send_message'); // Add action parameter
        
        // Add file if selected
        if (fileUpload.files.length) {
            formData.append('file', fileUpload.files[0]);
        }
        
        // Add message to UI immediately with pending status
        const tempId = 'msg-' + Date.now();
        const messageEl = document.createElement('div');
        messageEl.className = 'flex justify-end mb-4';
        messageEl.dataset.messageId = tempId;
        messageEl.dataset.senderId = document.body.dataset.userId;
        messageEl.dataset.timestamp = new Date().toISOString();
        
        // Format file preview if present
        let filePreview = '';
        if (fileUpload.files.length) {
            const file = fileUpload.files[0];
            const isImage = file.type.startsWith('image/');
            
            if (isImage) {
                const imgUrl = URL.createObjectURL(file);
                filePreview = `
                    <div class="mt-2">
                        <img src="${imgUrl}" alt="${file.name}" class="max-w-xs max-h-48 rounded-md">
                    </div>
                `;
            } else {
                filePreview = `
                    <div class="mt-2 flex items-center border border-gray-200 rounded-md p-2 bg-gray-50">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="text-gray-700">${file.name}</span>
                    </div>
                `;
            }
        }
        
        // Add message actions dropdown for user's own messages
        const messageActions = `
            <div class="message-actions absolute right-0 top-0 hidden group-hover:block">
                <button class="p-1 rounded-full hover:bg-blue-600 text-white edit-message-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                    </svg>
                </button>
                <button class="p-1 rounded-full hover:bg-blue-600 text-white delete-message-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        `;
        
        messageEl.innerHTML = `
            <div class="max-w-xs lg:max-w-md">
                <div class="bg-blue-500 text-white rounded-lg rounded-tr-none py-2 px-4 relative group">
                    <div class="text-sm">${messageContent || ''}</div>
                ${filePreview}
                    ${messageActions}
                </div>
                <div class="text-xs text-gray-500 mt-1 text-right">
                    ${formatMessageTime(new Date())}
                    <span class="ml-1 read-indicator">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </span>
                </div>
            </div>
        `;
        
        // Remove the sending status and add the actual message
        const sendingStatus = messagesList.querySelector('.sending-status');
        if (sendingStatus) {
            sendingStatus.remove();
        }
        
        messagesList.appendChild(messageEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        // Clear input
        messageInput.value = '';
        messageInput.style.height = 'auto';
        fileUpload.value = '';
        fileName.textContent = '';
        const fileUploadContainer = document.getElementById('file-upload-container');
        if (fileUploadContainer) {
            fileUploadContainer.classList.add('hidden');
        }
        
        // Add event listeners for message actions
        const editBtn = messageEl.querySelector('.edit-message-btn');
        const deleteBtn = messageEl.querySelector('.delete-message-btn');
        
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                // We don't have the message ID yet, but we can use the ID of the element
                const actualMessageId = messageEl.dataset.messageId;
                if (actualMessageId && !actualMessageId.startsWith('msg-')) {
                    editMessage(actualMessageId, messageContent);
                } else {
                    showError('Cannot edit a message that is still sending');
                }
            });
        }
        
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => {
                const actualMessageId = messageEl.dataset.messageId;
                if (actualMessageId && !actualMessageId.startsWith('msg-')) {
                    deleteMessage(actualMessageId);
                } else {
                    showError('Cannot delete a message that is still sending');
                }
            });
        }
        
        // Send message to server
        fetch('/bagoscout/api/messages.php?action=send_message', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to send message: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || 'Failed to send message');
            }
            
            console.log('Message sent successfully:', data);
            
            // Update message element with real ID
            const messageEl = document.querySelector(`[data-message-id="${tempId}"]`);
            if (messageEl) {
                // Handle both response formats (message_id or message.id)
                const messageId = data.message_id || (data.message && data.message.id);
                if (messageId) {
                    messageEl.dataset.messageId = messageId;
                }
                
                // Update sending indicator to sent
                const statusEl = messageEl.querySelector('.text-right');
                if (statusEl) {
                    const indicator = statusEl.querySelector('.read-indicator');
                    if (indicator) {
                        indicator.innerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6L9 17l-5-5"></path>
                            </svg>
                        `;
                    }
                }
            }
            
            // Send real-time notification via Ably
            if (ablyInitialized && currentChannel) {
                // Handle both response formats (message_id or message.id)
                const messageId = data.message_id || (data.message && data.message.id);
                const filePath = data.file_path || (data.message && data.message.file_path) || null;
                const fileName = data.file_name || (data.message && data.message.file_name) || null;
                
                const messageData = {
                    id: messageId,
                    sender_id: String(document.body.dataset.userId), // Ensure sender_id is a string
                    receiver_id: String(currentReceiverId), // Ensure receiver_id is a string
                    message: messageContent,
                    file_path: filePath,
                    file_name: fileName,
                    created_at: new Date().toISOString()
                };
                
                try {
                    console.log("%c PUBLISHING MESSAGE TO ABLY (SEEKER) ", "background: #4caf50; color: white; font-size: 12px; font-weight: bold;");
                    console.log("Channel name:", currentChannel.name);
                    console.log("Event name: new-message");
                    console.log("Message data:", messageData);
                    
                    currentChannel.publish('new-message', messageData);
                    console.log('Message published to Ably successfully');
                } catch (err) {
                    console.error('Error publishing message to Ably:', err);
                    // Message was still sent via API, so no need to show error to user
                }
            }
        })
        .catch(error => {
            console.error('Error sending message:', error);
            showError('Failed to send message. Please try again.');
            
            // Update sending indicator to error
            const messageEl = document.querySelector(`[data-message-id="${tempId}"]`);
            if (messageEl) {
                const statusEl = messageEl.querySelector('.text-right');
                if (statusEl) {
                    const indicator = statusEl.querySelector('.read-indicator');
                    if (indicator) {
                        indicator.innerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        `;
                    }
                }
                
                // Add retry button
                const messageDiv = messageEl.querySelector('.bg-blue-500');
                if (messageDiv) {
                    const retryButton = document.createElement('button');
                    retryButton.className = 'text-xs text-white bg-red-500 hover:bg-red-600 rounded px-2 py-1 mt-2';
                    retryButton.textContent = 'Retry';
                    retryButton.onclick = function(e) {
                        e.preventDefault();
                        // Remove this message and try again
                        messageEl.remove();
                        
                        // Re-add message to input
                        messageInput.value = messageContent;
                        
                        // Re-add file if it was included
                        if (data && data.file_path) {
                            // We can't restore the file input, so just notify the user
                            showError('Please re-attach your file before sending.');
                        }
                    };
                    messageDiv.appendChild(retryButton);
                }
            }
        })
        .finally(() => {
            // Reset send button
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z" />
                    </svg>
                `;
            }
        });
    }
    
    // Mark conversation as read
    function markConversationAsRead(conversationId) {
        if (!conversationId) return;
        
        // Mark as read in the database
        fetch('/bagoscout/api/messages.php?action=mark_as_read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'conversation_id=' + conversationId
        })
        .then(response => response.json())
        .then(data => {
            console.log('Marked conversation as read:', data);
        })
        .catch(error => {
            console.error('Error marking conversation as read:', error);
        });
        
        // Publish read status to Ably
        console.log('Publishing read status to Ably');
        
        if (currentChannel && ably && ably.connection.state === 'connected') {
            try {
                currentChannel.publish('read-status', {
                    user_id: document.body.dataset.userId,
                    conversation_id: conversationId,
                    timestamp: new Date().toISOString()
                });
                console.log('Published read status successfully');
            } catch (error) {
                console.error('Error marking as read:', error);
            }
        }
    }
    
    // Subscribe to Ably channel
    function subscribeToChannel(conversationId) {
        // Only proceed if Ably is initialized
        if (!ably || !ablyInitialized) {
            console.error('Ably not initialized, attempting to initialize');
            initializeAbly();
            
            // Set a timeout to try again after Ably initializes
            setTimeout(() => {
                if (ablyInitialized && ably) {
                    console.log('Ably initialized, now subscribing to channel');
                    subscribeToChannel(conversationId);
                }
            }, 2000);
            return;
        }
        
        // Check if Ably is connected
        if (ably.connection.state !== 'connected') {
            console.log('Ably not connected, current state:', ably.connection.state);
            
            // Try to connect
            ably.connection.connect();
            
            // Listen for the connected event and then subscribe
            const onConnected = function() {
                console.log('Ably connected, now subscribing to channel');
                ably.connection.off('connected', onConnected);
                subscribeToChannel(conversationId);
            };
            
            ably.connection.once('connected', onConnected);
            return;
        }
        
        console.log('Ably is connected, proceeding with channel subscription');
        
        // Use a standardized channel naming convention
        const channelName = `conversation-${conversationId}`;
        
        // Check if we're already subscribed to this channel
        if (currentChannel && currentChannel.name === channelName) {
            console.log('Already subscribed to this channel');
            return;
        }
        
        // Detach from previous channel if exists
        if (currentChannel) {
            try {
                console.log('Detaching from previous channel:', currentChannel.name);
                currentChannel.unsubscribe();
                currentChannel.detach();
            } catch (error) {
                console.error('Error detaching from previous channel:', error);
            }
        }
        
        try {
            // Subscribe to new channel
            console.log('Subscribing to channel:', channelName);
            currentChannel = ably.channels.get(channelName);
            
            // Subscribe to new messages
            currentChannel.subscribe('new-message', function(message) {
                console.log('%c ======= ABLY NEW MESSAGE RECEIVED (SEEKER) ======= ', 'background: #4caf50; color: white; font-size: 12px; font-weight: bold;');
                console.log('Raw message object:', message);
                console.log('Message name:', message.name);
                console.log('Message data:', message.data);
                console.log('Message clientId:', message.clientId);
                console.log('Message connectionId:', message.connectionId);
                console.log('Current user ID:', document.body.dataset.userId);
                
                // Parse the message data
                const messageData = typeof message.data === 'string' 
                    ? JSON.parse(message.data) 
                    : message.data;
                
                console.log('Parsed message data:', messageData);
                
                // Convert IDs to strings for comparison
                const senderId = String(messageData.sender_id);
                const currentUserId = String(document.body.dataset.userId);
                
                console.log('Message sender ID (string):', senderId);
                console.log('Current user ID (string):', currentUserId);
                console.log('Are IDs different?', senderId !== currentUserId);
                
                // Only process messages from other users
                if (senderId !== currentUserId) {
                    console.log('Message from other user, adding to UI');
                    
                    // Check if this message already exists in the UI
                    const existingMessage = document.querySelector(`[data-message-id="${messageData.id}"]`);
                    if (existingMessage) {
                        console.log('Message already exists in UI, skipping');
                        return;
                    }
                    
                    // Use the addMessageToUI helper function instead of duplicating code
                    addMessageToUI(messageData, false);
                    
                    // Mark conversation as read
                    markConversationAsRead(conversationId);
                } else {
                    console.log('Message is from current user, ignoring');
                }
                console.log('%c ================= END SEEKER ================= ', 'background: #4caf50; color: white; font-size: 12px; font-weight: bold;');
            });
            
            // Subscribe to edited message events
            currentChannel.subscribe('message-edited', function(message) {
                console.log('Received edited message:', message);
                
                try {
                    // Parse the message data
                    const editData = typeof message.data === 'string' 
                        ? JSON.parse(message.data) 
                        : message.data;
                    
                    console.log('Parsed edit data:', editData);
                    
                    // Only process edits from other users
                    if (String(editData.sender_id) !== String(document.body.dataset.userId)) {
                        console.log('Message edited by other user, updating UI');
                        
                        // Find the message in the UI
                        const messageEl = document.querySelector(`[data-message-id="${editData.id}"]`);
                        if (messageEl) {
                            // Update the message content
                            const contentEl = messageEl.querySelector('.text-sm');
                            if (contentEl) {
                                contentEl.textContent = editData.message;
                            }
                            
                            // Add edited indicator if not already present
                            const messageDiv = messageEl.querySelector('.bg-gray-200');
                            if (messageDiv && !messageDiv.querySelector('.edited-indicator')) {
                                const editedIndicator = document.createElement('div');
                                editedIndicator.className = 'edited-indicator text-xs text-gray-500 mt-1';
                                editedIndicator.textContent = '(edited)';
                                messageDiv.appendChild(editedIndicator);
                            }
                        }
                    }
                } catch (error) {
                    console.error('Error handling edited message:', error);
                }
            });
            
            // Subscribe to deleted message events
            currentChannel.subscribe('message-deleted', function(message) {
                console.log('Received deleted message event:', message);
                
                try {
                    // Parse the message data
                    const deleteData = typeof message.data === 'string' 
                        ? JSON.parse(message.data) 
                        : message.data;
                    
                    console.log('Parsed delete data:', deleteData);
                    
                    // Only process deletes from other users
                    if (String(deleteData.sender_id) !== String(document.body.dataset.userId)) {
                        console.log('Message deleted by other user, updating UI');
                        
                        // Find and remove the message from the UI
                        const messageEl = document.querySelector(`[data-message-id="${deleteData.id}"]`);
                        if (messageEl) {
                            messageEl.remove();
                        }
                    }
                } catch (error) {
                    console.error('Error handling deleted message:', error);
                }
            });
            
            // Subscribe to read status updates
            currentChannel.subscribe('read-status', function(message) {
                console.log('Received read status:', message);
                
                // Parse the message data
                const readData = typeof message.data === 'string' 
                    ? JSON.parse(message.data) 
                    : message.data;
                
                // Only process read status from other users
                if (readData.user_id != document.body.dataset.userId) {
                    console.log('Read status from other user, updating UI');
                    
                    // Update read indicators for all messages
                    const messages = messagesList.querySelectorAll('[data-sender-id="' + document.body.dataset.userId + '"]');
                    messages.forEach(message => {
                        // Add read indicator if not already present
                        const timestampEl = message.querySelector('.text-right');
                        if (timestampEl) {
                            const readIndicator = timestampEl.querySelector('.read-indicator');
                            if (readIndicator) {
                                readIndicator.innerHTML = `
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 6L9.7 16.3l-2.1-2.1"></path>
                                        <path d="M18 12L9.7 22.3l-2.1-2.1"></path>
                                    </svg>
                                `;
                            }
                        }
                    });
                }
            });
            
            // Listen for typing indicators
            currentChannel.subscribe('typing', function(message) {
                const typingData = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;
                
                if (typingData.user_id == currentReceiverId) {
                    conversationStatus.textContent = 'Typing...';
                    
                    // Reset after 3 seconds
                    clearTimeout(window.typingTimeout);
                    window.typingTimeout = setTimeout(() => {
                        conversationStatus.textContent = 'Online';
                    }, 3000);
                }
            });
            
            // Listen for online status
            currentChannel.subscribe('status', function(message) {
                const statusData = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;
                
                if (statusData.user_id == currentReceiverId) {
                    conversationStatus.textContent = statusData.status === 'online' ? 'Online' : 'Offline';
                }
            });
            
            // Publish online status
            try {
                currentChannel.publish('status', { 
                    user_id: document.body.dataset.userId,
                    status: 'online'
                });
                console.log('Published online status successfully');
            } catch (err) {
                console.error('Error publishing status:', err);
            }
        } catch (error) {
            console.error('Error subscribing to channel:', error);
            showError('Error connecting to conversation. Please refresh the page.');
        }
    }
    
    // Helper functions
    function getInitials(name) {
        if (!name) return '';
        return name
            .split(' ')
            .map(n => n[0])
            .join('')
            .toUpperCase()
            .substring(0, 2);
    }
    
    function formatDate(date) {
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        
        if (date.toDateString() === today.toDateString()) {
            return 'Today';
        } else if (date.toDateString() === yesterday.toDateString()) {
            return 'Yesterday';
        } else {
            return date.toLocaleDateString();
        }
    }
    
    function formatMessageTime(date) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    // Event listeners
    messageForm.addEventListener('submit', function(e) {
        e.preventDefault();
        sendMessage();
    });
    
    // Send typing indicator
    function sendTypingIndicator() {
        if (!currentChannel || !ablyInitialized || !currentConversationId || !currentReceiverId) {
            return;
        }
        
        try {
            currentChannel.publish('typing', {
                user_id: document.body.dataset.userId,
                conversation_id: currentConversationId,
                timestamp: new Date().toISOString()
            });
        } catch (error) {
            console.error('Error sending typing indicator:', error);
        }
    }
    
    // Add typing indicator event
    messageInput.addEventListener('input', function() {
        // Throttle typing events to avoid sending too many
        if (window.typingTimeout) {
            clearTimeout(window.typingTimeout);
        }
        
        window.typingTimeout = setTimeout(sendTypingIndicator, 500);
    });
    
    conversationSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const conversations = conversationList.querySelectorAll('[data-conversation-id]');
        
        conversations.forEach(conv => {
            const name = conv.dataset.participantName.toLowerCase();
            if (name.includes(query)) {
                conv.classList.remove('hidden');
            } else {
                conv.classList.add('hidden');
            }
        });
    });
    
    // Set online status
    window.addEventListener('beforeunload', function() {
        if (currentChannel) {
            currentChannel.publish('status', { 
                user_id: 'current_user_id',
                status: 'offline'
            });
        }
    });
    
    // Publish online status
    setTimeout(function() {
        if (currentChannel) {
            currentChannel.publish('status', { 
                user_id: 'current_user_id',
                status: 'online'
            });
        }
    }, 1000);

    // Add event listener for close conversation button
    const closeConversationBtn = document.getElementById('close-conversation-btn');
    if (closeConversationBtn) {
        closeConversationBtn.addEventListener('click', () => {
            // Show the conversation list on mobile
            const leftSidebar = document.getElementById('left-sidebar');
            if (leftSidebar) {
                leftSidebar.classList.add('hidden');
                mobileMenuToggle.forEach(toggle => {
                    toggle.classList.remove('hidden');
                });
            }
        });
    }

    // Edit message function
    function editMessage(messageId, currentContent) {
        // First, get the most current content from the UI
        const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
        let actualCurrentContent = currentContent;
        
        if (messageEl) {
            const contentEl = messageEl.querySelector('.text-sm');
            if (contentEl) {
                actualCurrentContent = contentEl.textContent;
            }
        }
        
        // Ask user for the new message content
        Swal.fire({
            title: 'Edit Message',
            input: 'textarea',
            inputValue: actualCurrentContent,
            showCancelButton: true,
            confirmButtonText: 'Save',
            cancelButtonText: 'Cancel',
            inputValidator: (value) => {
                if (!value.trim()) {
                    return 'Please enter a message';
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const newContent = result.value.trim();
                
                // Only proceed if content actually changed
                if (newContent !== actualCurrentContent) {
                    // Send edit request to server
                    fetch('/bagoscout/api/messages.php?action=edit_message', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `message_id=${messageId}&content=${encodeURIComponent(newContent)}`
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            console.log('Message edited successfully');
                            
                            // Update message in UI
                            if (messageEl) {
                                const contentEl = messageEl.querySelector('.text-sm');
                                if (contentEl) {
                                    contentEl.textContent = newContent;
                                }
                                
                                // Add edited indicator if not already present
                                const messageDiv = messageEl.querySelector('.bg-blue-500');
                                if (messageDiv && !messageDiv.querySelector('.edited-indicator')) {
                                    const editedIndicator = document.createElement('div');
                                    editedIndicator.className = 'edited-indicator text-xs text-white opacity-70 mt-1';
                                    editedIndicator.textContent = '(edited)';
                                    messageDiv.appendChild(editedIndicator);
                                }
                            }
                            
                            // Also publish to Ably directly in case server-side publishing failed
                            if (currentChannel && ablyInitialized) {
                                try {
                                    currentChannel.publish('message-edited', {
                                        id: messageId,
                                        sender_id: document.body.dataset.userId,
                                        message: newContent,
                                        is_edited: 1
                                    });
                                } catch (err) {
                                    console.error('Error publishing edit to Ably:', err);
                                }
                            }
                        } else {
                            console.error('Failed to edit message:', data.error || 'Unknown error');
                            showError('Failed to edit message');
                        }
                    })
                    .catch(error => {
                        console.error('Error editing message:', error);
                        showError('Error editing message. Please try again.');
                    });
                }
            }
        });
    }
    
    // Delete message function
    function deleteMessage(messageId) {
        // Confirm deletion
        Swal.fire({
            title: 'Delete Message',
            text: 'Are you sure you want to delete this message?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33'
        }).then((result) => {
            if (result.isConfirmed) {
                // Send delete request to server
                fetch('/bagoscout/api/messages.php?action=delete_message', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `message_id=${messageId}`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        console.log('Message deleted successfully');
                        
                        // Remove message from UI
                        const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
                        if (messageEl) {
                            messageEl.remove();
                        }
                        
                        // Also publish to Ably directly in case server-side publishing failed
                        if (currentChannel && ablyInitialized) {
                            try {
                                currentChannel.publish('message-deleted', {
                                    id: messageId,
                                    sender_id: document.body.dataset.userId
                                });
                            } catch (err) {
                                console.error('Error publishing deletion to Ably:', err);
                            }
                        }
                    } else {
                        console.error('Failed to delete message:', data.error || 'Unknown error');
                        showError('Failed to delete message');
                    }
                })
                .catch(error => {
                    console.error('Error deleting message:', error);
                    showError('Error deleting message. Please try again.');
                });
            }
        });
    }

    function selectConversation(conversationId, otherUser) {
        console.log('Selecting conversation:', conversationId);
        console.log('Other user:', otherUser);
        
        // Store current conversation ID and other user
        currentConversationId = conversationId;
        currentReceiverId = otherUser.id;
        
        // Update UI
        if (noConversationView) {
            noConversationView.classList.add('hidden');
        }
        
        if (conversationView) {
            conversationView.classList.remove('hidden');
            conversationView.classList.add('flex');
        }
        
        // Update conversation header
        if (conversationName) {
            conversationName.textContent = otherUser.name;
        }
        
        if (conversationStatus) {
            conversationStatus.textContent = 'Online'; // Default status
        }
        
        // Update avatar
        if (conversationAvatarImg && conversationAvatarPlaceholder) {
            if (otherUser.profile) {
                conversationAvatarImg.src = otherUser.profile;
                conversationAvatarImg.classList.remove('hidden');
                conversationAvatarPlaceholder.classList.add('hidden');
            } else {
                conversationAvatarImg.classList.add('hidden');
                conversationAvatarPlaceholder.classList.remove('hidden');
                conversationAvatarPlaceholder.textContent = getInitials(otherUser.name);
            }
        }
        
        // Highlight selected conversation
        const conversations = document.querySelectorAll('.conversation-item');
        conversations.forEach(conv => {
            conv.classList.remove('bg-blue-50', 'border-blue-500');
        });
        
        const selectedConversation = document.querySelector(`.conversation-item[data-conversation-id="${conversationId}"]`);
        if (selectedConversation) {
            selectedConversation.classList.add('bg-blue-50', 'border-blue-500');
        }
        
        // On mobile, collapse the sidebar after selecting a conversation
        if (window.innerWidth < 768 && leftSidebar) {
            leftSidebar.classList.add('hidden');
            if (mobileMenuToggle) {
                mobileMenuToggle.forEach(toggle => {
                    toggle.classList.remove('hidden');
                });
            }
        }
        
        // Load messages
        loadMessages(conversationId);
        
        // Subscribe to Ably channel
        subscribeToChannel(conversationId);
        
        // Mark conversation as read
        markConversationAsRead(conversationId);
        
        // Setup call buttons
        setupCallButtons(otherUser.id, otherUser.name);
        
        // Dispatch custom event with recipient ID for call handler
        document.dispatchEvent(new CustomEvent('recipientSelected', {
            detail: {
                recipientId: otherUser.id,
                recipientName: otherUser.name
            }
        }));
    }

    // Format call duration for display
    function formatCallDuration(seconds) {
        if (!seconds) return '0 seconds';
        
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainingSeconds = seconds % 60;
        
        let formattedDuration = '';
        
        if (hours > 0) {
            formattedDuration += `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
        }
        
        if (minutes > 0) {
            if (formattedDuration) formattedDuration += ' ';
            formattedDuration += `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
        }
        
        if (remainingSeconds > 0 || (!hours && !minutes)) {
            if (formattedDuration) formattedDuration += ' ';
            formattedDuration += `${remainingSeconds} ${remainingSeconds === 1 ? 'second' : 'seconds'}`;
        }
        
        return formattedDuration;
    }
}); 