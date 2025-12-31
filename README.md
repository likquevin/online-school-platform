# Online School & Live Classroom Platform

## Overview
This project is a full-stack web-based online education platform designed to support
virtual classrooms, real-time teaching, and automated student assessments.

The system enables schools and educators to deliver structured learning experiences
through live video classes, real-time communication, and online examinations,
all within a secure and scalable environment.

## Problem Statement
Access to quality education remains a challenge in many low-resource environments
due to limited infrastructure and physical classroom constraints.

This platform was built to demonstrate how modern web technologies can be used
to deliver effective online education using lightweight, accessible systems.

## Key Features
- Role-based access control (Admin, Teacher, Student)
- Secure session-based authentication and authorization
- Live classroom with video and audio streaming (WebRTC)
- Real-time class chat using WebSockets
- Classroom-based assessment system
- Time-bound online examinations
- Automated grading for multiple-choice questions (MCQ)
- Manual grading support for written/short-answer questions
- Admin and teacher dashboards for classroom and assessment management

## Technology Stack

### Backend
- PHP (server-side logic, access control, grading engine)
- MySQL (relational database design and persistence)

### Frontend
- HTML5 (semantic structure)
- CSS3 (responsive layout, animations)
- Vanilla JavaScript (dynamic UI, AJAX communication)

### Real-Time Communication
- WebSocket (signaling and real-time messaging)
- WebRTC (live video and audio classrooms)
- STUN servers for peer-to-peer connectivity

### Security
- Session-based authentication
- Role enforcement (Admin / Teacher / Student)
- Access guards for protected resources

## System Architecture
The platform follows a modular architecture:

- Users (Admins, Teachers, Students)
- Classrooms
  - Assessments
    - Sections
      - Questions
        - Options (for MCQ)
        - Answers (student submissions)

### Assessment Flow
1. Teachers create assessments and sections
2. Students access assessments via classroom authorization
3. Answers are submitted asynchronously (AJAX)
4. MCQ answers are auto-graded server-side
5. Results are stored per student and per assessment
6. Written answers are available for manual grading

## Core System Modules
- Authentication & role-based access control
- Classroom management
- Live video classroom & chat
- Assessment engine (sections, questions, grading)
- Database transaction handling
- Real-time signaling and messaging

## Database Design (Core Tables)
- users (id, name, email, role)
- classrooms
- assessments
- assessment_sections
- assessment_questions
- assessment_options
- assessment_answers

## What I Learned
Through building this system, I gained hands-on experience in:
- Designing and implementing scalable backend systems
- Secure authentication and authorization mechanisms
- Relational database modeling and integrity
- Real-time web communication (WebSocket & WebRTC)
- Automated grading logic and transactional operations
- Building production-style applications beyond basic CRUD

## Future Improvements
- AI-assisted grading for written answers
- Student performance analytics dashboard
- Recommendation system for personalized learning
- API-based architecture for scalability and integration
- Enhanced security and performance optimizations

## Project Status
Actively developed as part of my software engineering portfolio.

## Author
Kevin Ishimwe
