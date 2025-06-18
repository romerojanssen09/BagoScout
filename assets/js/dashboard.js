/**
 * Dashboard JavaScript file for BagoScout
 * Handles WebSocket connection and Alby integration
 */

// WebSocket connection
let socket;
// Alby token
let albyToken = '';

// Wait for DOM to be loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize WebSocket connection
    initWebSocket();
    
    // Initialize Alby integration
    initAlby();
});

/**
 * Initialize WebSocket connection
 */
function initWebSocket() {
    const wsStatus = document.getElementById('websocket-status');
    
    // Check if WebSocket is supported
    if (!window.WebSocket) {
        if (wsStatus) {
            wsStatus.textContent = 'WebSocket not supported by your browser';
            wsStatus.classList.add('error');
        }
        return;
    }
    
    // Create WebSocket connection
    socket = new WebSocket('ws://localhost:8080');
    
    // Connection opened
    socket.addEventListener('open', function(event) {
        if (wsStatus) {
            wsStatus.textContent = 'Connected to WebSocket server';
            wsStatus.classList.add('success');
        }
        
        // Register user with WebSocket server
        const userId = getUserId();
        if (userId) {
            socket.send(JSON.stringify({
                type: 'register',
                userId: userId
            }));
        }
        
        // Start ping interval to keep connection alive
        startPingInterval();
    });
    
    // Listen for messages
    socket.addEventListener('message', function(event) {
        console.log('Message from server:', event.data);
        
        try {
            const data = JSON.parse(event.data);
            handleWebSocketMessage(data);
        } catch (error) {
            console.error('Error parsing WebSocket message:', error);
        }
    });
    
    // Connection closed
    socket.addEventListener('close', function(event) {
        if (wsStatus) {
            wsStatus.textContent = 'Disconnected from WebSocket server';
            wsStatus.classList.remove('success');
            wsStatus.classList.add('error');
        }
        
        // Try to reconnect after 5 seconds
        setTimeout(initWebSocket, 5000);
    });
    
    // Connection error
    socket.addEventListener('error', function(event) {
        if (wsStatus) {
            wsStatus.textContent = 'WebSocket connection error';
            wsStatus.classList.add('error');
        }
        console.error('WebSocket error:', event);
    });
}

/**
 * Handle WebSocket messages
 * 
 * @param {Object} data Message data
 */
function handleWebSocketMessage(data) {
    if (!data || !data.type) {
        return;
    }
    
    switch (data.type) {
        case 'auth':
            handleAuthResponse(data);
            break;
            
        case 'register':
            console.log('Registration status:', data.status);
            break;
            
        case 'pong':
            // Received pong from server
            break;
            
        default:
            console.log('Unknown message type:', data.type);
    }
}

/**
 * Handle authentication response
 * 
 * @param {Object} data Authentication response data
 */
function handleAuthResponse(data) {
    const albyStatus = document.getElementById('alby-status');
    
    if (data.status === 'success') {
        if (albyStatus) {
            albyStatus.textContent = 'Connected to Alby as ' + data.user.name;
            albyStatus.classList.add('success');
        }
        
        // Hide connect button
        const connectBtn = document.getElementById('connect-alby');
        if (connectBtn) {
            connectBtn.style.display = 'none';
        }
        
        // Save Alby token to localStorage
        localStorage.setItem('alby_token', albyToken);
    } else {
        if (albyStatus) {
            albyStatus.textContent = 'Failed to connect to Alby: ' + data.message;
            albyStatus.classList.add('error');
        }
    }
}

/**
 * Initialize Alby integration
 */
function initAlby() {
    const connectBtn = document.getElementById('connect-alby');
    const albyStatus = document.getElementById('alby-status');
    
    if (connectBtn) {
        // Check if we already have a token
        albyToken = localStorage.getItem('alby_token');
        
        if (albyToken) {
            // We have a token, try to authenticate
            if (socket && socket.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({
                    type: 'auth',
                    token: albyToken
                }));
                
                if (albyStatus) {
                    albyStatus.textContent = 'Authenticating with Alby...';
                }
            }
        } else {
            // No token, show connect button
            if (albyStatus) {
                albyStatus.textContent = 'Not connected to Alby';
            }
        }
        
        // Add click event to connect button
        connectBtn.addEventListener('click', function() {
            connectToAlby();
        });
    }
}

/**
 * Connect to Alby
 */
function connectToAlby() {
    // Alby OAuth configuration
    const clientId = 'wAsqVg.n1Cj3Q';
    const redirectUri = window.location.origin + '/alby-callback.php';
    const scope = 'account:read';
    
    // Generate random state for security
    const state = generateRandomString(32);
    localStorage.setItem('alby_state', state);
    
    // Build authorization URL
    const authUrl = `https://getalby.com/oauth?client_id=${clientId}&response_type=code&redirect_uri=${encodeURIComponent(redirectUri)}&scope=${encodeURIComponent(scope)}&state=${state}`;
    
    // Open authorization window
    window.location.href = authUrl;
}

/**
 * Start ping interval to keep WebSocket connection alive
 */
function startPingInterval() {
    // Send ping every 30 seconds
    setInterval(function() {
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify({
                type: 'ping',
                time: Date.now()
            }));
        }
    }, 30000);
}

/**
 * Get user ID from page data
 * 
 * @return {string|null} User ID or null if not found
 */
function getUserId() {
    // Try to get user ID from page data
    const userIdElement = document.querySelector('meta[name="user-id"]');
    return userIdElement ? userIdElement.getAttribute('content') : null;
}

/**
 * Generate random string
 * 
 * @param {number} length String length
 * @return {string} Random string
 */
function generateRandomString(length) {
    const charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    
    for (let i = 0; i < length; i++) {
        const randomIndex = Math.floor(Math.random() * charset.length);
        result += charset.charAt(randomIndex);
    }
    
    return result;
} 