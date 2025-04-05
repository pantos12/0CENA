# 0CENA

A lightweight PHP web application for grading park and recreation agency submissions using OpenAI's GPT API.

## Features

- Beautiful landing page with background video and falling leaves animation
- File upload via drag-and-drop or file browser
- Support for various document formats (.doc, .docx, .pdf, .txt)
- AI-powered grading using OpenAI's GPT models
- Tabbed interface for uploads and results
- SQLite database for storing submission results
- Responsive design for mobile and desktop
- Containerized with Docker for easy deployment

## Docker Setup (Recommended)

### Prerequisites
- Docker and Docker Compose installed on your system

### Quick Start
1. Clone this repository:
   ```
   git clone https://github.com/yourusername/0CENA.git
   cd 0CENA
   ```

2. Copy the environment file and add your OpenAI API key:
   ```
   cp .env.example .env
   # Edit .env and set your OPENAI_API_KEY
   ```

3. Start the application:
   ```
   docker-compose up -d
   ```

4. Access the application at http://localhost:8080

### Docker Commands
- Start in background: `docker-compose up -d`
- View logs: `docker-compose logs -f`
- Stop: `docker-compose down`
- Rebuild: `docker-compose build --no-cache`

## Manual Setup

1. Clone this repository to your web server
2. Ensure PHP is installed with SQLite and cURL extensions enabled
3. Create the following directories with write permissions:
   - `uploads/`
   - `database/`
   - `logs/`
   - `images/`
   - `videos/`
4. Place your background video in the `videos/` directory as `droneCLEV.mp4`
5. Create or obtain leaf images and place them in the `images/` directory:
   - `leaf1.png`
   - `leaf2.png`
   - `leaf3.png`
6. Create or obtain a logo and place it in the `images/` directory as `logo.png`
7. API Key Setup: Copy `.env.example` to `.env` and add your OpenAI API key
8. Start the development server:
   ```
   php serve.php
   ```
9. Access the application at http://127.0.0.1:8000

## API Key Security

For the most secure operation:

1. Copy the `.env.example` file to `.env`: `cp .env.example .env`
2. Edit the `.env` file and replace `your_api_key_here` with your actual OpenAI API key
3. Ensure the `.env` file has restricted permissions: `chmod 600 .env`
4. The `.env` file is already in `.gitignore` to prevent accidental commits

This way, your API key remains on the server and is never exposed to clients.

## Background Video Instructions

The background video should be:
- High-quality, nature-themed footage (parks, forests, etc.)
- Optimized for web (compressed, ideally less than 10MB)
- 1080p resolution recommended
- 15-30 seconds long (it will loop automatically)
- Saved as `droneCLEV.mp4` in the `videos/` directory

## Technical Details

This application uses:
- PHP for server-side processing
- jQuery for frontend interactions
- SQLite for data storage
- OpenAI API for document grading
- Docker for containerization

## Security Notes

- API keys are stored securely in the `.env` file on the server
- Implement proper authentication and authorization before deploying in production
- Add rate limiting to prevent API abuse
- Consider implementing file type validation beyond extension checking

## License

This project is licensed under the MIT License - see the LICENSE file for details. 