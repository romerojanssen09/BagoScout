# Bagoscout Realtime Messaging System

This document provides instructions for setting up the realtime messaging system for Bagoscout.

## Prerequisites

1. PHP 7.4 or higher
2. MySQL 5.7 or higher
3. Ably account (for realtime messaging)

## Setup Instructions

### 1. Database Setup

Run the SQL script to create the necessary tables:

```sql
mysql -u your_username -p your_database_name < sql/messaging_tables.sql
```

Or import the `sql/messaging_tables.sql` file using phpMyAdmin.

### 2. Ably Setup

1. Sign up for an Ably account at [https://ably.com/](https://ably.com/)
2. Create a new application in the Ably dashboard
3. Get your API key from the application settings
4. Update the `api/ably-auth.php` file with your API key:

```php
// Your Ably API key - in production, this should be stored securely
$apiKey = 'YOUR_ABLY_API_KEY'; // Replace with your actual Ably API key
```

### 3. File Structure

The messaging system consists of the following files:

#### Frontend Files
- `assets/js/employer-messaging.js` - JavaScript for the employer messaging interface
- `assets/js/seeker-messaging.js` - JavaScript for the seeker messaging interface
- `pages/auth-user/employer/message.php` - Employer messaging page
- `pages/auth-user/seeker/message.php` - Seeker messaging page

#### API Endpoints
- `api/ably-auth.php` - Authenticates users with Ably
- `api/conversations.php` - Gets all conversations for the current user
- `api/messages.php` - Gets messages for a specific conversation
- `api/send-message.php` - Sends a new message
- `api/mark-read.php` - Marks messages as read
- `api/create-conversation.php` - Creates a new conversation
- `api/search-users.php` - Searches for users
- `api/get-user.php` - Gets information about a specific user

### 4. Usage

#### Starting a New Conversation

1. Click the "+" button in the conversations list
2. Search for a user by name or email
3. Click on a user to start a conversation

#### Sending Messages

1. Select a conversation from the list
2. Type your message in the input field
3. Press Enter or click the send button

#### Realtime Features

- Messages are delivered in realtime
- Typing indicators show when the other user is typing
- Online status is displayed for each user
- Unread message count is displayed for each conversation

## Security Considerations

- All API endpoints verify that the user is logged in
- Conversations and messages are only accessible to the participants
- Ably authentication is used to secure realtime channels

## Troubleshooting

If you encounter issues with the messaging system, check the following:

1. Make sure the database tables are created correctly
2. Verify that your Ably API key is correct
3. Check browser console for JavaScript errors
4. Ensure that all API endpoints are accessible

For more information, please contact support. 