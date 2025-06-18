/**
 * Call Notification Handler for BagoScout
 * Listens for incoming call notifications and displays them to the user
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize variables
    let ably = null;
    let currentUserId = document.body.dataset.userId;
    let currentUserName = document.body.dataset.userName;
    let currentUserRole = document.body.dataset.userRole;
    
    // Initialize Ably connection if user is logged in
    if (currentUserId) {
        initializeAbly();
    }
    
    // Initialize Ably
    function initializeAbly() {
        fetch('/bagoscout/api/ably-auth.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to get Ably token');
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error('Invalid response from auth server: ' + JSON.stringify(data));
                }
                
                // Use key parameter instead of token for Ably initialization
                ably = new Ably.Realtime({
                    key: data.key, // Use key instead of token
                    clientId: 'user-' + data.user_id
                });
                
                ably.connection.on('connected', () => {
                    console.log('Connected to Ably for call notifications');
                    setupNotificationChannel();
                });
                
                ably.connection.on('failed', (err) => {
                    console.error('Ably connection failed:', err);
                });
            })
            .catch(error => {
                console.error('Failed to initialize Ably:', error);
            });
    }
    
    // Setup notification channel
    function setupNotificationChannel() {
        // Subscribe to personal notification channel
        const notificationChannel = ably.channels.get(`notifications-${currentUserId}`);
        
        notificationChannel.subscribe('call-notification', (message) => {
            console.log('Received call notification:', message);
            
            try {
                const notification = typeof message.data === 'string' 
                    ? JSON.parse(message.data) 
                    : message.data;
                
                // Handle incoming call notification
                if (notification.type === 'incoming-call') {
                    showIncomingCallNotification(notification);
                }
                
                // Handle call rejected notification
                if (notification.type === 'call-rejected') {
                    handleCallRejected(notification);
                }
                
                // Handle call missed notification
                if (notification.type === 'call-missed') {
                    handleCallMissed(notification);
                }
            } catch (error) {
                console.error('Error processing call notification:', error);
            }
        });
    }

    // Show incoming call notification
    function showIncomingCallNotification(notification) {
        const { callerId, callerName, callId } = notification;
        
        // Check if we're already in a call page
        if (window.location.pathname.includes('/call.php')) {
            console.log('Already in a call, ignoring incoming call notification');
            return;
        }
        
        // Play ringtone
        playRingtone();
        
        Swal.fire({
            title: 'Incoming Video Call',
            html: `<div class="text-center">
                <div class="w-20 h-20 mx-auto mb-4 bg-blue-500 rounded-full flex items-center justify-center text-white text-2xl">
                    ${getInitials(callerName)}
                </div>
                <p class="text-lg font-medium">${callerName}</p>
                <p class="text-sm text-gray-500">is calling you...</p>
            </div>`,
            showCancelButton: true,
            confirmButtonText: 'Answer',
            cancelButtonText: 'Decline',
            confirmButtonColor: '#4CAF50',
            cancelButtonColor: '#F44336',
            allowOutsideClick: false,
            backdrop: `rgba(0,0,0,0.4)`,
            timer: 30000, // 30 seconds
            timerProgressBar: true,
            customClass: {
                popup: 'rounded-lg shadow-xl'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Answer the call
                answerCall(callerId, callId);
            } else {
                // Decline the call
                declineCall(callerId, callId);
            }
        });
    }
    
    // Answer call
    function answerCall(callerId, callId) {
        // Determine the correct call page URL based on user role
        let callPageUrl;
        if (currentUserRole === 'seeker') {
            callPageUrl = '/bagoscout/pages/auth-user/seeker/call.php';
        } else {
            callPageUrl = '/bagoscout/pages/auth-user/employer/call.php';
        }
        
        // Open call in new tab
        const callUrl = `${callPageUrl}?type=video&user=${callerId}&id=${callId}`;
        window.open(callUrl, '_blank');
        
        // Stop ringtone
        stopRingtone();
    }
    
    // Decline call
    function declineCall(callerId, callId) {
        if (!ably) return;
        
        // Send decline notification to caller
        const notificationChannel = ably.channels.get(`notifications-${callerId}`);
        
        notificationChannel.publish('call-notification', {
            type: 'call-rejected',
            callId: callId,
            recipientId: currentUserId,
            recipientName: currentUserName,
            timestamp: new Date().toISOString()
        });
        
        // Update call status in database
        fetch('/bagoscout/api/call.php?action=update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                callId: callId,
                status: 'rejected'
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('Call marked as rejected:', data);
        })
        .catch(error => {
            console.error('Error updating call status:', error);
        });
        
        // Add system message about rejected call if in messaging page
        if (window.location.pathname.includes('message.php')) {
            addSystemMessage(callerId, `Call was declined`);
        }
        
        // Stop ringtone
        stopRingtone();
    }
    
    // Handle call rejected notification
    function handleCallRejected(notification) {
        const { recipientName } = notification;
        
        // Show notification that call was rejected
        Swal.fire({
            icon: 'info',
            title: 'Call Declined',
            text: `${recipientName} declined your call`,
            timer: 3000,
            timerProgressBar: true
        });
        
        // Add system message about rejected call if in messaging page
        if (window.location.pathname.includes('message.php')) {
            addSystemMessage(notification.recipientId, `${recipientName} declined your call`);
        }
        
        // Close call window if we're in one
        if (window.location.pathname.includes('/call.php')) {
            window.close();
        }
    }
    
    // Handle call missed notification
    function handleCallMissed(notification) {
        const { recipientName } = notification;
        
        // Show notification that call was missed
        Swal.fire({
            icon: 'info',
            title: 'Call Missed',
            text: `${recipientName} didn't answer your call`,
            timer: 3000,
            timerProgressBar: true
        });
        
        // Add system message about missed call if in messaging page
        if (window.location.pathname.includes('message.php')) {
            addSystemMessage(notification.recipientId, `${recipientName} didn't answer your call`);
        }
        
        // Close call window if we're in one
        if (window.location.pathname.includes('/call.php')) {
            window.close();
        }
    }
    
    // Add system message about call status
    function addSystemMessage(otherUserId, messageText) {
        // Get conversation ID
        const conversationId = getConversationId();
        
        if (!conversationId) return;
        
        // Send system message
        fetch('/bagoscout/api/messages.php?action=system_message', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                conversation_id: conversationId,
                message: messageText,
                sender_id: currentUserId,
                receiver_id: otherUserId
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('System message added:', data);
            
            // Refresh messages if needed
            if (typeof loadMessages === 'function') {
                loadMessages(conversationId);
            }
        })
        .catch(error => {
            console.error('Error adding system message:', error);
        });
    }
    
    // Helper function to get conversation ID from URL or DOM
    function getConversationId() {
        // Try to get from URL first
        const urlParams = new URLSearchParams(window.location.search);
        const conversationId = urlParams.get('id');
        
        if (conversationId) return conversationId;
        
        // Try to get from active conversation in DOM
        const activeConversation = document.querySelector('.conversation-item.active');
        if (activeConversation) {
            return activeConversation.dataset.conversationId;
        }
        
        return null;
    }
    
    // Get initials from name
    function getInitials(name) {
        if (!name) return '?';
        
        const parts = name.split(' ');
        if (parts.length >= 2) {
            return (parts[0][0] + parts[1][0]).toUpperCase();
        }
        return parts[0][0].toUpperCase();
    }
    
    // Play ringtone
    let ringtone = null;
    function playRingtone() {
        stopRingtone(); // Stop any existing ringtone
        
        ringtone = new Audio('/bagoscout/assets/sounds/ringtone.mp3');
        ringtone.loop = true;
        ringtone.play().catch(e => console.log('Could not play ringtone:', e));
    }
    
    // Stop ringtone
    function stopRingtone() {
        if (ringtone) {
            ringtone.pause();
            ringtone.currentTime = 0;
            ringtone = null;
        }
    }
}); 