# BagoScout - Job Portal

## About

BagoScout is a comprehensive job portal platform tailored for Bago City, connecting employers and job seekers with features like geospatial job matching, real-time communication, and smart recommendations.

## Recent Updates

- Implemented real-time video calling feature
- Added smart job matching algorithm based on skills and preferences
- Enhanced user verification system

## System Architecture

BagoScout is built with PHP on the backend and uses a combination of TailwindCSS and Alpine.js for the frontend. The system uses MySQL for data storage and Ably for real-time communication.

## System Flows

### User Authentication Flow

1. **Registration**:
   - Step 1: User selects account type (employer or jobseeker)
   - Step 2: User enters personal information (name, email, password)
   - Step 3: User enters contact information (phone, address)
   - Step 4: User uploads verification documents
   - Step 5: User selects fields of expertise and skills (for jobseekers) or company information (for employers)

2. **Login**:
   - User enters email and password
   - System verifies credentials and redirects to appropriate dashboard
   - Optional "Remember Me" functionality for extended sessions

3. **Account Verification**:
   - After registration, user account status is set to "unverified"
   - Admin reviews uploaded documents
   - Account status is updated to "active" when verified
   - Email notifications are sent to users when verification status changes

### Employer Flows

1. **Job Posting**:
   - Employer creates a new job listing with title, description, requirements, and location
   - Job is saved as draft or published immediately
   - Admin approval may be required for new employers

2. **Candidate Management**:
   - Review applications for posted jobs
   - Filter candidates by skills, experience, or application status
   - Shortlist candidates of interest
   - Schedule interviews with shortlisted candidates

3. **Communication**:
   - Real-time messaging with applicants
   - Video/audio call functionality for remote interviews
   - Email notifications for important updates

### Jobseeker Flows

1. **Job Search**:
   - Browse available jobs with search and filter options
   - View job details and company information
   - See location-based job recommendations
   - Save favorite jobs for later application

2. **Application Process**:
   - Apply to jobs with pre-filled information from profile
   - Track application status (pending, reviewed, shortlisted, rejected)
   - Receive notifications about application updates

3. **Profile Management**:
   - Update skills, experience, and work preferences
   - Upload portfolio documents
   - Manage visibility settings

### Real-time Communication System

1. **Messaging**:
   - Direct messaging between employers and jobseekers
   - Notification system for new messages
   - Message history and conversation tracking

2. **Video/Audio Calls**:
   - WebRTC-based call system using Ably for signaling
   - Call session management and recording
   - Call history for reference

3. **Notification System**:
   - Real-time notifications for messages, calls, application updates
   - Email notifications for important events
   - In-app notification center

### Admin Dashboard

1. **User Management**:
   - Review and verify new user registrations
   - Manage user accounts (suspend, activate, delete)
   - Handle user reports and issues

2. **Job Monitoring**:
   - Review and approve job postings
   - Monitor job activity and applications
   - Generate statistics and reports

3. **System Management**:
   - Configure system settings
   - Monitor system performance
   - Backup and restore data

## Database Schema

The system uses a relational database with the following core tables:

- `users`: Core user information and authentication
- `employers`: Employer-specific information
- `jobseekers`: Jobseeker-specific information
- `jobs`: Job listings
- `applications`: Job applications
- `calls`: Call records between users
- `notifications`: System notifications

## Technical Details

- **Backend**: PHP 8.x
- **Frontend**: HTML5, TailwindCSS, Alpine.js
- **Database**: MySQL
- **Real-time Communication**: Ably
- **Video Calls**: WebRTC with Ably signaling

## Installation and Setup

1. Clone the repository
2. Configure database in `config/database.php`
3. Set up API keys in `config/api_keys.php`
4. Run the database migrations
5. Set up a web server with PHP 8.x
6. Access the application through the web server

## Contributing

Contributions to BagoScout are welcome! Please follow these steps:

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

Copyright © 2024 BagoScout. All rights reserved.
