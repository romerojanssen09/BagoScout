/**
 * WebRTC implementation for BagoScout
 * Handles peer-to-peer audio/video calls using WebRTC and Ably for signaling
 */

// Global variables
let localStream = null;
let peerConnection = null;
let ably = null;
let callChannel = null;
let config = null;
let mediaConstraints = {
    audio: true,
    video: undefined // Will be set based on call type
};
let hasRemoteStream = false;
let callStartTime = null;
let callDuration = 0;
let callTimer = null;
let otherUserName = '';
let pendingIceCandidates = []; // Store ICE candidates that arrive before remote description is set
let isNegotiating = false; // Flag to avoid negotiation collisions

// Call controls
const toggleAudio = () => {
    if (!localStream) return;
    
    const audioTrack = localStream.getAudioTracks()[0];
    if (audioTrack) {
        audioTrack.enabled = !audioTrack.enabled;
        document.querySelector('#toggle-audio i').classList.toggle('fa-microphone');
        document.querySelector('#toggle-audio i').classList.toggle('fa-microphone-slash');
        document.querySelector('#toggle-audio').classList.toggle('muted');
    }
};

const toggleVideo = () => {
    if (!localStream) return;
    
    const videoTrack = localStream.getVideoTracks()[0];
    if (videoTrack) {
        videoTrack.enabled = !videoTrack.enabled;
        document.querySelector('#toggle-video i').classList.toggle('fa-video');
        document.querySelector('#toggle-video i').classList.toggle('fa-video-slash');
        document.querySelector('#toggle-video').classList.toggle('muted');
    }
};

const endCall = () => {
    console.log('Ending call...');
    
    // Calculate call duration
    let duration = 0;
    if (callStartTime) {
        duration = Math.floor((Date.now() - callStartTime) / 1000);
        callDuration = duration;
    }
    
    console.log('Call duration:', duration, 'seconds');
    
    // Get conversation ID for redirect
    const conversationId = getConversationIdFromUrl();
    console.log('Conversation ID for redirect:', conversationId);
    
    // Send end call signal with duration
    if (callChannel) {
        sendSignal({
            type: 'call-ended',
            to: config.otherUserId,
            duration: duration
        }).catch(error => {
            console.error('Error sending call-ended signal:', error);
        });
    }
    
    // Update call status in the database
    fetch('/bagoscout/api/call.php?action=update', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            callId: config.callId,
            status: 'ended',
            duration: duration
        })
    })
    .then(response => {
        if (!response.ok) {
            console.error('Failed to update call status to ended:', response.status);
        }
        return response.json();
    })
    .catch(error => {
        console.error('Error updating call status to ended:', error);
    });
    
    // Add system message about call duration
    const formattedDuration = formatCallDuration(duration);
    const callEndMessage = `Call ended. Duration: ${formattedDuration}`;
    console.log('Adding system message:', callEndMessage);
    addSystemMessage(callEndMessage);
    
    // Stop the call timer
    if (callTimer) {
        clearInterval(callTimer);
        callTimer = null;
    }
    
    cleanup();
    
    // Clear in-call status
    if (typeof sessionStorage !== 'undefined') {
        sessionStorage.removeItem('bagoscout_in_call');
    }
    
    // Determine appropriate redirect URL based on user role or path
    const currentPath = window.location.pathname;
    let redirectURL = '/bagoscout/';
    
    // Determine the correct messages page URL based on user role
    if (currentPath.includes('/auth-user/seeker/')) {
        redirectURL = '/bagoscout/pages/auth-user/seeker/message.php';
    } else if (currentPath.includes('/auth-user/employer/')) {
        redirectURL = '/bagoscout/pages/auth-user/employer/message.php';
    }
    
    // Add conversation ID to redirect URL if available
    if (conversationId) {
        redirectURL += `?id=${conversationId}`;
    }
    
    console.log('Call ended, redirecting to:', redirectURL);
    
    // Small delay to ensure message is sent before redirecting
    setTimeout(() => {
        window.location.href = redirectURL;
    }, 500);
};

const cleanup = () => {
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }
    
    if (peerConnection) {
        peerConnection.close();
        peerConnection = null;
    }
    
    if (callChannel) {
        callChannel.unsubscribe();
        callChannel = null;
    }
    
    if (ably) {
        ably.close();
        ably = null;
    }
};

// Initialize Ably
const initializeAbly = async () => {
    try {
        const response = await fetch('/bagoscout/api/ably-auth.php');
        if (!response.ok) throw new Error('Failed to get Ably token');
        
        const data = await response.json();
        if (!data.success) {
            throw new Error('Invalid response from auth server: ' + JSON.stringify(data));
        }
        
        console.log('Ably auth response:', data);
        
        // Make sure we have a valid key
        if (!data.key) {
            throw new Error('No API key provided in the response');
        }
        
        // Create a consistent client ID format
        const clientId = 'user-' + data.user_id;
        console.log('Using client ID:', clientId);
        
        // Initialize Ably with the key
        ably = new Ably.Realtime({
            key: data.key,
            clientId: clientId,
            log: { level: 4 }, // More detailed logging
            autoConnect: true,
            closeOnUnload: true
        });
        
        // Add event listeners for all connection states
        ably.connection.on('connecting', () => {
            console.log('Connecting to Ably...');
            updateCallStatus('Connecting to signaling server...');
        });
        
        ably.connection.on('connected', () => {
            console.log('Connected to Ably successfully');
            setupCallChannel();
        });
        
        ably.connection.on('failed', (err) => {
            console.error('Ably connection failed:', err);
            updateCallStatus('Connection failed. Retrying...');
            
            // Try with a different approach if the connection fails
            console.log('Attempting to reconnect with a different approach');
            
            // Clean up the previous connection
            try {
                if (ably) {
                    ably.close();
                    ably = null;
                }
            } catch (e) {
                console.error('Error closing Ably connection:', e);
            }
            
            // Create a new connection with just the API key string
            setTimeout(() => {
                try {
                    console.log('Creating new Ably instance with direct key');
                    ably = new Ably.Realtime(data.key);
                    
                    ably.connection.on('connected', () => {
                        console.log('Connected to Ably (retry with direct key)');
                        setupCallChannel();
                    });
                    
                    ably.connection.on('failed', (retryErr) => {
                        console.error('Ably connection failed on retry:', retryErr);
                        updateCallStatus('Connection failed. Please refresh and try again.');
                    });
                } catch (retryError) {
                    console.error('Error creating new Ably instance:', retryError);
                }
            }, 1000);
        });

        // Add additional connection state handlers
        ably.connection.on('disconnected', () => {
            console.log('Disconnected from Ably, attempting to reconnect...');
            updateCallStatus('Connection lost. Reconnecting...');
            
            setTimeout(() => {
                if (ably && ably.connection.state !== 'connected') {
                    console.log('Attempting to reconnect to Ably...');
                    ably.connection.connect();
                }
            }, 1000);
        });
        
        ably.connection.on('suspended', () => {
            console.log('Ably connection suspended, will retry automatically');
            updateCallStatus('Connection suspended. Retrying...');
        });
        
        ably.connection.on('closed', () => {
            console.log('Ably connection closed');
        });
        
        return ably;
    } catch (error) {
        console.error('Failed to initialize Ably:', error);
        updateCallStatus('Connection failed. Please try again.');
        throw error;
    }
};

// Setup call signaling channel
const setupCallChannel = () => {
    // Check if Ably is available
    if (!ably) {
        console.error('Ably not initialized, cannot setup call channel');
        updateCallStatus('Connection error. Please refresh and try again.');
        return;
    }
    
    // If not connected, wait for connection
    if (ably.connection.state !== 'connected') {
        console.log('Ably not connected yet, waiting for connection...');
        updateCallStatus('Connecting to signaling server...');
        
        // Wait for connection to be established
        ably.connection.once('connected', () => {
            console.log('Ably connected, now setting up call channel');
            setupCallChannel(); // Call this function again once connected
        });
        return;
    }

    console.log('Setting up call channel for call ID:', config.callId);
    
    try {
        // Create a channel for sending/receiving call signals
        callChannel = ably.channels.get(`call-${config.callId}`);
        
        // Subscribe to call signals
        callChannel.subscribe('call-signal', (message) => {
            console.log('Received call signal:', message);
            
            try {
                const signal = typeof message.data === 'string' 
                    ? JSON.parse(message.data) 
                    : message.data;
                    
                // Make sure the message is for us
                if (signal.to !== config.userId && signal.to !== null) {
                    console.log('Signal not for us, ignoring');
                    return;
                }
                
                handleSignal(signal);
            } catch (error) {
                console.error('Error processing call signal:', error);
            }
        });
        
        // Create call duration element immediately
        const durationDiv = document.createElement('div');
        durationDiv.id = 'call-duration';
        durationDiv.className = 'call-duration';
        durationDiv.textContent = '00:00:00';
        
        // Insert into the DOM
        const controlsElement = document.querySelector('.controls');
        if (controlsElement) {
            controlsElement.parentNode.insertBefore(durationDiv, controlsElement);
        } else {
            // If controls not found, add to the call container
            const callContainer = document.querySelector('.call-container');
            if (callContainer) {
                callContainer.appendChild(durationDiv);
            }
        }
        
        // Start call timer once connected
        callStartTime = Date.now();
        callTimer = setInterval(() => {
            const duration = Math.floor((Date.now() - callStartTime) / 1000);
            callDuration = duration;
            updateCallDuration(duration);
        }, 1000);
        
        // Make sure we have media before proceeding with the call setup
        const setupCallWithMedia = async () => {
            // If we don't have local stream yet, try to initialize it
            if (!localStream) {
                try {
                    console.log('No local stream available, initializing media first...');
                    await initializeMedia();
                } catch (err) {
                    console.error('Failed to initialize media:', err);
                    updateCallStatus('Failed to access camera or microphone');
                    return;
                }
            }
            
            // Create peer connection first
            createPeerConnection();
            
            // Now proceed with call setup based on role
            if (config.isInitiator) {
                console.log('We are the initiator, sending initial signal');
                
                // Update the calling status UI
                updateCallStatus(`Calling ${otherUserName || 'user'}...`);
                
                // Don't send offer here - it will be triggered by the negotiationneeded event
                // after tracks are added to the peer connection
            } else {
                console.log('We are the recipient, waiting for offer');
                
                // Update the calling status UI
                updateCallStatus('Incoming call...');
                
                // Automatically accept the call
                acceptCall();
            }
        };
        
        // Call the setup function
        setupCallWithMedia();
    } catch (error) {
        console.error('Error setting up call channel:', error);
        updateCallStatus('Error setting up call. Please try again.');
    }
};

// Handle incoming signals
const handleSignal = async (signal) => {
    console.log('Handling signal:', signal.type);
    
    if (!peerConnection) {
        console.log('Creating peer connection for incoming signal');
        createPeerConnection();
    }
    
    switch (signal.type) {
        case 'offer':
            try {
                if (!peerConnection) {
                    console.error('No peer connection available for offer');
                    return;
                }
                
                // Mark that we're in the process of setting remote description
                isNegotiating = true;
                
                // Set remote description from offer
                const offerDesc = new RTCSessionDescription(signal.sdp);
                await peerConnection.setRemoteDescription(offerDesc);
                console.log('Set remote description from offer');
                
                // Create and set local description (answer)
                const answer = await peerConnection.createAnswer();
                await peerConnection.setLocalDescription(answer);
                console.log('Created and set local answer');
                
                // Send answer back using sendSignal
                const success = await sendSignal({
                    type: 'answer',
                    sdp: peerConnection.localDescription,
                    to: signal.from
                });
                
                if (success) {
                    console.log('Answer sent successfully to caller');
                } else {
                    console.error('Failed to send answer to caller');
                    updateCallStatus('Connection issue. Please try again.');
                }
                
                // Process any pending ICE candidates that arrived before the remote description was set
                if (pendingIceCandidates.length > 0) {
                    console.log(`Processing ${pendingIceCandidates.length} pending ICE candidates`);
                    
                    for (const candidate of pendingIceCandidates) {
                        try {
                            await peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
                            console.log('Added pending ICE candidate');
                        } catch (err) {
                            console.error('Error adding pending ICE candidate:', err);
                        }
                    }
                    
                    // Clear the pending candidates
                    pendingIceCandidates = [];
                }
                
                // Update UI
                updateCallStatus('Call connected');
                
                // Hide the calling status div once connected
                setTimeout(() => {
                    const callingStatus = document.getElementById('calling-status');
                    if (callingStatus) {
                        callingStatus.style.display = 'none';
                    }
                }, 1000);
                
                // Reset negotiation flag
                isNegotiating = false;
            } catch (err) {
                console.error('Error handling offer:', err);
                updateCallStatus('Failed to process call offer');
                isNegotiating = false;
            }
            break;
            
        case 'answer':
            try {
                if (!peerConnection) {
                    console.error('No peer connection available for answer');
                    return;
                }
                
                // Mark that we're in the process of setting remote description
                isNegotiating = true;
                
                // Set remote description from answer
                const answerDesc = new RTCSessionDescription(signal.sdp);
                await peerConnection.setRemoteDescription(answerDesc);
                console.log('Set remote description from answer');
                
                // Process any pending ICE candidates that arrived before the remote description was set
                if (pendingIceCandidates.length > 0) {
                    console.log(`Processing ${pendingIceCandidates.length} pending ICE candidates`);
                    
                    for (const candidate of pendingIceCandidates) {
                        try {
                            await peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
                            console.log('Added pending ICE candidate');
                        } catch (err) {
                            console.error('Error adding pending ICE candidate:', err);
                        }
                    }
                    
                    // Clear the pending candidates
                    pendingIceCandidates = [];
                }
                
                // Update UI
                updateCallStatus('Call connected');
                
                // Hide the calling status div once connected
                setTimeout(() => {
                    const callingStatus = document.getElementById('calling-status');
                    if (callingStatus) {
                        callingStatus.style.display = 'none';
                    }
                }, 1000);
                
                // Reset negotiation flag
                isNegotiating = false;
            } catch (err) {
                console.error('Error handling answer:', err);
                updateCallStatus('Failed to establish connection');
                isNegotiating = false;
            }
            break;
            
        case 'ice-candidate':
            try {
                if (!peerConnection) {
                    console.error('No peer connection available for ICE candidate');
                    return;
                }
                
                // Add ICE candidate if we have a remote description, otherwise store it
                if (signal.candidate) {
                    if (peerConnection.remoteDescription && peerConnection.remoteDescription.type) {
                        try {
                            await peerConnection.addIceCandidate(new RTCIceCandidate(signal.candidate));
                            console.log('Added ICE candidate');
                        } catch (err) {
                            console.error('Error adding ICE candidate:', err);
                        }
                    } else {
                        // Store the candidate to add later
                        console.log('Remote description not set yet, storing ICE candidate for later');
                        pendingIceCandidates.push(signal.candidate);
                    }
                }
            } catch (err) {
                console.error('Error handling ICE candidate:', err);
            }
            break;
            
        case 'call-accepted':
            console.log('Call accepted by recipient');
            updateCallStatus('Call connected');
            
            // If we're the initiator and haven't sent an offer yet, send one now
            if (config.isInitiator && peerConnection && peerConnection.signalingState === 'stable') {
                console.log('Sending offer after call acceptance');
                sendOffer();
            }
            
            // Hide the calling status div once connected
            setTimeout(() => {
                const callingStatus = document.getElementById('calling-status');
                if (callingStatus) {
                    callingStatus.style.display = 'none';
                }
            }, 1000);
            break;
            
        case 'call-ended':
            console.log('Call ended by other party');
            updateCallStatus('Call ended by other party');
            
            // End the call on our side
            endCall();
            break;
            
        default:
            console.log('Unknown signal type:', signal.type);
    }
};

// Create peer connection
const createPeerConnection = () => {
    if (peerConnection) {
        console.log('Peer connection already exists, not creating a new one');
        return;
    }
    
    console.log('Creating peer connection');
    
    // ICE servers configuration
    const iceServers = {
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'stun:stun2.l.google.com:19302' },
            { urls: 'stun:stun3.l.google.com:19302' },
            { urls: 'stun:stun4.l.google.com:19302' }
        ]
    };
    
    try {
        // Create peer connection
        peerConnection = new RTCPeerConnection(iceServers);
        
        // Add local stream tracks to peer connection
        if (localStream) {
            localStream.getTracks().forEach(track => {
                peerConnection.addTrack(track, localStream);
                console.log('Added local track to peer connection:', track.kind);
            });
        } else {
            console.error('No local stream available to add to peer connection');
            
            // Try to get media access if not already available
            initializeMedia().then(() => {
                if (localStream && peerConnection) {
                    console.log('Media initialized, adding tracks to peer connection');
                    localStream.getTracks().forEach(track => {
                        peerConnection.addTrack(track, localStream);
                        console.log('Added local track to peer connection (delayed):', track.kind);
                    });
                }
            }).catch(err => {
                console.error('Failed to initialize media after peer connection creation:', err);
            });
        }
        
        // Handle ICE candidates
        peerConnection.onicecandidate = async (event) => {
            if (event.candidate) {
                console.log('Generated ICE candidate');
                
                // Send ICE candidate to the other peer using sendSignal
                const success = await sendSignal({
                    type: 'ice-candidate',
                    candidate: event.candidate,
                    to: config.otherUserId
                });
                
                if (success) {
                    console.log('ICE candidate sent successfully');
                } else {
                    console.error('Failed to send ICE candidate');
                }
            } else {
                console.log('All ICE candidates gathered');
            }
        };
        
        // Handle ICE connection state changes
        peerConnection.oniceconnectionstatechange = () => {
            console.log('ICE connection state:', peerConnection.iceConnectionState);
            
            switch (peerConnection.iceConnectionState) {
                case 'connected':
                case 'completed':
                    updateCallStatus('Connected');
                    // Hide the calling status div once connected
                    setTimeout(() => {
                        const callingStatus = document.getElementById('calling-status');
                        if (callingStatus) {
                            callingStatus.style.display = 'none';
                        }
                    }, 1000);
                    break;
                    
                case 'failed':
                    updateCallStatus('Connection lost. Trying to reconnect...');
                    
                    // Try to restart ICE if it failed
                    if (peerConnection.restartIce) {
                        console.log('Attempting to restart ICE');
                        peerConnection.restartIce();
                    }
                    break;
                    
                case 'disconnected':
                    updateCallStatus('Connection lost. Trying to reconnect...');
                    
                    // For disconnected state, wait a bit and see if it recovers
                    setTimeout(() => {
                        if (peerConnection && peerConnection.iceConnectionState === 'disconnected') {
                            console.log('Still disconnected after timeout, attempting to restart ICE');
                            if (peerConnection.restartIce) {
                                peerConnection.restartIce();
                            }
                        }
                    }, 3000);
                    break;
                    
                case 'closed':
                    updateCallStatus('Connection closed');
                    break;
            }
        };
        
        // Handle connection state changes
        peerConnection.onconnectionstatechange = () => {
            console.log('Connection state:', peerConnection.connectionState);
            
            switch (peerConnection.connectionState) {
                case 'connected':
                    console.log('PeerConnection connected successfully');
                    break;
                    
                case 'failed':
                    console.error('PeerConnection failed');
                    updateCallStatus('Connection failed. Please try again.');
                    break;
                    
                case 'closed':
                    console.log('PeerConnection closed');
                    break;
            }
        };
        
        // Handle remote stream
        peerConnection.ontrack = (event) => {
            console.log('Received remote track:', event.track.kind);
            
            // Set remote video element
            const remoteVideo = document.getElementById('remote-video');
            if (remoteVideo && event.streams && event.streams[0]) {
                remoteVideo.srcObject = event.streams[0];
                hasRemoteStream = true;
                
                // Play remote video
                remoteVideo.onloadedmetadata = () => {
                    remoteVideo.play().catch(err => {
                        console.error('Error playing remote video:', err);
                    });
                };
            }
        };
        
        // Handle negotiation needed
        peerConnection.onnegotiationneeded = async () => {
            console.log('Negotiation needed');
            
            // Avoid negotiation collisions by checking if we're already negotiating
            if (isNegotiating) {
                console.log('Already negotiating, skipping');
                return;
            }
            
            try {
                isNegotiating = true;
                
                if (config.isInitiator) {
                    console.log('We are initiator, sending offer');
                    await sendOffer();
                }
            } catch (err) {
                console.error('Error during negotiation:', err);
            } finally {
                isNegotiating = false;
            }
        };
        
        // Handle signaling state changes
        peerConnection.onsignalingstatechange = () => {
            console.log('Signaling state:', peerConnection.signalingState);
            
            // Reset negotiating flag when signaling state becomes stable
            if (peerConnection.signalingState === 'stable') {
                isNegotiating = false;
            }
        };
        
        return peerConnection;
    } catch (err) {
        console.error('Error creating peer connection:', err);
        updateCallStatus('Failed to create connection');
        return null;
    }
};

// Send WebRTC offer
const sendOffer = async () => {
    if (!peerConnection) {
        console.error('Cannot send offer: peer connection not created');
        return;
    }
    
    // Don't send offers if we're already negotiating
    if (isNegotiating) {
        console.log('Already negotiating, not sending offer');
        return;
    }
    
    try {
        isNegotiating = true;
        
        // Ensure we have media before sending an offer
        if (!localStream) {
            console.log('Waiting for local media before sending offer...');
            try {
                await initializeMedia();
                
                // If we had to initialize media, we should recreate the peer connection
                // to avoid the m-line order issue
                if (peerConnection) {
                    console.log('Closing existing peer connection and creating a new one');
                    peerConnection.close();
                    peerConnection = null;
                    createPeerConnection();
                    
                    // If we had to recreate the peer connection, we should return and wait for
                    // the negotiationneeded event to be fired again
                    isNegotiating = false;
                    return;
                }
            } catch (mediaErr) {
                console.error('Failed to get local media before sending offer:', mediaErr);
                updateCallStatus('Failed to access camera or microphone');
                isNegotiating = false;
                return;
            }
        }
        
        // If peerConnection is null (closed above), create a new one
        if (!peerConnection) {
            console.log('Creating new peer connection before sending offer');
            createPeerConnection();
            
            // If we had to create a new peer connection, we should return and wait for
            // the negotiationneeded event to be fired
            isNegotiating = false;
            return;
        }
        
        // Check signaling state before creating offer
        if (peerConnection.signalingState !== 'stable') {
            console.log('Signaling state is not stable, waiting before sending offer');
            isNegotiating = false;
            return;
        }
        
        console.log('Creating offer');
        
        // Create offer with specific options
        const offerOptions = {
            offerToReceiveAudio: true,
            offerToReceiveVideo: config.callType === 'video',
            voiceActivityDetection: true
        };
        
        console.log('Offer options:', offerOptions);
        const offer = await peerConnection.createOffer(offerOptions);
        
        // Log the SDP for debugging
        console.log('Generated offer SDP:', offer.sdp);
        
        // Check signaling state again before setting local description
        if (peerConnection.signalingState !== 'stable') {
            console.log('Signaling state changed, not setting local description');
            isNegotiating = false;
            return;
        }
        
        // Set local description
        try {
            await peerConnection.setLocalDescription(offer);
            console.log('Set local description (offer)');
        } catch (sdpError) {
            console.error('Error setting local description:', sdpError);
            
            // Try recreating the peer connection and offer as a fallback
            console.log('Recreating peer connection as fallback');
            if (peerConnection) {
                peerConnection.close();
                peerConnection = null;
            }
            
            createPeerConnection();
            
            // We'll let the negotiationneeded event trigger a new offer
            isNegotiating = false;
            return;
        }
        
        // Send offer to other peer using the sendSignal function
        const success = await sendSignal({
            type: 'offer',
            sdp: peerConnection.localDescription,
            to: config.otherUserId
        });
        
        if (success) {
            console.log('Offer sent successfully to recipient');
        } else {
            console.error('Failed to send offer to recipient');
            updateCallStatus('Failed to connect. Please try again.');
        }
        
        // Reset negotiating flag when done
        isNegotiating = false;
    } catch (err) {
        console.error('Error creating/sending offer:', err);
        updateCallStatus('Failed to initiate call');
        isNegotiating = false;
    }
};

// Accept incoming call
const acceptCall = async () => {
    if (!callChannel) {
        console.error('Cannot accept call: call channel not available');
        return;
    }
    
    console.log('Accepting call');
    
    // Update UI
    updateCallStatus('Connecting...');
    
    try {
        // Make sure we have media before accepting
        if (!localStream) {
            console.log('Waiting for local media before accepting call...');
            try {
                await initializeMedia();
                
                // If we had to initialize media, make sure tracks are added to the peer connection
                if (peerConnection && localStream) {
                    console.log('Adding tracks to peer connection after media initialization');
                    localStream.getTracks().forEach(track => {
                        // Check if track is already added
                        const senders = peerConnection.getSenders();
                        const isTrackAdded = senders.some(sender => sender.track === track);
                        
                        if (!isTrackAdded) {
                            peerConnection.addTrack(track, localStream);
                            console.log('Added local track to peer connection:', track.kind);
                        }
                    });
                }
            } catch (mediaErr) {
                console.error('Failed to get local media before accepting call:', mediaErr);
                updateCallStatus('Failed to access camera or microphone');
                return;
            }
        }
        
        // Send accept signal
        const success = await sendSignal({
            type: 'call-accepted',
            to: config.otherUserId
        });
        
        if (success) {
            console.log('Call acceptance signal sent successfully');
        } else {
            console.error('Failed to send call acceptance signal');
        }
        
        // Update call status in database
        fetch('/bagoscout/api/call.php?action=update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                callId: config.callId,
                status: 'connected'
            })
        })
        .then(response => {
            if (!response.ok) {
                console.error('Failed to update call status to connected:', response.status);
            }
            return response.json();
        })
        .catch(error => {
            console.error('Error updating call status to connected:', error);
        });
        
        // Add system message about call
        addSystemMessage('Call connected');
    } catch (error) {
        console.error('Error accepting call:', error);
        updateCallStatus('Failed to accept call. Please try again.');
    }
};

// Reject incoming call
const rejectCall = () => {
    if (!callChannel) {
        console.error('Cannot reject call: call channel not available');
        return;
    }
    
    console.log('Rejecting call');
    
    // Send rejection to caller
    sendSignal({
        type: 'call-rejected',
        to: config.otherUserId
    }).catch(error => {
        console.error('Error sending call-rejected signal:', error);
    });
    
    // Update call status in database
    fetch('/bagoscout/api/call.php?action=update', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            callId: config.callId,
            status: 'rejected'
        })
    })
    .then(response => {
        if (!response.ok) {
            console.error('Failed to update call status to rejected:', response.status);
        }
        return response.json();
    })
    .catch(error => {
        console.error('Error updating call status to rejected:', error);
    });
    
    // Add system message about call
    addSystemMessage('Call rejected');
    
    // Close the call window
    cleanup();
    window.close();
};

// Format call duration
const formatCallDuration = (seconds) => {
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
};

// Add system message function
const addSystemMessage = (message) => {
    // Check if we have the necessary information
    if (!config || !config.callId) {
        console.log('Cannot add system message, missing call ID');
        return;
    }
    
    // Get conversation ID
    const conversationId = getConversationIdFromUrl();
    if (!conversationId) {
        console.log('Cannot add system message, missing conversation ID');
        return;
    }
    
    // Send system message
    fetch('/bagoscout/api/messages.php?action=system_message', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            conversation_id: conversationId,
            message: `${message} (${config.callId})`, // Include call ID for tracking
            sender_id: config.userId,
            receiver_id: config.otherUserId,
            call_id: config.callId
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log('System message added:', data);
    })
    .catch(error => {
        console.error('Error adding system message:', error);
    });
};

// Get conversation ID from URL parameter or storage
const getConversationIdFromUrl = () => {
    // Try to get from URL of the messaging page that opened this call
    if (document.referrer) {
        try {
            const referrerUrl = new URL(document.referrer);
            const params = new URLSearchParams(referrerUrl.search);
            const id = params.get('id');
            if (id) return id;
        } catch (e) {
            console.error('Error parsing referrer URL:', e);
        }
    }
    
    // Try to get from localStorage if available
    if (typeof localStorage !== 'undefined') {
        const conversationId = localStorage.getItem('current_conversation_id');
        if (conversationId) return conversationId;
    }
    
    return null;
};

// Update call status text
const updateCallStatus = (text) => {
    const statusElement = document.getElementById('call-status-text');
    if (statusElement) {
        statusElement.textContent = text;
    }
};

// Initialize media streams
const initializeMedia = async () => {
    try {
        // Set media constraints based on call type
        if (config.callType === 'video') {
            console.log('Setting up video call with constraints');
            mediaConstraints.video = {
                width: { ideal: 1280 },
                height: { ideal: 720 }
            };
        } else {
            console.log('Setting up audio-only call');
            mediaConstraints.video = false;
        }
        
        console.log('Requesting media with constraints:', JSON.stringify(mediaConstraints));
        
        // Get user media
        localStream = await navigator.mediaDevices.getUserMedia(mediaConstraints);
        
        console.log('Media stream obtained:', localStream.getTracks().map(track => ({
            kind: track.kind,
            enabled: track.enabled,
            muted: track.muted,
            id: track.id
        })));
        
        // Log available devices for debugging
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoDevices = devices.filter(device => device.kind === 'videoinput');
        console.log('Available video devices:', videoDevices.length);
        
        // Set local video element
        const localVideo = document.getElementById('local-video');
        if (localVideo) {
            localVideo.srcObject = localStream;
            localVideo.style.display = 'block'; // Ensure video element is visible
            
            // Log when video is playing
            localVideo.onloadedmetadata = () => {
                console.log('Local video metadata loaded');
                localVideo.play().then(() => {
                    console.log('Local video playing');
                }).catch(err => {
                    console.error('Error playing local video:', err);
                });
            };
        } else {
            console.error('Local video element not found');
        }
        
        // If not initiator and Ably is connected, accept the call
        // The acceptCall will be called from setupCallChannel if Ably isn't ready yet
        if (!config.isInitiator && ably && ably.connection.state === 'connected' && callChannel) {
            acceptCall();
        }
    } catch (err) {
        console.error('Error accessing media devices:', err);
        
        // Show more detailed error message
        let errorMsg = 'Could not access camera or microphone. ';
        if (err.name === 'NotAllowedError') {
            errorMsg += 'Please grant permission to use your camera and microphone.';
        } else if (err.name === 'NotFoundError') {
            errorMsg += 'No camera or microphone found.';
        } else if (err.name === 'NotReadableError' || err.name === 'AbortError') {
            errorMsg += 'Your camera or microphone might be in use by another application.';
        } else {
            errorMsg += err.message || 'Unknown error';
        }
        
        updateCallStatus(errorMsg);
        
        // If video fails but it's a video call, try falling back to audio only
        if (config.callType === 'video' && (err.name === 'NotFoundError' || err.name === 'NotAllowedError')) {
            console.log('Falling back to audio-only call');
            mediaConstraints.video = false;
            try {
                localStream = await navigator.mediaDevices.getUserMedia(mediaConstraints);
                
                const localVideo = document.getElementById('local-video');
                if (localVideo) {
                    localVideo.srcObject = localStream;
                    // Hide local video element if no video
                    localVideo.style.display = 'none';
                }
                
                // Only accept call if Ably is connected
                if (!config.isInitiator && ably && ably.connection.state === 'connected' && callChannel) {
                    acceptCall();
                }
            } catch (fallbackErr) {
                console.error('Error accessing audio devices:', fallbackErr);
                updateCallStatus('Could not access microphone. Please check your device permissions.');
            }
        }
    }
};

// Initialize call
const initializeCall = async (params) => {
    config = params;
    
    console.log('Initializing WebRTC call with params:', params);
    
    // Validate required parameters
    if (!config.callId || !config.userId || !config.otherUserId) {
        console.error('Missing required call parameters:', config);
        updateCallStatus('Missing required call parameters');
        return;
    }
    
    // Set otherUserName if available
    if (params.otherUserName) {
        otherUserName = params.otherUserName;
    } else {
        // Try to get other user name from UI
        const callingStatusHeading = document.querySelector('#calling-status h3');
        if (callingStatusHeading) {
            otherUserName = callingStatusHeading.textContent.trim();
        }
    }
    
    // Set in-call status in session storage
    if (typeof sessionStorage !== 'undefined') {
        sessionStorage.setItem('bagoscout_in_call', 'true');
        console.log('Set in-call status to true');
    }
    
    // Check for device support
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        updateCallStatus('Your browser does not support video calls');
        console.error('getUserMedia not supported');
        return;
    }
    
    // Pre-check available devices
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const hasAudio = devices.some(device => device.kind === 'audioinput');
        const hasVideo = devices.some(device => device.kind === 'videoinput');
        
        console.log('Available devices - Audio:', hasAudio, 'Video:', hasVideo);
        
        if (config.callType === 'video' && !hasVideo) {
            console.warn('Video requested but no camera found');
        }
        
        if (!hasAudio) {
            console.warn('No microphone detected');
            updateCallStatus('No microphone detected');
        }
    } catch (err) {
        console.error('Error checking media devices:', err);
    }
    
    // Set up event listeners for call controls
    document.getElementById('toggle-audio')?.addEventListener('click', toggleAudio);
    
    if (config.callType === 'video') {
        document.getElementById('toggle-video')?.addEventListener('click', toggleVideo);
    }
    
    document.getElementById('end-call')?.addEventListener('click', endCall);
    
    // Handle browser close
    window.addEventListener('beforeunload', (event) => {
        // Calculate call duration
        let duration = 0;
        if (callStartTime) {
            duration = Math.floor((Date.now() - callStartTime) / 1000);
            callDuration = duration;
        }
        
        // Update call status to ended in the database
        fetch('/bagoscout/api/call.php?action=update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                callId: config.callId,
                status: 'ended',
                duration: duration
            })
        });
        
        // Send end call signal with duration
        if (callChannel) {
            try {
                callChannel.publish('call-signal', {
                    type: 'call-ended',
                    from: config.userId,
                    to: config.otherUserId,
                    duration: duration,
                    timestamp: Date.now()
                });
            } catch (error) {
                console.error('Error sending call-ended signal on tab close:', error);
            }
        }
        
        cleanup();
        
        // Clear in-call status
        if (typeof sessionStorage !== 'undefined') {
            sessionStorage.removeItem('bagoscout_in_call');
        }
    });
    
    try {
        // Initialize Ably first
        await initializeAbly();
        
        // Media will be initialized in the setupCallChannel function
        // to ensure proper order of operations
    } catch (err) {
        console.error('Failed to initialize call:', err);
        updateCallStatus('Connection failed. Please try again.');
        
        // Try to recover from Ably initialization failure
        setTimeout(() => {
            console.log('Attempting to reconnect after initialization failure...');
            initializeAbly()
                .then(() => console.log('Reconnection successful'))
                .catch(retryErr => console.error('Reconnection failed:', retryErr));
        }, 3000);
    }
};

// Update call duration display
const updateCallDuration = (seconds) => {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;
    
    const durationText = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
    
    // Update UI with call duration
    const durationElement = document.getElementById('call-duration');
    if (durationElement) {
        durationElement.textContent = durationText;
    } else {
        // Create call duration element if it doesn't exist
        const durationDiv = document.createElement('div');
        durationDiv.id = 'call-duration';
        durationDiv.className = 'call-duration';
        durationDiv.textContent = durationText;
        
        // Insert into the DOM
        const controlsElement = document.querySelector('.controls');
        if (controlsElement) {
            controlsElement.parentNode.insertBefore(durationDiv, controlsElement);
        } else {
            // If controls not found, add to the call container
            const callContainer = document.querySelector('.call-container');
            if (callContainer) {
                callContainer.appendChild(durationDiv);
            }
        }
        
        // Add styles if not already added
        if (!document.getElementById('call-duration-styles')) {
            const style = document.createElement('style');
            style.id = 'call-duration-styles';
            style.textContent = `
                .call-duration {
                    position: absolute;
                    top: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background-color: rgba(0, 0, 0, 0.5);
                    color: white;
                    padding: 5px 10px;
                    border-radius: 4px;
                    font-size: 16px;
                    font-weight: bold;
                    z-index: 100;
                }
            `;
            document.head.appendChild(style);
        }
    }
};

// Send signal to the other peer
const sendSignal = async (signal) => {
    if (!callChannel) {
        console.error('Call channel not initialized');
        return false;
    }

    try {
        // Add from and timestamp to the signal
        const signalWithMetadata = {
            ...signal,
            from: config.userId,
            timestamp: Date.now()
        };

        console.log('Sending signal:', signalWithMetadata);
        
        // Try to publish the message
        try {
            await callChannel.publish('call-signal', signalWithMetadata);
            console.log('Signal sent successfully');
            return true;
        } catch (error) {
            console.error('Error sending signal through channel:', error);
            
            // If the first attempt fails, try again with a slight delay
            return new Promise((resolve) => {
                setTimeout(async () => {
                    try {
                        await callChannel.publish('call-signal', signalWithMetadata);
                        console.log('Signal sent successfully (retry)');
                        resolve(true);
                    } catch (retryError) {
                        console.error('Error sending signal (retry):', retryError);
                        
                        // Last resort - try to reconnect Ably and then send
                        try {
                            if (ably && ably.connection.state !== 'connected') {
                                console.log('Attempting to reconnect Ably before sending signal...');
                                ably.connection.connect();
                                
                                // Wait for connection or timeout
                                await new Promise((connResolve) => {
                                    const timeout = setTimeout(() => {
                                        console.log('Connection attempt timed out');
                                        connResolve(false);
                                    }, 3000);
                                    
                                    ably.connection.once('connected', () => {
                                        clearTimeout(timeout);
                                        console.log('Reconnected to Ably');
                                        connResolve(true);
                                    });
                                });
                                
                                // Try one more time to send the signal
                                try {
                                    await callChannel.publish('call-signal', signalWithMetadata);
                                    console.log('Signal sent successfully (after reconnect)');
                                    resolve(true);
                                } catch (finalError) {
                                    console.error('Failed to send signal after reconnect:', finalError);
                                    resolve(false);
                                }
                            } else {
                                resolve(false);
                            }
                        } catch (connectionError) {
                            console.error('Error reconnecting:', connectionError);
                            resolve(false);
                        }
                    }
                }, 1000);
            });
        }
    } catch (error) {
        console.error('Error preparing signal:', error);
        return false;
    }
}; 