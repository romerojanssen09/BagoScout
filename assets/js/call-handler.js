/**
 * Call Handler for BagoScout
 * Handles video call button clicks, checks user status, and manages call notifications
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize variables
    let ably = null;
    let callChannel = null;
    let currentUserId = document.body.dataset.userId;
    let currentUserName = document.body.dataset.userName;
    let currentUserRole = document.body.dataset.userRole;
    
    console.log('Call handler initialized with user data:', {
        userId: currentUserId,
        userName: currentUserName,
        userRole: currentUserRole
    });
    
    let recipientId = null;
    let recipientName = null;
    
    // Initialize Ably connection
    initializeAbly();
    
    // Find and attach event listener to video call button
    const setupVideoCallButton = () => {
        const videoCallBtn = document.getElementById('video-call-btn');
        
        // Check if we're on a call page - if so, don't look for the button
        if (window.location.pathname.includes('/call.php')) {
            console.log('On call page, skipping video call button setup');
            return;
        }
        
        if (videoCallBtn) {
            // Remove any existing event listeners by cloning the button
            const newVideoCallBtn = videoCallBtn.cloneNode(true);
            videoCallBtn.parentNode.replaceChild(newVideoCallBtn, videoCallBtn);
            
            // Get the fresh reference and attach event listener
            document.getElementById('video-call-btn').addEventListener('click', () => {
                // const conversationId = this.dataset.conversationId;
                handleVideoCallClick();
            });
            console.log('Video call button event listener attached');
        } else {
            console.warn('Video call button not found');
            
            // Try again after a short delay in case the button is added dynamically
            // But only retry a limited number of times to avoid infinite retries
            if (!window.videoCallButtonRetries) {
                window.videoCallButtonRetries = 0;
            }
            
            if (window.videoCallButtonRetries < 5) {
                window.videoCallButtonRetries++;
                setTimeout(setupVideoCallButton, 1000);
            } else {
                console.log('Giving up on finding video call button after multiple attempts');
            }
        }
    };
    
    // Make handleVideoCallClick globally accessible
    window.handleVideoCallClick = (recipientIdParam, recipientNameParam) => {
        // Use parameters if provided, otherwise use stored values
        const targetRecipientId = recipientIdParam || recipientId;
        const targetRecipientName = recipientNameParam || recipientName;
        
        console.log('Video call button clicked for recipient:', targetRecipientId, targetRecipientName);
        console.log('User role:', currentUserRole);
        console.log('User ID:', currentUserId);
        console.log('User name:', currentUserName);
        
        if (!targetRecipientId) {
            console.error('No recipient ID found');
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Cannot start a call without a recipient'
            });
            return;
        }
        
        try {
            // Check if recipient is already in a call
            fetch(`/bagoscout/api/call.php?action=check_status&user_id=${targetRecipientId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.in_call) {
                        // Recipient is already in a call
                        Swal.fire({
                            icon: 'info',
                            title: 'User Unavailable',
                            text: 'This user is currently in another call. Please try again later.'
                        });
                        return;
                    }
                    
                    // Generate call ID
                    const callId = generateCallId(currentUserId, targetRecipientId);
                    
                    // Determine the correct call page URL based on user role
                    let callPageUrl;
                    if (currentUserRole === 'seeker') {
                        callPageUrl = '/bagoscout/pages/auth-user/seeker/call.php';
                    } else {
                        callPageUrl = '/bagoscout/pages/auth-user/employer/call.php';
                    }
                    
                    // Open call in new tab
                    const callUrl = `${callPageUrl}?type=video&user=${targetRecipientId}&initiator=true&id=${callId}`;
                    console.log('Opening call URL:', callUrl);
                    const newWindow = window.open(callUrl, '_blank');
                    
                    // Check if popup was blocked
                    if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                        console.error('Popup was blocked');
                        Swal.fire({
                            icon: 'warning',
                            title: 'Popup Blocked',
                            text: 'Please allow popups for this site to make calls.',
                            footer: `<a href="${callUrl}" target="_blank">Click here to open the call manually</a>`
                        });
                        return;
                    }
                    
                    // Send call notification to recipient
                    sendCallNotification(targetRecipientId, targetRecipientName, callId);
                    
                    // Create call record in database
                    createCallRecord(callId, targetRecipientId);
                })
                .catch(error => {
                    console.error('Error checking user status:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Call Failed',
                        text: 'Could not check user status. Please try again.'
                    });
                });
        } catch (error) {
            console.error('Error initiating call:', error);
            Swal.fire({
                icon: 'error',
                title: 'Call Failed',
                text: 'Could not initiate call. Please try again.'
            });
        }
    };
    
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
                    setupCallNotificationChannel();
                });
                
                ably.connection.on('failed', (err) => {
                    console.error('Ably connection failed:', err);
                });
            })
            .catch(error => {
                console.error('Failed to initialize Ably:', error);
            });
    }
    
    // Setup call notification channel
    function setupCallNotificationChannel() {
        if (!ably) return;
        
        // Create a channel for sending/receiving call signals
        callChannel = ably.channels.get('notifications-' + currentUserId);
    }
    
    // Send call notification to recipient
    function sendCallNotification(recipientId, recipientName, callId) {
        if (!ably || !ably.connection.state === 'connected') {
            console.error('Ably not connected, cannot send notification');
            return;
        }
        
        const notificationChannel = ably.channels.get('notifications-' + recipientId);
        
        notificationChannel.publish('call-notification', {
            type: 'incoming-call',
            callerId: currentUserId,
            callerName: currentUserName,
            callId: callId,
            timestamp: new Date().toISOString()
        }, (err) => {
            if (err) {
                console.error('Error sending call notification:', err);
            } else {
                console.log('Call notification sent successfully');
            }
        });
    }
    
    // Create call record in database
    function createCallRecord(callId, recipientId) {        
        fetch('/bagoscout/api/call.php?action=create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                callId: callId,
                recipientId: recipientId,
                callType: 'video',
            })
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to create call record:', data.message);
            } else {
                console.log('Call record created successfully');
            }
        })
        .catch(error => {
            console.error('Error creating call record:', error);
        });
    }
    
    // Listen for recipient selection from messaging.js
    document.addEventListener('recipientSelected', function(event) {
        recipientId = event.detail.recipientId;
        recipientName = event.detail.recipientName;
        console.log('Recipient selected:', recipientId, recipientName);
        
        // Set up the video call button after recipient is selected
        setupVideoCallButton();
    });
    
    // Initial setup for video call button
    setupVideoCallButton();
    
    // Generate a consistent call ID from two user IDs
    function generateCallId(userId1, userId2) {
        // Sort the IDs numerically to ensure consistency regardless of who initiates
        const sortedIds = [parseInt(userId1), parseInt(userId2)].sort((a, b) => a - b);
        return `call-${sortedIds[0]}-${sortedIds[1]}-${Date.now()}`;
    }
}); 