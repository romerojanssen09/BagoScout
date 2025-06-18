# BagoScout - Job Portal

## Recent Updates

### Admin Area Enhancements
- **Complete Admin Dashboard**: Enhanced with statistics, recent users, and messages
- **Employer Management**: View and manage employer accounts with detailed profiles
- **Job Seeker Management**: View and manage job seeker accounts with skills and fields
- **Settings Page**: Configure site settings, email settings, and registration options
- **Contact Messages**: View and manage messages from the contact form

### User Profile Enhancements
- **Skills Management**: Job seekers can add and update their skills
- **Work Fields**: Job seekers can specify their work fields of interest
- **Business Fields**: Employers can add their business fields

### Contact System
- **Contact Form**: Users can send messages to site administrators
- **Admin Notification**: Admins receive email notifications for new messages
- **Message Management**: Admins can view, mark as read, and delete messages

### Database Updates
- Added `contact_messages` table for storing user inquiries
- Added `settings` table for site configuration
- Added `fields` column to employers table
- Added `fields` and `skills` columns to jobseekers table

## Installation

1. Clone the repository
2. Import the database schema
3. Configure database connection in `config/database.php`
4. Run the SQL updates in `db_updates.sql`

## Features

- User registration and authentication
- Account verification with ID and photo upload
- Job posting and application system
- Admin approval workflow
- Profile management
- Contact system
- Responsive design

## License

This project is licensed under the MIT License.

# BagoScout Call Activity Integration

This document outlines the enhancements made to better integrate call activities with the messaging interface in the BagoScout application.

## Database Structure

The system uses two main tables for calls and messages:

- **calls** - Stores call records with details like initiator, recipient, status, and duration
- **messages** - Stores messaging data, including system messages about calls

Key fields in the `calls` table:
- `call_id`: Unique identifier for the call
- `initiator_id`: User who initiated the call
- `recipient_id`: User who received the call
- `call_type`: Audio or video call
- `status`: Current call status (initiated, accepted, rejected, ended, missed)
- `duration`: Call duration in seconds
- `created_at`: When the call was started

## Call Integration Improvements

### 1. Database Schema Enhancements

- Added the `is_system` column to the messages table to identify system messages
- Added additional indexes to optimize query performance:
  - `is_system` index for faster filtering of system messages
  - `created_at` and `status` indexes on the calls table

### 2. API Enhancements (api/messages.php)

- Enhanced message queries to JOIN with the calls table to retrieve call details
- Improved filtering using the new `is_system` flag
- Added proper correlation between call records and system messages
- Added `formatCallDuration()` function to consistently format call durations on the server-side

### 3. Frontend Improvements

#### Call Handler (call-handler.js)

- Modified `addSystemMessage()` to pass the call_id to system messages
- Improved system message creation for different call states (missed, rejected, accepted)

#### WebRTC Handler (webrtc.js)

- Enhanced `addSystemMessage()` to include call_id with system messages
- Updated message formatting to use server-provided call duration formatting

#### Messaging UI (employer-messaging.js & seeker-messaging.js)

- Enhanced display of call information in the message list
- Added visual indicators for different call states with appropriate icons and colors
- Added proper call duration formatting using server-provided formatted durations

## Call Status Display

The system now displays call messages with appropriate styling based on status:

| Call Status | Icon Color | Description |
|-------------|------------|-------------|
| Ended       | Green      | Successfully completed call with duration |
| Missed      | Yellow     | Call was not answered |
| Rejected    | Red        | Call was actively declined |
| Initiated   | Blue       | Call was started (generic) |

For ended calls, the duration is displayed in a human-readable format (hours, minutes, seconds).

## System Message Flow

1. When a call event occurs (start, end, missed, etc.), the system:
   - Updates the call record in the database
   - Creates a system message in the conversation
   - Links the system message to the call via the call_id
   - Publishes real-time updates through Ably

2. When displaying messages, the system:
   - Identifies call-related system messages
   - Retrieves associated call details from the database
   - Formats and styles the message based on call status
   - Shows call duration for completed calls

This integration provides users with a complete history of their call activities directly within their messaging interface. "# BagoScout" 
