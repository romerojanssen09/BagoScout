/**
 * Status Change Listener
 * Handles realtime user status changes notifications
 */

document.addEventListener('DOMContentLoaded', function() {
    // Check if user is logged in
    const userId = document.body.getAttribute('data-user-id');
    const userRole = document.body.getAttribute('data-user-role');
    
    if (userId && userRole) {
        initStatusChangeListener(userId, userRole);
    }
});

/**
 * Initialize status change listener
 * @param {string} userId - User ID
 * @param {string} userRole - User role (jobseeker or employer)
 */
function initStatusChangeListener(userId, userRole) {
    console.log('Initializing status change listener for user:', userId, 'role:', userRole);
    
    fetch('/bagoscout/api/ably-auth.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.key) {
                console.log('Ably auth successful, connecting with key');
                
                const ably = new Ably.Realtime({
                    key: data.key,
                    clientId: data.clientId
                });
                
                ably.connection.on('connected', () => {
                    console.log('Connected to Ably for status changes');
                    
                    // Subscribe to private channel for status changes
                    const channelName = `private:user-${userId}`;
                    console.log('Subscribing to channel:', channelName);
                    
                    const privateChannel = ably.channels.get(channelName);
                    
                    privateChannel.subscribe('status-change', (message) => {
                        console.log('Status change received:', message.data);
                        
                        // Handle different status change actions
                        handleStatusChange(message.data, userRole);
                    });
                });
                
                ably.connection.on('failed', (error) => {
                    console.error('Failed to connect to Ably:', error);
                });
            } else {
                console.error('No Ably key found in response:', data);
            }
        })
        .catch(error => console.error('Error initializing Ably:', error));
}

/**
 * Handle a status change notification
 * @param {object} data - Status change data
 * @param {string} userRole - User role (jobseeker or employer)
 */
function handleStatusChange(data, userRole) {
    console.log('Status change received:', data);
    
    // Create notification in the UI
    createNotificationAlert(data);
    
    // Different handling based on action
    switch(data.action) {
        case 'approve':
            showStatusChangeSuccess('Your account has been approved!', 
                'Your account has been approved by an administrator. You now have full access to all features.');
            
            // Reload immediately to reflect changes
            window.location.reload();
            break;
            
        case 'reject':
        case 'suspend':
            showStatusChangeWarning(`Your account has been ${data.action === 'reject' ? 'rejected' : 'suspended'}`, 
                data.message, data.reason);
            
            // Redirect to settings page immediately
            window.location.href = `/bagoscout/pages/auth-user/${userRole}/settings.php?status=${data.action}`;
            break;
            
        case 'delete':
            showStatusChangeError('Your account has been deleted', 
                'Your account has been deleted by an administrator. You will be logged out.');
            
            // Redirect to logout page immediately
            window.location.href = '/bagoscout/pages/logout.php';
            break;
    }
}

/**
 * Create a notification alert for status change
 * @param {object} data - Status change data
 */
function createNotificationAlert(data) {
    // Create a notification alert that will be shown at the top of the page
    const alertDiv = document.createElement('div');
    alertDiv.className = 'fixed top-0 left-0 right-0 z-50 p-4 bg-yellow-100 border-b border-yellow-200 text-yellow-800';
    
    // Add different classes based on action
    if (data.action === 'approve') {
        alertDiv.className = 'fixed top-0 left-0 right-0 z-50 p-4 bg-green-100 border-b border-green-200 text-green-800';
    } else if (data.action === 'reject' || data.action === 'suspend') {
        alertDiv.className = 'fixed top-0 left-0 right-0 z-50 p-4 bg-yellow-100 border-b border-yellow-200 text-yellow-800';
    } else if (data.action === 'delete') {
        alertDiv.className = 'fixed top-0 left-0 right-0 z-50 p-4 bg-red-100 border-b border-red-200 text-red-800';
    }
    
    alertDiv.innerHTML = `
        <div class="container mx-auto flex items-center justify-between">
            <div class="flex items-center">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <span class="font-medium">${data.message}</span>
            </div>
            <button class="text-gray-500 hover:text-gray-700 focus:outline-none" onclick="this.parentNode.parentNode.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    // Add to the page
    document.body.prepend(alertDiv);
    
    // Auto-remove after 10 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 10000);
}

/**
 * Show a success SweetAlert for status change
 * @param {string} title - Alert title
 * @param {string} message - Alert message
 */
function showStatusChangeSuccess(title, message) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: title,
            text: message,
            icon: 'success',
            confirmButtonText: 'OK'
        });
    }
}

/**
 * Show a warning SweetAlert for status change
 * @param {string} title - Alert title
 * @param {string} message - Alert message
 * @param {string} reason - Reason for the status change
 */
function showStatusChangeWarning(title, message, reason) {
    if (typeof Swal !== 'undefined') {
        let html = `${message}`;
        
        if (reason) {
            html += `<div class="mt-3 p-3 bg-gray-100 rounded text-gray-800">
                <strong>Reason:</strong> ${reason}
            </div>`;
        }
        
        Swal.fire({
            title: title,
            html: html,
            icon: 'warning',
            confirmButtonText: 'OK'
        });
    }
}

/**
 * Show an error SweetAlert for status change
 * @param {string} title - Alert title
 * @param {string} message - Alert message
 */
function showStatusChangeError(title, message) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: title,
            text: message,
            icon: 'error',
            confirmButtonText: 'OK'
        });
    }
} 