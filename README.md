# Online School & Live Classroom Platform

## Overview
This project is a full-stack online learning platform designed to support virtual classrooms, real-time teaching, and automated student assessments.

It was built to address access-to-education challenges by providing a structured and scalable web-based learning environment.

## Key Features
- Role-based access control (Admin, Teacher, Student)
- Secure session-based authentication
- Live classroom using WebRTC (video & audio)
- Real-time chat via WebSockets
- Time-based online assessments
- Automated grading for MCQ exams
- Manual grading support for written answers
- Classroom and assessment management dashboard

## Technology Stack
- Backend: PHP
- Database: MySQL
- Frontend: HTML5, CSS3, Vanilla JavaScript
- Real-time Communication: WebSocket, WebRTC (STUN servers)
- Security: Sessions, access guards, role enforcement

## System Architecture
- Classrooms contain assessments
- Assessments contain sections
- Sections contain questions
- Students submit answers via AJAX
- MCQ questions are auto-graded server-side
- Results are stored per student and per assessment

## Why I Built This
Coming from a low-resource environment, I wanted to design a system that enables quality education without relying on physical classrooms.

This project helped me gain hands-on experience in:
- Backend system design
- Database relationships and integrity
- Authentication and authorization
- Real-time web communication
- Assessment logic and automation

## Future Improvements
- AI-assisted grading for written answers
- Learning analytics dashboard
- Recommendation system for students
- API-based architecture for scalability

## Status
Actively developed as part of my software engineering portfolio.
