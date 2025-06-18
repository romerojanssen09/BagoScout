/**
 * Admin Realtime Notifications and Status Updates
 * Handles realtime communication for the admin panel
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Ably connection for admin
    initAblyForAdmin();
});

/**
 * Initialize Ably connection for admin panel
 */
function initAblyForAdmin() {
    fetch('/bagoscout/api/admin-ably-auth.php')
        .then(response => response.json())
        .then(data => {
            if (data.key) {
                // Use key authentication instead of token
                const ably = new Ably.Realtime({
                    key: data.key,
                    clientId: data.clientId
                });
                
                ably.connection.on('connected', () => {
                    console.log('Admin connected to Ably');
                    
                    // Subscribe to admin channel for notifications
                    const adminChannel = ably.channels.get('admin-notifications');
                    
                    adminChannel.subscribe('status-update', (message) => {
                        // Show notification for status updates
                        showAdminNotification('User Status Update', message.data.message);
                        
                        // If currently viewing the user, refresh the page
                        if (window.location.pathname.includes('view-jobseeker.php') || 
                            window.location.pathname.includes('view-employer.php')) {
                            
                            const urlParams = new URLSearchParams(window.location.search);
                            const userId = urlParams.get('id');
                            
                            if (userId && userId === message.data.user_id.toString()) {
                                // Add a success message before refreshing
                                showAdminActionSuccess(message.data.message);
                                
                                // Refresh the page after a short delay
                                setTimeout(() => {
                                    window.location.reload();
                                }, 2000);
                            }
                        }
                    });
                });
                
                ably.connection.on('failed', (error) => {
                    console.error('Failed to connect to Ably:', error);
                    showAdminActionError('Failed to connect to real-time service. Some features may not work properly.');
                });
            }
        })
        .catch(error => console.error('Error initializing Ably:', error));
}

/**
 * Show a notification in the admin panel
 * @param {string} title - Notification title
 * @param {string} message - Notification message
 */
function showAdminNotification(title, message) {
    // Use SweetAlert2 if available
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: title,
            text: message,
            icon: 'info',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 5000,
            timerProgressBar: true
        });
    } else {
        // Fallback to alert if SweetAlert2 is not available
        alert(`${title}: ${message}`);
    }
}

/**
 * Show a success message for an admin action
 * @param {string} message - Success message
 */
function showAdminActionSuccess(message) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Success',
            text: message,
            icon: 'success',
            confirmButtonText: 'OK'
        });
    }
}

/**
 * Show an error message for an admin action
 * @param {string} message - Error message
 */
function showAdminActionError(message) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Error',
            text: message,
            icon: 'error',
            confirmButtonText: 'OK'
        });
    }
}

/**
 * Publish status update from admin actions
 * @param {string} userId - User ID
 * @param {string} action - Action performed (approve, reject, suspend, delete)
 * @param {string} message - Message to send
 * @param {string} reason - Reason for action (optional)
 */
function publishUserStatusUpdate(userId, action, message, reason = '') {
    console.log('Publishing status update for user:', userId, 'action:', action);
    
    // Use a synchronous XMLHttpRequest to ensure the message is sent before page redirection
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/bagoscout/api/admin-ably-auth.php', false); // false makes the request synchronous
    xhr.send();
    
    if (xhr.status === 200) {
        const data = JSON.parse(xhr.responseText);
        console.log('Admin Ably auth successful');
        
        if (data.key) {
            // Create payload for admin notifications
            const adminPayload = JSON.stringify({
                name: 'status-update',
                data: {
                    user_id: userId,
                    action: action,
                    message: message,
                    reason: reason,
                    timestamp: new Date().toISOString()
                }
            });
            
            // Create payload for user's private channel
            const userPayload = JSON.stringify({
                name: 'status-change',
                data: {
                    action: action,
                    message: message,
                    reason: reason,
                    timestamp: new Date().toISOString()
                }
            });
            
            console.log('Sending to admin notifications channel...');
            // Send to admin notifications channel (synchronous)
            const adminXhr = new XMLHttpRequest();
            adminXhr.open('POST', 'https://rest.ably.io/channels/admin-notifications/messages', false);
            adminXhr.setRequestHeader('Authorization', 'Basic ' + btoa(data.key));
            adminXhr.setRequestHeader('Content-Type', 'application/json');
            adminXhr.send(adminPayload);
            console.log('Admin notification sent, status:', adminXhr.status);
            
            console.log('Sending to user channel:', 'private:user-' + userId);
            // Send to user's private channel (synchronous)
            const userXhr = new XMLHttpRequest();
            userXhr.open('POST', 'https://rest.ably.io/channels/private:user-' + userId + '/messages', false);
            userXhr.setRequestHeader('Authorization', 'Basic ' + btoa(data.key));
            userXhr.setRequestHeader('Content-Type', 'application/json');
            userXhr.send(userPayload);
            console.log('User notification sent, status:', userXhr.status);
            
            console.log('Status update published synchronously');
            return true;
        }
    } else {
        console.error('Failed to get Ably auth, status:', xhr.status);
    }
    
    console.error('Failed to publish status update');
    return false;
} 