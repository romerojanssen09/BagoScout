// Employer Messaging System
console.log('Employer messaging system loading...');

// Initialize global variables
let ablyClient = null;
let ablyChannel = null;
let ablyInitialized = false;
let currentConversationId = null;
let currentOtherUser = null;

// DOM Elements - will be initialized when document is loaded
let conversationList, messagesContainer, messagesList, messageForm, messageInput;
let noConversationSelected, conversationView, conversationName, conversationStatus;
let conversationAvatarImg, conversationAvatarPlaceholder, fileUpload, filePreviewContainer;
let fileName, clearFileBtn, leftSidebar, mobileMenuToggle;
let userSearchInput, userSearchResults;

document.addEventListener('DOMContentLoaded', () => {
    // Log user information
    console.log('Employer messaging system initializing...');
    console.log('User ID from data attribute:', document.body.dataset.userId);
    console.log('User name:', document.body.dataset.userName);

    // Initialize DOM Elements
    conversationList = document.getElementById('conversation-list');
    messagesContainer = document.getElementById('messages-container');
    messagesList = document.getElementById('messages-list');
    messageForm = document.getElementById('message-form');
    messageInput = document.getElementById('message-input');
    noConversationSelected = document.getElementById('no-conversation-selected');
    conversationView = document.getElementById('conversation-view');
    conversationName = document.getElementById('conversation-name');
    conversationStatus = document.getElementById('conversation-status');
    conversationAvatarImg = document.getElementById('conversation-avatar-img');
    conversationAvatarPlaceholder = document.getElementById('conversation-avatar-placeholder');
    fileUpload = document.getElementById('file-upload');
    filePreviewContainer = document.getElementById('file-upload-container');
    fileName = document.getElementById('file-name');
    clearFileBtn = document.getElementById('clear-file');
    leftSidebar = document.getElementById('left-sidebar');
    mobileMenuToggle = document.querySelectorAll('#mobile-menu-toggle');

    // Verify essential DOM elements
    if (!conversationList) console.error('Conversation list not found');
    if (!messagesContainer) console.error('Messages container not found');
    if (!messagesList) console.error('Messages list not found');
    if (!messageForm) console.error('Message form not found');
    if (!messageInput) console.error('Message input not found');

    // Initialize Ably
    initAbly();

    // File upload event
    if (fileUpload) {
        fileUpload.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                if (fileName) {
                    fileName.textContent = e.target.files[0].name;
                }
                // Show selected file name
                if (filePreviewContainer) {
                    filePreviewContainer.classList.remove('hidden');
                }
            } else {
                if (fileName) {
                    fileName.textContent = '';
                }
                if (filePreviewContainer) {
                    filePreviewContainer.classList.add('hidden');
                }
            }
        });

        // Clear file button
        if (clearFileBtn) {
            clearFileBtn.addEventListener('click', () => {
                fileUpload.value = '';
                if (fileName) {
                    fileName.textContent = '';
                }
                if (filePreviewContainer) {
                    filePreviewContainer.classList.add('hidden');
                }
            });
        }
    }

    // Mobile menu toggle
    if (mobileMenuToggle && leftSidebar) {
        mobileMenuToggle.forEach(toggle => {
            toggle.addEventListener('click', () => {
                leftSidebar.classList.remove('hidden');
                toggle.classList.add('hidden');
            });
        });
    }

    // Initialize Ably
    function initAbly() {
        try {
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
                    if (!data.success) {
                        throw new Error('Invalid response from auth server: ' + JSON.stringify(data));
                    }

                    console.log('Ably auth successful, received token');
                    console.log('Client ID from server:', data.clientId);

                    // Initialize Ably with the key
                    const ablyOptions = {
                        key: data.key, // Using the provided key
                        clientId: 'user-' + data.user_id,
                        echoMessages: false
                    };

                    // Create a new Ably client
                    ablyClient = new Ably.Realtime(ablyOptions);

                    // Set up listeners
                    setupAblyListeners();

                    // Load conversations
                    loadConversations();
                })
                .catch(error => {
                    console.error('Error initializing Ably:', error);
                    showError('Failed to initialize messaging service. Please try refreshing the page.');

                    // Try to reinitialize after a delay
                    setTimeout(initAbly, 5000);
                });
        } catch (error) {
            console.error('Error initializing Ably:', error);
            showError('Failed to initialize messaging service. Please try refreshing the page.');

            // Try to reinitialize after a delay
            setTimeout(initAbly, 5000);
        }
    }

    // Set up Ably connection listeners
    function setupAblyListeners() {
        if (!ablyClient) return;

        ablyClient.connection.on('connected', () => {
            console.log('Connected to Ably');
            ablyInitialized = true;

            // Subscribe to conversation if one is selected
            if (currentConversationId) {
                subscribeToConversation(currentConversationId);
            }
        });

        ablyClient.connection.on('failed', (err) => {
            console.error('Ably connection failed:', err);
            ablyInitialized = false;
            showError('Failed to connect to messaging service. Please try refreshing the page.');
        });

        ablyClient.connection.on('disconnected', () => {
            console.log('Disconnected from Ably, attempting to reconnect...');
            // Don't set ablyInitialized to false here, as Ably will try to reconnect automatically

            // Try to reconnect after a short delay
            setTimeout(() => {
                if (ablyClient && ablyClient.connection.state !== 'connected') {
                    console.log('Attempting to reconnect to Ably...');
                    ablyClient.connection.connect();
                }
            }, 3000);
        });

        ablyClient.connection.on('suspended', () => {
            console.log('Ably connection suspended, will retry automatically');

            // Try to reconnect after a delay
            setTimeout(() => {
                if (ablyClient && ablyClient.connection.state !== 'connected') {
                    console.log('Attempting to reconnect to Ably from suspended state...');
                    ablyClient.connection.connect();
                }
            }, 10000);
        });

        ablyClient.connection.on('closed', () => {
            console.log('Connection to Ably closed');
            ablyInitialized = false;

            // Try to reconnect after a delay
            setTimeout(initAbly, 5000);
        });

        ablyClient.connection.on('error', (err) => {
            console.error('Ably connection error:', err);

            // Try to reconnect if there's an error
            setTimeout(() => {
                if (ablyClient && ablyClient.connection.state !== 'connected') {
                    console.log('Attempting to reconnect to Ably after error...');
                    ablyClient.connection.connect();
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
                    <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1 1 0 011.414 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                </svg>
            </span>
        `;
        document.body.appendChild(errorDiv);

        // Add click event to close
        const closeBtn = errorDiv.querySelector('svg');
        closeBtn.addEventListener('click', function () {
            errorDiv.remove();
        });

        // Auto remove after 10 seconds
        setTimeout(function () {
            if (document.body.contains(errorDiv)) {
                errorDiv.remove();
            }
        }, 10000);
    }

    // Subscribe to Ably channel for conversation
    function subscribeToConversation(conversationId) {
        // Only proceed if Ably is initialized
        if (!ablyClient || !ablyInitialized) {
            console.error('Ably not initialized, attempting to initialize');
            initAbly();

            // Set a timeout to try again after Ably initializes
            setTimeout(() => {
                if (ablyInitialized && ablyClient) {
                    console.log('Ably initialized, now subscribing to channel');
                    subscribeToConversation(conversationId);
                }
            }, 2000);
            return;
        }

        // Check if Ably is connected
        if (ablyClient.connection.state !== 'connected') {
            console.log('Ably not connected, current state:', ablyClient.connection.state);

            // Try to connect
            ablyClient.connection.connect();

            // Listen for the connected event and then subscribe
            const onConnected = function () {
                console.log('Ably connected, now subscribing to channel');
                ablyClient.connection.off('connected', onConnected);
                subscribeToConversation(conversationId);
            };

            ablyClient.connection.once('connected', onConnected);
            return;
        }

        console.log('Ably is connected, proceeding with channel subscription');

        // Use a standardized channel naming convention
        const channelName = `conversation-${conversationId}`;

        // Check if we're already subscribed to this channel
        if (ablyChannel && ablyChannel.name === channelName) {
            console.log('Already subscribed to this channel');
            return;
        }

        // Detach from previous channel if exists
        if (ablyChannel) {
            try {
                console.log('Detaching from previous channel:', ablyChannel.name);
                ablyChannel.unsubscribe();
                ablyChannel.detach();
            } catch (error) {
                console.error('Error detaching from previous channel:', error);
            }
        }

        try {
            // Subscribe to new channel
            console.log('Subscribing to channel:', channelName);
            ablyChannel = ablyClient.channels.get(channelName);

            // Subscribe to new messages
            ablyChannel.subscribe('new-message', function (message) {
                console.log('%c ======= ABLY NEW MESSAGE RECEIVED (EMPLOYER) ======= ', 'background: #e91e63; color: white; font-size: 12px; font-weight: bold;');
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

                // Only check sender_id (not clientId)
                if (senderId !== currentUserId) {
                    console.log('Message is from another user, adding to UI');

                    // Check if this message already exists in the UI
                    const existingMessage = document.querySelector(`[data-message-id="${messageData.id}"]`);
                    if (existingMessage) {
                        console.log('Message already exists in UI, skipping');
                        return;
                    }

                    // Add message to UI
                    addMessageToUI(messageData, false);

                    // Mark conversation as read
                    markConversationAsRead(conversationId);
                } else {
                    console.log('Message is from current user, ignoring');
                }
                console.log('%c ================= END EMPLOYER ================= ', 'background: #e91e63; color: white; font-size: 12px; font-weight: bold;');
            });

            // Subscribe to edited message events
            ablyChannel.subscribe('message-edited', function (message) {
                console.log('Received edited message:', message);

                // Parse the message data
                const editData = typeof message.data === 'string'
                    ? JSON.parse(message.data)
                    : message.data;

                // Only process edits from other users
                if (editData.sender_id != document.body.dataset.userId) {
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
            });

            // Subscribe to deleted message events
            ablyChannel.subscribe('message-deleted', function (message) {
                console.log('Received deleted message event:', message);

                // Parse the message data
                const deleteData = typeof message.data === 'string'
                    ? JSON.parse(message.data)
                    : message.data;

                // Only process deletes from other users
                if (deleteData.sender_id != document.body.dataset.userId) {
                    console.log('Message deleted by other user, updating UI');

                    // Find and remove the message from the UI
                    const messageEl = document.querySelector(`[data-message-id="${deleteData.id}"]`);
                    if (messageEl) {
                        messageEl.remove();
                    }
                }
            });

            // Subscribe to read status updates
            ablyChannel.subscribe('read-status', function (message) {
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
                                // Update to double check icon
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
            ablyChannel.subscribe('typing', function (message) {
                const typingData = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;

                if (typingData.user_id == currentOtherUser.id) {
                    conversationStatus.textContent = 'Typing...';

                    // Reset after 3 seconds
                    clearTimeout(window.typingTimeout);
                    window.typingTimeout = setTimeout(() => {
                        conversationStatus.textContent = 'Online';
                    }, 3000);
                }
            });

            // Listen for online status
            ablyChannel.subscribe('status', function (message) {
                const statusData = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;

                if (statusData.user_id == currentOtherUser.id) {
                    conversationStatus.textContent = statusData.status === 'online' ? 'Online' : 'Offline';
                }
            });

            // Publish online status
            try {
                ablyChannel.publish('status', {
                    user_id: document.body.dataset.userId,
                    status: 'online'
                });
                console.log('Published online status successfully');
            } catch (err) {
                console.error('Error publishing status:', err);
            }
        } catch (error) {
            console.error('Error subscribing to channel:', error);
            showError('Failed to subscribe to conversation channel. Please try refreshing the page.');
        }
    }

    // Load conversations
    function loadConversations() {
        fetch('/bagoscout/api/messages.php?action=get_conversations')
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error('Error loading conversations:', data.error);
                    conversationList.innerHTML = '<div class="p-4 text-center text-red-500">Error loading conversations</div>';
                    return;
                }

                if (!data.conversations || data.conversations.length === 0) {
                    conversationList.innerHTML = '<div class="p-4 text-center text-gray-500">No conversations yet</div>';
                    return;
                }

                conversationList.innerHTML = '';
                data.conversations.forEach(conversation => {
                    // Create a standardized otherUser object from the conversation data
                    let otherUser = {};

                    if (conversation.other_user_id) {
                        // New API format
                        otherUser = {
                            id: conversation.other_user_id,
                            name: conversation.other_user_name || '',
                            profile: conversation.other_user_profile || null
                        };

                        // Parse first and last name from full name
                        const nameParts = otherUser.name.split(' ');
                        otherUser.first_name = nameParts[0] || '';
                        otherUser.last_name = nameParts.slice(1).join(' ') || '';
                    } else if (conversation.user1_id) {
                        // Old API format
                        const currentUserId = parseInt(document.body.dataset.userId);
                        const isUser1 = conversation.user1_id === currentUserId;
                        const userData = isUser1 ? {
                            id: conversation.user2_id,
                            first_name: conversation.user2_first_name,
                            last_name: conversation.user2_last_name,
                            profile: conversation.user2_profile
                        } : {
                            id: conversation.user1_id,
                            first_name: conversation.user1_first_name,
                            last_name: conversation.user1_last_name,
                            profile: conversation.user1_profile
                        };

                        otherUser = userData;
                        otherUser.name = `${otherUser.first_name} ${otherUser.last_name}`.trim();
                    }

                    // Skip if we couldn't determine the other user
                    if (!otherUser.id) {
                        console.error('Could not determine other user for conversation', conversation);
                        return;
                    }

                    // Format time (either from last_message_time or updated_at)
                    const timeToFormat = conversation.last_message_time || conversation.updated_at;
                    const formattedTime = formatTime(timeToFormat);

                    const conversationEl = document.createElement('div');
                    conversationEl.className = `conversation-item p-3 border-b border-gray-200 hover:bg-gray-50 cursor-pointer transition border-l-4 border-transparent ${currentConversationId === conversation.conversation_id ? 'bg-blue-50 border-blue-500' : ''}`;
                    conversationEl.dataset.conversationId = conversation.conversation_id;
                    conversationEl.dataset.userId = otherUser.id;
                    conversationEl.dataset.userName = otherUser.name;
                    if (otherUser.profile) {
                        conversationEl.dataset.userProfile = otherUser.profile;
                    }

                    let avatarContent;
                    if (otherUser.profile) {
                        avatarContent = `<img src="${otherUser.profile}" alt="" class="w-full h-full rounded-full object-cover">`;
                    } else {
                        // Handle cases where first_name or last_name might be undefined
                        const firstName = otherUser.first_name || '';
                        const lastName = otherUser.last_name || '';
                        const initials = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase() || 'U';
                        avatarContent = `<div class="w-full h-full rounded-full bg-blue-500 flex items-center justify-center text-white font-semibold">${initials}</div>`;
                    }

                    conversationEl.innerHTML = `
                      <div class="flex items-center">
                          <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                              ${avatarContent}
                          </div>
                          <div class="flex-1">
                              <div class="flex justify-between items-center">
                                  <h3 class="font-semibold text-gray-900">${otherUser.name}</h3>
                                  <span class="text-xs text-gray-500">${formattedTime}</span>
                              </div>
                              <p class="text-sm text-gray-600 truncate" id="last-message-${conversation.conversation_id}">${conversation.last_message || 'No messages yet'}</p>
                          </div>
                          ${conversation.unread_count > 0 ?
                            `<div class="ml-2 bg-blue-500 text-white text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center">${conversation.unread_count}</div>` : ''}
                      </div>
                    `;

                    conversationEl.addEventListener('click', () => {
                        selectConversation(conversation.conversation_id, otherUser);
                    });

                    conversationList.appendChild(conversationEl);
                });
            })
            .catch(error => {
                console.error('Error:', error);
                conversationList.innerHTML = '<div class="p-4 text-center text-red-500">Error loading conversations</div>';
            });
    }

    // Select a conversation
    function selectConversation(conversationId, otherUser) {
        console.log('Selecting conversation:', conversationId);
        console.log('Other user:', otherUser);

        // Store current conversation ID and other user
        currentConversationId = conversationId;
        currentOtherUser = otherUser;

        // Update UI to show selected conversation
        const conversationItems = document.querySelectorAll('.conversation-item');
        conversationItems.forEach(item => {
            item.classList.remove('active', 'bg-blue-50');
        });

        const selectedItem = document.querySelector(`[data-conversation-id="${conversationId}"]`);
        if (selectedItem) {
            selectedItem.classList.add('active', 'bg-blue-50');

            // Mark as read
            const unreadBadge = selectedItem.querySelector('.unread-badge');
            if (unreadBadge) {
                unreadBadge.classList.add('hidden');
            }
        }

        // Show conversation view and hide no conversation message
        const noConversationSelected = document.getElementById('no-conversation-selected');
        const conversationView = document.getElementById('conversation-view');

        if (noConversationSelected) {
            noConversationSelected.classList.add('hidden');
        }

        if (conversationView) {
            conversationView.classList.remove('hidden');
            conversationView.classList.add('flex');
        }

        // Update conversation header
        const conversationName = document.getElementById('conversation-name');
        const conversationStatus = document.getElementById('conversation-status');

        if (conversationName) {
            conversationName.textContent = otherUser.name || 'Unknown User';
        }

        if (conversationStatus) {
            conversationStatus.textContent = 'Online'; // Default status
        }

        // Update avatar
        const avatarImg = document.getElementById('conversation-avatar-img');
        const avatarPlaceholder = document.getElementById('conversation-avatar-placeholder');

        if (otherUser.profile) {
            avatarImg.src = otherUser.profile;
            avatarImg.classList.remove('hidden');
            avatarPlaceholder.classList.add('hidden');
        } else {
            avatarImg.classList.add('hidden');
            avatarPlaceholder.classList.remove('hidden');
            avatarPlaceholder.textContent = getInitials(otherUser.name);

            // Set background color based on user ID
            const colors = ['#4299e1', '#48bb78', '#ed8936', '#9f7aea', '#ed64a6', '#667eea'];
            const colorIndex = (parseInt(otherUser.id) || 0) % colors.length;
            avatarPlaceholder.style.backgroundColor = colors[colorIndex];
        }

        // Set up call buttons with recipient ID
        setupCallButtons(otherUser.id, otherUser.name);

        // Load messages for this conversation
        loadMessages(conversationId);

        // Mark conversation as read
        markConversationAsRead(conversationId);

        // Subscribe to this conversation channel
        subscribeToConversation(conversationId);

        // On mobile, hide conversation list
        const leftSidebar = document.getElementById('left-sidebar');
        if (window.innerWidth < 768 && leftSidebar) {
            leftSidebar.classList.add('hidden-mobile');
        }

        // Dispatch custom event with recipient ID for call handler
        document.dispatchEvent(new CustomEvent('recipientSelected', {
            detail: {
                recipientId: otherUser.id,
                recipientName: otherUser.name
            }
        }));
    }

    // Setup call buttons
    function setupCallButtons(recipientId, recipientName = '') {
        const videoCallBtn = document.getElementById('video-call-btn');
        if (videoCallBtn) {
            // Remove any existing event listeners by cloning the button
            const newVideoCallBtn = videoCallBtn.cloneNode(true);
            videoCallBtn.parentNode.replaceChild(newVideoCallBtn, videoCallBtn);

            // Get the fresh reference and attach event listener
            document.getElementById('video-call-btn').addEventListener('click', () => {
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
                    const callPageUrl = '/bagoscout/pages/auth-user/employer/call.php';
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

    // Load messages for a conversation
    function loadMessages(conversationId) {
        // Show loading indicator
        messagesList.innerHTML = '<div class="text-center py-4 text-gray-500">Loading messages...</div>';

        fetch(`/bagoscout/api/messages.php?action=get_messages&conversation_id=${conversationId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Server responded with status ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    console.error('Error loading messages:', data.message || 'Unknown error');
                    messagesList.innerHTML = '<div class="text-center py-4 text-red-500">Error loading messages</div>';
                    return;
                }

                messagesList.innerHTML = '';
                if (!data.messages || data.messages.length === 0) {
                    messagesList.innerHTML = '<div class="text-center py-4 text-gray-500">No messages yet. Send a message to start the conversation!</div>';
                    return;
                }

                // Fetch both users' details to ensure we have names and profiles
                const senderIds = [...new Set(data.messages.map(m => m.sender_id))];

                // Either use cached user info if we have it or add basic info from currentOtherUser
                const userDetails = {};

                // Add current user info
                userDetails[document.body.dataset.userId] = {
                    first_name: document.body.dataset.firstName || '',
                    last_name: document.body.dataset.lastName || '',
                    profile: null // We might not have this
                };

                // Add other user info if available
                if (currentOtherUser && currentOtherUser.id) {
                    userDetails[currentOtherUser.id] = {
                        first_name: currentOtherUser.first_name || '',
                        last_name: currentOtherUser.last_name || '',
                        profile: currentOtherUser.profile || null
                    };
                }

                // Process and display messages
                data.messages.forEach(message => {
                    // Enhance message with sender details if missing
                    if (userDetails[message.sender_id]) {
                        if (!message.first_name && !message.last_name) {
                            message.first_name = userDetails[message.sender_id].first_name;
                            message.last_name = userDetails[message.sender_id].last_name;
                        }
                        if (!message.sender_name) {
                            message.sender_name = `${userDetails[message.sender_id].first_name} ${userDetails[message.sender_id].last_name}`.trim();
                        }
                        if (!message.profile && !message.sender_profile) {
                            message.profile = userDetails[message.sender_id].profile;
                        }
                    }

                    // Add message to UI
                    addMessageToUI(message);
                });

                // Scroll to bottom
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            })
            .catch(error => {
                console.error('Error loading messages:', error);
                messagesList.innerHTML = '<div class="text-center py-4 text-red-500">Error loading messages. Please try again.</div>';

                // Add retry button
                const retryBtn = document.createElement('button');
                retryBtn.className = 'mt-2 px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none';
                retryBtn.textContent = 'Retry';
                retryBtn.addEventListener('click', () => loadMessages(conversationId));

                const container = document.createElement('div');
                container.className = 'text-center';
                container.appendChild(retryBtn);
                messagesList.appendChild(container);
            });
    }

    // Format time
    function formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();

        // If today, show time only
        if (date.toDateString() === now.toDateString()) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        // If this year, show month and day
        if (date.getFullYear() === now.getFullYear()) {
            return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
        }

        // Otherwise show day, month and year
        return date.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // Format message time
    function formatMessageTime(date) {
        const hours = date.getHours();
        const minutes = date.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        const formattedHours = hours % 12 || 12;
        const formattedMinutes = minutes < 10 ? '0' + minutes : minutes;
        return `${formattedHours}:${formattedMinutes} ${ampm}`;
    }

    // Add a message to the UI
    function addMessageToUI(message, isFromCurrentUser) {
        // console.log('Adding message to UI:', message);

        // Check if message already exists
        if (document.querySelector(`[data-message-id="${message.id}"]`)) {
            console.log('Message already exists in UI, skipping');
            return;
        }

        // Determine if the message is from the current user
        const currentUserId = document.body.dataset.userId;
        isFromCurrentUser = message.sender_id == currentUserId;

        const messageEl = document.createElement('div');
        messageEl.dataset.messageId = message.id;
        messageEl.dataset.senderId = message.sender_id;
        messageEl.dataset.timestamp = message.created_at;

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
                    const otherUserName = currentOtherUser ? currentOtherUser.name : 'User';

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
                const readStatus = message.is_read ? 'double-check' : 'check';
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
                            ${formatTime(message.created_at ? new Date(message.created_at) : new Date())}
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
                            ${formatTime(message.created_at ? new Date(message.created_at) : new Date())}
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
                        .then(response => response.json())
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
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            console.log('Message deleted successfully');

                            // Remove message from UI
                            const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
                            if (messageEl) {
                                messageEl.remove();
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

    // Initialize message form
    if (messageForm) {
        messageForm.addEventListener('submit', function (e) {
            e.preventDefault();
            sendMessage();
        });
    } else {
        console.error('Message form not found');
    }

    // Handle enter key press
    messageInput.addEventListener('keydown', function (e) {
        // Send message on Enter key (without shift)
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Send message function
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
        formData.append('receiver_id', currentOtherUser.id);
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

        // Add message actions for the user's own messages
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

        // Add event listeners for message actions
        const editBtn = messageEl.querySelector('.edit-message-btn');
        const deleteBtn = messageEl.querySelector('.delete-message-btn');

        if (editBtn) {
            editBtn.addEventListener('click', () => {
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

        // Clear input
        messageInput.value = '';
        messageInput.style.height = 'auto';
        fileUpload.value = '';
        fileName.textContent = '';
        if (filePreviewContainer) {
            filePreviewContainer.classList.add('hidden');
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

                        // Update event listeners with the new message ID
                        const editBtn = messageEl.querySelector('.edit-message-btn');
                        const deleteBtn = messageEl.querySelector('.delete-message-btn');

                        if (editBtn) {
                            editBtn.addEventListener('click', () => {
                                editMessage(messageId, messageContent);
                            });
                        }

                        if (deleteBtn) {
                            deleteBtn.addEventListener('click', () => {
                                deleteMessage(messageId);
                            });
                        }
                    }

                    // Update sending indicator to sent
                    const statusEl = messageEl.querySelector('.text-right');
                    if (statusEl) {
                        const indicator = statusEl.querySelector('svg');
                        if (indicator) {
                            indicator.outerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6L9 17l-5-5"></path>
                            </svg>
                        `;
                        }
                    }
                }

                // Send real-time notification via Ably
                if (ablyInitialized && ablyChannel) {
                    // Handle both response formats (message_id or message.id)
                    const messageId = data.message_id || (data.message && data.message.id);
                    const filePath = data.file_path || (data.message && data.message.file_path) || null;
                    const fileName = data.file_name || (data.message && data.message.file_name) || null;

                    const messageData = {
                        id: messageId,
                        sender_id: String(document.body.dataset.userId), // Ensure sender_id is a string
                        receiver_id: String(currentOtherUser.id), // Ensure receiver_id is a string
                        message: messageContent,
                        content: messageContent, // Add both for backward compatibility
                        file_path: filePath,
                        file_name: fileName,
                        created_at: new Date().toISOString()
                    };

                    try {
                        console.log("%c PUBLISHING MESSAGE TO ABLY (EMPLOYER) ", "background: #e91e63; color: white; font-size: 12px; font-weight: bold;");
                        console.log("Channel name:", ablyChannel.name);
                        console.log("Event name: new-message");
                        console.log("Message data:", messageData);

                        ablyChannel.publish('new-message', messageData);
                        console.log('Message published to Ably successfully');
                    } catch (err) {
                        console.error('Error publishing message to Ably:', err);
                        // Message was still sent via API, so no need to show error to user
                    }
                }

                // Re-enable send button
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z" />
                    </svg>
                `;
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
                        const indicator = statusEl.querySelector('svg');
                        if (indicator) {
                            indicator.outerHTML = `
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
                        retryButton.onclick = function (e) {
                            e.preventDefault();
                            // Remove this message and try again
                            messageEl.remove();

                            // Re-add message to input
                            messageInput.value = messageContent;

                            // Re-enable send button
                            if (sendBtn) {
                                sendBtn.disabled = false;
                                sendBtn.innerHTML = `
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z" />
                                </svg>
                            `;
                            }
                        };
                        messageDiv.appendChild(retryButton);
                    }
                }

                // Re-enable send button
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

    // Search for users
    let searchTimeout;
    if (userSearchInput) {
        userSearchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);

            const query = e.target.value.trim();

            if (query.length < 2) {
                userSearchResults.innerHTML = '<p class="text-gray-500 text-center py-4">Type to search for users</p>';
                return;
            }

            userSearchResults.innerHTML = '<p class="text-gray-500 text-center py-4">Searching...</p>';

            searchTimeout = setTimeout(() => {
                fetch(`/bagoscout/api/messages.php?action=search_users&query=${query}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error('Error searching users:', data.error);
                            userSearchResults.innerHTML = '<p class="text-red-500 text-center py-4">Error searching users</p>';
                            return;
                        }

                        if (data.users.length === 0) {
                            userSearchResults.innerHTML = '<p class="text-gray-500 text-center py-4">No users found</p>';
                            return;
                        }

                        userSearchResults.innerHTML = '';
                        data.users.forEach(user => {
                            const userEl = document.createElement('div');
                            userEl.className = 'p-2 hover:bg-gray-100 cursor-pointer flex items-center';

                            let avatarContent;
                            if (user.profile) {
                                avatarContent = `<img src="${user.profile}" alt="" class="w-full h-full rounded-full object-cover">`;
                            } else {
                                const initials = `${user.first_name.charAt(0)}${user.last_name.charAt(0)}`;
                                avatarContent = `<div class="w-full h-full rounded-full bg-blue-500 flex items-center justify-center text-white font-semibold">${initials}</div>`;
                            }

                            userEl.innerHTML = `
                          <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                              ${avatarContent}
                          </div>
                          <div>
                              <div class="font-medium">${user.first_name} ${user.last_name}</div>
                              <div class="text-sm text-gray-500">${user.role}</div>
                          </div>
                        `;

                            userEl.addEventListener('click', () => {
                                startConversation(user);
                            });

                            userSearchResults.appendChild(userEl);
                        });
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        userSearchResults.innerHTML = '<p class="text-red-500 text-center py-4">Error searching users</p>';
                    });
            }, 500);
        });
    } else {
        console.warn('User search input not found');
    }

    // Start a new conversation
    function startConversation(otherUser) {
        fetch('/bagoscout/api/messages.php?action=create_conversation', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=create_conversation&user_id=${otherUser.id}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error('Error creating conversation:', data.error);
                    alert('Error creating conversation');
                    return;
                }

                // Close modal
                newConversationModal.classList.add('hidden');
                userSearchInput.value = '';
                userSearchResults.innerHTML = '<p class="text-gray-500 text-center py-4">Type to search for users</p>';

                // Reload conversations and select the new one
                loadConversations();

                // Select the conversation
                setTimeout(() => {
                    selectConversation(data.conversation_id, otherUser);
                }, 500);
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error creating conversation');
            });
    }

    // Send typing indicator
    function sendTypingIndicator() {
        if (!ablyChannel || !ablyInitialized || !currentConversationId || !currentOtherUser) {
            return;
        }

        try {
            ablyChannel.publish('typing', {
                user_id: document.body.dataset.userId,
                conversation_id: currentConversationId,
                timestamp: new Date().toISOString()
            });
        } catch (error) {
            console.error('Error sending typing indicator:', error);
        }
    }

    // Add typing indicator event
    messageInput.addEventListener('input', function () {
        // Throttle typing events to avoid sending too many
        if (window.typingTimeout) {
            clearTimeout(window.typingTimeout);
        }

        window.typingTimeout = setTimeout(sendTypingIndicator, 500);
    });

    // Set online/offline status
    window.addEventListener('beforeunload', () => {
        if (ablyChannel && ablyInitialized) {
            ablyChannel.publish('status', {
                user_id: document.body.dataset.userId,
                status: 'offline'
            });
        }
    });

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

                // Remove unread badge if present
                const conversation = document.querySelector(`[data-conversation-id="${conversationId}"]`);
                if (conversation) {
                    const badge = conversation.querySelector('.unread-badge');
                    if (badge) badge.remove();
                }
            })
            .catch(error => {
                console.error('Error marking conversation as read:', error);
            });

        // Publish read status to Ably
        console.log('Publishing read status to Ably');

        if (ablyChannel && ablyClient && ablyClient.connection.state === 'connected') {
            try {
                ablyChannel.publish('read-status', {
                    user_id: document.body.dataset.userId,
                    conversation_id: conversationId,
                    timestamp: new Date().toISOString()
                });
                console.log('Published read status successfully');
            } catch (error) {
                console.error('Error publishing read status:', error);
            }
        }
    }

    // Helper function to get initials from a name
    function getInitials(name) {
        if (!name) return 'U';

        const parts = name.split(' ');
        if (parts.length === 1) {
            return parts[0].charAt(0).toUpperCase();
        } else {
            return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        }
    }

    // Add event listener for close conversation button
    const closeConversationBtn = document.getElementById('close-conversation-btn');
    if (closeConversationBtn) {
        closeConversationBtn.addEventListener('click', () => {
            // Hide left sidebar 
            if (leftSidebar) {
                leftSidebar.classList.add('hidden');
                mobileMenuToggle.forEach(toggle => {
                    toggle.classList.remove('hidden');
                });
            }
        });
    }

    // Initialize
    initAbly();
    loadConversations();

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