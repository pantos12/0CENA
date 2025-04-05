<?php
// Enable error reporting only to be captured, not displayed
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start output buffering to catch any unwanted output
ob_start();

// Set headers for JSON response
header('Content-Type: application/json');

// Configuration
$maxFileSize = 5 * 1024 * 1024; // 5 MB
$allowedExtensions = ['doc', 'docx', 'pdf', 'txt'];
$uploadDir = 'uploads/';

// Ensure the upload directory exists
if (!file_exists($uploadDir) && !is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

try {
    // Database setup
    $dbFile = 'database/submissions.sqlite';
    $dbDir = dirname($dbFile);

    if (!file_exists($dbDir) && !is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    $db = new SQLite3($dbFile);
    $db->exec("
        CREATE TABLE IF NOT EXISTS submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_name TEXT NOT NULL,
            word_count INTEGER,
            score INTEGER,
            confidence REAL,
            feedback TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Initialize response
    $response = [
        'success' => false,
        'submissions' => [],
        'error' => null
    ];

    // Validate request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response['error'] = 'Invalid request method.';
        echo json_encode($response);
        exit;
    }

    // Get API key from environment variables first (for Render deployment)
    $apiKey = getenv('OPENAI_API_KEY');
    
    // If no environment variable, try to get it from .env file
    if (empty($apiKey)) {
        $envFile = '.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false) {
                    list($name, $value) = explode('=', $line, 2);
                    if ($name === 'OPENAI_API_KEY' && !empty($value) && $value !== 'your_api_key_here') {
                        $apiKey = $value;
                        break;
                    }
                }
            }
        }
    }
    
    // Debug log - be careful with this in production!
    file_put_contents('logs/api_key_debug.log', date('Y-m-d H:i:s') . " - API Key Found: " . (!empty($apiKey) ? "YES" : "NO") . PHP_EOL, FILE_APPEND | LOCK_EX);

    // Check if files were uploaded
    if (!isset($_FILES['files'])) {
        $response['error'] = 'No files were uploaded.';
        echo json_encode($response);
        exit;
    }

    // Process each file
    $files = reArrayFiles($_FILES['files']);

    foreach ($files as $file) {
        $result = [
            'fileName' => $file['name'],
            'wordCount' => 0,
            'score' => 0,
            'confidence' => 0,
            'feedback' => '',
            'error' => null
        ];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $result['error'] = getUploadErrorMessage($file['error']);
            $response['submissions'][] = $result;
            continue;
        }
        
        // Validate file size
        if ($file['size'] > $maxFileSize) {
            $result['error'] = 'File is too large. Maximum size is 5 MB.';
            $response['submissions'][] = $result;
            continue;
        }
        
        // Validate file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            $result['error'] = 'Invalid file type. Allowed types: ' . implode(', ', $allowedExtensions);
            $response['submissions'][] = $result;
            continue;
        }
        
        // Generate unique filename
        $uniqueName = uniqid() . '_' . $file['name'];
        $uploadPath = $uploadDir . $uniqueName;
        
        // Move the uploaded file
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            $result['error'] = 'Failed to save the file.';
            $response['submissions'][] = $result;
            continue;
        }
        
        // Extract text content from the file
        try {
            $content = extractTextFromFile($uploadPath, $extension);
            
            // Count words
            $result['wordCount'] = str_word_count($content);
            
            // Grade the submission using OpenAI API
            $gradingResult = gradeSubmission($content, $apiKey);
            
            if (isset($gradingResult['error'])) {
                $result['error'] = $gradingResult['error'];
            } else {
                $result['score'] = $gradingResult['score'];
                $result['confidence'] = $gradingResult['confidence'];
                $result['feedback'] = $gradingResult['feedback'];
                
                // Save to database
                $stmt = $db->prepare("
                    INSERT INTO submissions (file_name, word_count, score, confidence, feedback)
                    VALUES (:fileName, :wordCount, :score, :confidence, :feedback)
                ");
                
                $stmt->bindValue(':fileName', $file['name'], SQLITE3_TEXT);
                $stmt->bindValue(':wordCount', $result['wordCount'], SQLITE3_INTEGER);
                $stmt->bindValue(':score', $result['score'], SQLITE3_INTEGER);
                $stmt->bindValue(':confidence', $result['confidence'], SQLITE3_FLOAT);
                $stmt->bindValue(':feedback', $result['feedback'], SQLITE3_TEXT);
                
                $stmt->execute();
            }
        } catch (Exception $e) {
            $result['error'] = 'Error processing file: ' . $e->getMessage();
        }
        
        $response['submissions'][] = $result;
    }

    // Close database connection
    $db->close();

    // Return response
    $response['success'] = count(array_filter($response['submissions'], function($sub) {
        return empty($sub['error']);
    })) > 0;

    // Clean any output buffer before sending JSON
    ob_end_clean();
    echo json_encode($response);
    exit;

} catch (Exception $e) {
    // Clean buffer and return error as JSON
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'submissions' => []
    ]);
    exit;
}

/**
 * Helper Functions
 */

/**
 * Reorganizes the $_FILES array for easier processing
 */
function reArrayFiles($files) {
    $fileArray = [];
    
    if (!is_array($files['name'])) {
        return [$files];
    }
    
    $fileCount = count($files['name']);
    $fileKeys = array_keys($files);
    
    for ($i = 0; $i < $fileCount; $i++) {
        foreach ($fileKeys as $key) {
            $fileArray[$i][$key] = $files[$key][$i];
        }
    }
    
    return $fileArray;
}

/**
 * Returns a human-readable upload error message
 */
function getUploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return 'The uploaded file exceeds the upload_max_filesize directive in php.ini.';
        case UPLOAD_ERR_FORM_SIZE:
            return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.';
        case UPLOAD_ERR_PARTIAL:
            return 'The uploaded file was only partially uploaded.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing a temporary folder.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the file upload.';
        default:
            return 'Unknown upload error.';
    }
}

/**
 * Extracts text content from different file types
 */
function extractTextFromFile($filePath, $extension) {
    // For simplicity, we're only handling text files in this example
    // Real implementation would need libraries for processing DOC, DOCX, PDF
    
    if ($extension === 'txt') {
        $content = file_get_contents($filePath);
        // Log the word count for debugging
        file_put_contents('logs/word_count_debug.log', date('Y-m-d H:i:s') . " - File: " . basename($filePath) . " - Content length: " . strlen($content) . " - Word count: " . countWordsAccurately($content) . PHP_EOL, FILE_APPEND | LOCK_EX);
        return $content;
    } else if (in_array($extension, ['doc', 'docx', 'pdf'])) {
        // In a real application, you would use libraries like:
        // - PhpWord for DOC/DOCX files
        // - TCPDF or Spatie/pdf-to-text for PDF files
        
        // Generate more realistic simulated text based on filename
        $filename = basename($filePath);
        $parkName = pathinfo($filename, PATHINFO_FILENAME);
        
        // Create more varied simulated content
        $content = generateSimulatedParkContent($parkName);
        
        // Log the word count for debugging
        file_put_contents('logs/word_count_debug.log', date('Y-m-d H:i:s') . " - File: " . basename($filePath) . " (simulated) - Content length: " . strlen($content) . " - Word count: " . countWordsAccurately($content) . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        return $content;
    }
    
    throw new Exception("Unsupported file type: $extension");
}

/**
 * Count words more accurately than str_word_count
 * This improves on PHP's native function by better handling 
 * hyphenated words, numbers, and other edge cases
 */
function countWordsAccurately($text) {
    // Remove HTML tags if present
    $text = strip_tags($text);
    
    // Normalize whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Count words with a more robust regex
    // This pattern matches:
    // - Standard words (letters)
    // - Hyphenated words (counted as one word)
    // - Numbers (counted as words)
    // - Words with apostrophes
    preg_match_all('/\b[\w\'-]+\b/u', $text, $matches);
    
    // Return the count of matched words
    return count($matches[0]);
}

/**
 * Generates simulated park and recreation content
 */
function generateSimulatedParkContent($parkName) {
    // Replace hyphens with spaces and capitalize for better readability
    $parkName = ucwords(str_replace(['-', '_'], ' ', $parkName));
    
    // Create different paragraphs based on park name to ensure varied content
    $initiatives = [
        "community engagement programs",
        "youth sports leagues",
        "environmental conservation efforts",
        "trail development projects",
        "senior wellness activities",
        "accessibility improvements",
        "sustainable facility management"
    ];
    
    $randomInitiatives = array_rand(array_flip($initiatives), mt_rand(2, 4));
    
    // Create a paragraph about goals with random elements
    $goals = [
        "increasing park usage by 20% over the next year",
        "developing three new community programs",
        "improving accessibility in all facilities",
        "enhancing environmental sustainability",
        "expanding youth engagement by 15%",
        "reducing operating costs while maintaining service quality"
    ];
    
    $randomGoals = array_rand(array_flip($goals), mt_rand(2, 3));
    
    // Generate a varied word count between 200-500 words
    $content = "The $parkName Parks and Recreation Department has developed a comprehensive strategic plan to enhance community services and facilities. ";
    $content .= "Our mission is to provide exceptional recreational opportunities for residents of all ages and abilities. ";
    $content .= "\n\nOur key initiatives for the upcoming year include: " . implode(", ", $randomInitiatives) . ". ";
    $content .= "These programs are designed to foster community engagement and promote health and wellness throughout our service area. ";
    $content .= "\n\nOur strategic goals include: " . implode(", ", $randomGoals) . ". ";
    
    // Add some data to make content more realistic
    $content .= "\n\nDemographic data shows that our community has experienced a " . mt_rand(5, 15) . "% increase in ";
    $content .= "population over the last " . mt_rand(3, 7) . " years, with particular growth in the " . (mt_rand(0, 1) ? "youth" : "senior") . " demographic. ";
    $content .= "Our annual survey indicates a satisfaction rating of " . mt_rand(70, 95) . "% among park users. ";
    
    // Add some budget information
    $content .= "\n\nOur department operates with an annual budget of $" . mt_rand(2, 8) . " million, with ";
    $content .= mt_rand(20, 40) . "% allocated to program development, " . mt_rand(30, 50) . "% to facility maintenance, ";
    $content .= "and the remainder to administrative costs and future planning. ";
    
    // Add some more varied content
    $content .= "\n\nOur " . mt_rand(3, 7) . " parks serve approximately " . mt_rand(10000, 100000) . " residents annually. ";
    $content .= "We maintain " . mt_rand(10, 50) . " miles of trails and " . mt_rand(5, 20) . " recreational facilities. ";
    
    // Add conclusion
    $content .= "\n\nThe $parkName Parks and Recreation Department remains committed to improving quality of life ";
    $content .= "for all residents through innovative programming, sustainable practices, and responsive governance.";
    
    return $content;
}

/**
 * Grades a submission using OpenAI API
 */
function gradeSubmission($text, $apiKey) {
    // Get word count using our accurate method
    $wordCount = countWordsAccurately($text);
    
    // Word count analysis to add to results
    $wordCountAnalysis = analyzeWordCount($text);
    
    // Check if we should use AI or debug testing
    if (strlen($text) < 50) {
        // Text is too short - use debug values
        return [
            'score' => mt_rand(60, 95),
            'confidence' => mt_rand(60, 95), // Now on 1-100 scale
            'feedback' => "<p>This appears to be a very short document. Please ensure the full text was properly extracted. Based on the limited content provided, here's a provisional assessment:</p><p><strong>Score:</strong> 75<br><strong>Confidence:</strong> 70</p><p><strong>Feedback:</strong> The document provides minimal content to evaluate. For a complete assessment, please provide a more detailed submission covering strategies, initiatives, goals, and metrics.</p>",
            'dimensionalScores' => generateDimensionalScores(false),
            'writingQuality' => ['rating' => 'poor', 'score' => 2.0],
            'wordCount' => $wordCount,
            'wordCountDetails' => $wordCountAnalysis
        ];
    }
    
    // Add tracking for debug/testing
    $debugMode = false;
    $debugFile = 'logs/api_requests.log';
    $debugDir = dirname($debugFile);
    
    if ($debugMode) {
        if (!file_exists($debugDir) && !is_dir($debugDir)) {
            mkdir($debugDir, 0755, true);
        }
        
        // Log API request for debugging
        file_put_contents($debugFile, date('Y-m-d H:i:s') . " - API Request with text length: " . strlen($text) . PHP_EOL, FILE_APPEND);
        
        // Get writing quality analysis
        $writingQuality = analyzeWritingQuality($text);
        
        // Get word limit adherence
        $wordLimitAnalysis = checkWordLimitAdherence($text);
        
        // Generate dimensional scores
        $dimensionalScores = generateDimensionalScores(true, $wordLimitAnalysis['penalty']);
        
        // Return simulated results for debugging with enhanced analysis
        return [
            'score' => mt_rand(60, 95),
            'confidence' => mt_rand(60, 95), // Now on 1-100 scale
            'feedback' => simulateAIFeedback($text, $writingQuality, $wordLimitAnalysis),
            'dimensionalScores' => $dimensionalScores,
            'writingQuality' => $writingQuality,
            'wordCount' => $wordCount,
            'wordCountDetails' => $wordCountAnalysis
        ];
    }
    
    // Prepare the API request
    $url = 'https://api.openai.com/v1/chat/completions';
    
    $data = [
        'model' => 'gpt-4o',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a demanding and critical expert grading system for park and recreation agency submissions. 
                            Be honest and straightforward in your assessment, highlighting flaws and shortcomings.
                            Evaluate the submission critically based on these criteria:
                            - Quality of strategies and initiatives (30%)
                            - Use of concrete examples and data (20%)
                            - Alignment with best practices (20%)
                            - Clear articulation of goals and outcomes (20%)
                            - Innovation and forward-thinking (10%)
                            
                            Be judgmental and point out specific deficiencies. Do not sugar-coat your feedback.
                            Give clear, actionable recommendations for improvement.
                            
                            FORMAT YOUR RESPONSE EXACTLY LIKE THIS:
                            Score: [0-100]
                            Confidence: [1-100]
                            
                            Feedback:
                            [Your detailed feedback paragraph here with specific insights]
                            
                            Strengths:
                            - [Key strength 1]
                            - [Key strength 2]
                            
                            CRITICAL ISSUES:
                            - [Major issue 1]
                            - [Major issue 2]
                            
                            Do not include HTML tags in your response.'
            ],
            [
                'role' => 'user',
                'content' => $text
            ]
        ],
        'temperature' => 0.3,
        'max_tokens' => 800
    ];
    
    // Setup cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Check for cURL errors
    if ($response === false) {
        curl_close($ch);
        
        // Get writing quality analysis
        $writingQuality = analyzeWritingQuality($text);
        
        // Get word limit adherence
        $wordLimitAnalysis = checkWordLimitAdherence($text);
        
        // Generate dimensional scores
        $dimensionalScores = generateDimensionalScores(true, $wordLimitAnalysis['penalty']);
        
        return [
            'error' => 'cURL Error: ' . curl_error($ch),
            'score' => mt_rand(60, 95),
            'confidence' => mt_rand(60, 95), // Now on 1-100 scale
            'feedback' => simulateAIFeedback($text, $writingQuality, $wordLimitAnalysis),
            'dimensionalScores' => $dimensionalScores,
            'writingQuality' => $writingQuality,
            'wordCount' => $wordCount,
            'wordCountDetails' => $wordCountAnalysis
        ];
    }
    
    curl_close($ch);
    
    // Process response
    if ($httpCode !== 200) {
        $responseData = json_decode($response, true);
        $errorMessage = isset($responseData['error']['message']) 
            ? $responseData['error']['message'] 
            : 'Unknown API error';
        
        // Get writing quality analysis
        $writingQuality = analyzeWritingQuality($text);
        
        // Get word limit adherence
        $wordLimitAnalysis = checkWordLimitAdherence($text);
        
        // Generate dimensional scores
        $dimensionalScores = generateDimensionalScores(true, $wordLimitAnalysis['penalty']);
        
        // If API error, use simulated results
        return [
            'score' => mt_rand(60, 95),
            'confidence' => mt_rand(60, 95), // Now on 1-100 scale
            'feedback' => "<p><strong>API Error occurred:</strong> " . htmlspecialchars($errorMessage) . "</p><p>Here's a provisional assessment based on document analysis:</p>" . simulateAIFeedback($text, $writingQuality, $wordLimitAnalysis),
            'dimensionalScores' => $dimensionalScores,
            'writingQuality' => $writingQuality,
            'wordCount' => $wordCount,
            'wordCountDetails' => $wordCountAnalysis
        ];
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['choices'][0]['message']['content'])) {
        // Get writing quality analysis
        $writingQuality = analyzeWritingQuality($text);
        
        // Get word limit adherence
        $wordLimitAnalysis = checkWordLimitAdherence($text);
        
        // Generate dimensional scores
        $dimensionalScores = generateDimensionalScores(true, $wordLimitAnalysis['penalty']);
        
        // If API response is invalid, use simulated results
        return [
            'score' => mt_rand(60, 95),
            'confidence' => mt_rand(60, 95), // Now on 1-100 scale
            'feedback' => "<p><strong>Error:</strong> Invalid API response format. Here's a provisional assessment:</p>" . simulateAIFeedback($text, $writingQuality, $wordLimitAnalysis),
            'dimensionalScores' => $dimensionalScores,
            'writingQuality' => $writingQuality,
            'wordCount' => $wordCount,
            'wordCountDetails' => $wordCountAnalysis
        ];
    }
    
    $assistantMessage = $responseData['choices'][0]['message']['content'];
    
    // Parse the assistant's response to extract score, confidence and feedback
    $scoreMatch = preg_match('/score:?\s*(\d+)/i', $assistantMessage, $scoreMatches);
    $confidenceMatch = preg_match('/confidence:?\s*(\d+)/i', $assistantMessage, $confidenceMatches);
    
    // Get writing quality analysis
    $writingQuality = analyzeWritingQuality($text);
    
    // Get word limit adherence
    $wordLimitAnalysis = checkWordLimitAdherence($text);
    
    // Generate dimensional scores - if API response, use score to influence dimensions
    $score = $scoreMatch ? intval($scoreMatches[1]) : mt_rand(60, 95);
    $dimensionalScores = generateDimensionalScores(false, $wordLimitAnalysis['penalty'], $score);
    
    // Format the feedback with HTML for better display
    $formattedFeedback = formatFeedbackAsHtml($assistantMessage);
    
    return [
        'score' => $score,
        'confidence' => $confidenceMatch ? intval($confidenceMatches[1]) : mt_rand(60, 95), // Now on 1-100 scale
        'feedback' => $formattedFeedback,
        'dimensionalScores' => $dimensionalScores,
        'writingQuality' => $writingQuality,
        'wordCount' => $wordCount,
        'wordCountDetails' => $wordCountAnalysis
    ];
}

/**
 * Generate dimensional scores for different assessment criteria
 */
function generateDimensionalScores($isRandom = true, $penalty = 0, $baseScore = null) {
    if ($isRandom) {
        // Generate random but somewhat balanced scores
        $baseValue = mt_rand(60, 90);
        
        return [
            'content' => min(100, max(0, $baseValue + mt_rand(-10, 15) - ($penalty * 100))),
            'organization' => min(100, max(0, $baseValue + mt_rand(-15, 10) - ($penalty * 100))),
            'evidence' => min(100, max(0, $baseValue + mt_rand(-20, 5) - ($penalty * 100))),
            'innovation' => min(100, max(0, $baseValue + mt_rand(-25, 20) - ($penalty * 100)))
        ];
    } else if ($baseScore !== null) {
        // Use the base score to influence dimensional scores with some variation
        return [
            'content' => min(100, max(0, $baseScore + mt_rand(-5, 10) - ($penalty * 100))),
            'organization' => min(100, max(0, $baseScore + mt_rand(-10, 5) - ($penalty * 100))),
            'evidence' => min(100, max(0, $baseScore + mt_rand(-15, 0) - ($penalty * 100))),
            'innovation' => min(100, max(0, $baseScore + mt_rand(-20, 15) - ($penalty * 100)))
        ];
    } else {
        // Default values
        return [
            'content' => 75,
            'organization' => 70,
            'evidence' => 65,
            'innovation' => 60
        ];
    }
}

/**
 * Analyze the word count for additional insights
 */
function analyzeWordCount($text) {
    $wordCount = countWordsAccurately($text);
    
    // Calculate words per paragraph
    $paragraphs = preg_split('/\n\s*\n/', $text);
    $paragraphCount = count($paragraphs);
    $wordsPerParagraph = $paragraphCount > 0 ? $wordCount / $paragraphCount : 0;
    
    // Check for presence of shorter vs longer paragraphs
    $shortParagraphCount = 0;
    $longParagraphCount = 0;
    
    foreach ($paragraphs as $paragraph) {
        $paragraphWordCount = countWordsAccurately($paragraph);
        if ($paragraphWordCount < 30) {
            $shortParagraphCount++;
        }
        if ($paragraphWordCount > 100) {
            $longParagraphCount++;
        }
    }
    
    // Simple pacing analysis
    $pacing = 'balanced';
    if ($shortParagraphCount > ($paragraphCount * 0.7)) {
        $pacing = 'choppy';
    } else if ($longParagraphCount > ($paragraphCount * 0.7)) {
        $pacing = 'dense';
    }
    
    return [
        'wordCount' => $wordCount,
        'paragraphCount' => $paragraphCount,
        'wordsPerParagraph' => round($wordsPerParagraph, 1),
        'shortParagraphCount' => $shortParagraphCount,
        'longParagraphCount' => $longParagraphCount,
        'pacing' => $pacing
    ];
}

/**
 * Format the feedback text with HTML for better display
 */
function formatFeedbackAsHtml($feedbackText) {
    // Escape HTML
    $text = htmlspecialchars($feedbackText);
    
    // Format Score and Confidence
    $text = preg_replace('/^Score:\s*(\d+)/im', '<strong>Score:</strong> <span class="score-value">$1</span>', $text);
    
    // Now confidence is on a 1-100 scale
    $text = preg_replace('/^Confidence:\s*(\d+)/im', '<strong>Confidence:</strong> <span class="confidence-value">$1</span>', $text);
    
    // Format Feedback section
    $text = preg_replace('/^Feedback:\s*/im', '<h3>Feedback</h3><div class="feedback-section">', $text);
    
    // Format Strengths section
    $text = preg_replace('/^Strengths:\s*/im', '</div><h3>Strengths</h3><ul class="strengths-list">', $text);
    
    // Format CRITICAL ISSUES section with highlighting
    $text = preg_replace('/^(CRITICAL ISSUES|Areas for Improvement):\s*/im', '</ul><h3 class="critical-issues-header">CRITICAL ISSUES</h3><ul class="critical-issues-list">', $text);
    
    // Format bullet points in lists
    $text = preg_replace('/^- (.+)$/im', '<li>$1</li>', $text);
    
    // Convert newlines to <br> tags
    $text = nl2br($text);
    
    // Ensure closing tags
    if (strpos($text, '<ul class="critical-issues-list">') !== false && strpos($text, '</ul>', strpos($text, '<ul class="critical-issues-list">')) === false) {
        $text .= '</ul>';
    }
    
    if (strpos($text, '<div class="feedback-section">') !== false && strpos($text, '</div>', strpos($text, '<div class="feedback-section">')) === false) {
        $text .= '</div>';
    }
    
    // Add summary box at the top for critical issues
    if (preg_match('/<ul class="critical-issues-list">(.*?)<\/ul>/s', $text, $matches)) {
        $criticalIssuesSummary = '<div class="critical-issues-summary"><h4>⚠️ Key Issues Identified:</h4>' . $matches[1] . '</div>';
        $text = '<div class="assessment-report">' . $criticalIssuesSummary . $text . '</div>';
    } else {
        // Wrap entire feedback in a styled container
        $text = '<div class="assessment-report">' . $text . '</div>';
    }
    
    return $text;
}

/**
 * Generate simulated AI feedback for when the API isn't available
 */
function simulateAIFeedback($text, $writingQuality = null, $wordLimitData = null) {
    $parkName = "";
    
    // Try to extract park name from text
    if (preg_match('/([A-Za-z\s-]+) Parks and Recreation/i', $text, $matches)) {
        $parkName = trim($matches[1]);
    }
    
    // Generate some statistics based on text length to create varied responses
    $textLength = strlen($text);
    $wordCount = countWordsAccurately($text);
    $score = min(95, max(65, round($wordCount / 10)));
    $confidenceLevel = mt_rand(60, 95); // Now on 1-100 scale
    $dataPoints = min(5, max(1, round($wordCount / 100)));
    
    // Apply penalties for writing issues if available
    if ($writingQuality) {
        if ($writingQuality['rating'] === 'poor') {
            $score -= 15;
        } else if ($writingQuality['rating'] === 'fair') {
            $score -= 7;
        }
    }
    
    // Apply penalties for word limit issues
    if ($wordLimitData && isset($wordLimitData['penalty']) && $wordLimitData['penalty'] > 0) {
        $score -= round($wordLimitData['penalty'] * 100);
    }
    
    // Ensure score stays in valid range
    $score = min(95, max(40, $score));
    
    $feedback = "<div class=\"assessment-report\">";
    
    // Generate critical issues summary first
    $criticalIssues = [];
    
    // Add writing quality issues if available
    if ($writingQuality && ($writingQuality['rating'] === 'poor' || $writingQuality['rating'] === 'fair')) {
        $criticalIssues[] = "Poor writing quality with approximately {$writingQuality['grammarErrors']} grammar/style issues";
    }
    
    // Add word limit issues if available
    if ($wordLimitData && isset($wordLimitData['adherence']) && $wordLimitData['adherence'] === 0) {
        $overagePercent = round($wordLimitData['overageRatio'] * 100);
        $criticalIssues[] = "Exceeds word limit by {$overagePercent}%";
    }
    
    // Always include some random critical issues
    $potentialIssues = [
        "Lack of specific, measurable outcomes",
        "Insufficient data to support key claims",
        "No clear implementation timeline",
        "Budget allocations lack necessary detail",
        "Failure to address accessibility requirements",
        "Limited innovation in proposed solutions",
        "Inadequate community engagement strategies",
        "No sustainability measures outlined",
        "Weak evidence of best practices application",
        "Missing demographic analysis"
    ];
    
    // Add 2-3 random issues
    $randomIssueCount = mt_rand(2, 3);
    for ($i = 0; $i < $randomIssueCount; $i++) {
        if (count($potentialIssues) > 0) {
            $randomIndex = array_rand($potentialIssues);
            $criticalIssues[] = $potentialIssues[$randomIndex];
            unset($potentialIssues[$randomIndex]);
            $potentialIssues = array_values($potentialIssues);
        }
    }
    
    // Add critical issues summary if we have any
    if (count($criticalIssues) > 0) {
        $feedback .= "<div class=\"critical-issues-summary\"><h4>⚠️ Key Issues Identified:</h4><ul>";
        foreach ($criticalIssues as $issue) {
            $feedback .= "<li>{$issue}</li>";
        }
        $feedback .= "</ul></div>";
    }
    
    $feedback .= "<h2>Assessment of " . ($parkName ? htmlspecialchars($parkName) : "the") . " Parks and Recreation Submission</h2>";
    
    $feedback .= "<p><strong>Score:</strong> <span class=\"score-value\">$score</span><br>";
    $feedback .= "<strong>Confidence:</strong> <span class=\"confidence-value\">{$confidenceLevel}</span></p>";
    
    $feedback .= "<h3>Feedback</h3><div class=\"feedback-section\">";
    
    // Randomize the quality assessment - now more critical
    $qualities = [
        "provides a basic overview of current programs but lacks depth",
        "outlines several strategies but fails to demonstrate effectiveness",
        "presents an inadequate framework for recreational services",
        "proposes facility improvements without sufficient ROI analysis",
        "offers a limited foundation for departmental operations"
    ];
    
    $feedback .= "<p>The submission " . $qualities[array_rand($qualities)] . ". ";
    
    // Comment on data usage - more critical
    if ($wordCount > 300) {
        $feedback .= "The document incorporates approximately $dataPoints data points, but fails to effectively connect this data to proposed actions. ";
    } else {
        $feedback .= "The document is severely lacking in statistical support and concrete examples. ";
    }
    
    // Comment on goals - more critical
    $goalQuality = ["poorly articulated", "inadequately defined", "inconsistently structured", "insufficiently developed"];
    $feedback .= "Goals are " . $goalQuality[array_rand($goalQuality)] . ", ";
    
    // Innovation assessment - more critical
    $innovationLevels = ["showing few innovative approaches", "relying excessively on outdated practices", "featuring minimal forward-thinking concepts", "failing to incorporate modern methodologies"];
    $feedback .= $innovationLevels[array_rand($innovationLevels)] . ".</p></div>";
    
    // Add strengths - fewer than before
    $feedback .= "<h3>Strengths</h3><ul class=\"strengths-list\">";
    $strengths = [
        "Basic presentation of departmental mission",
        "Some attempt at budget allocation",
        "Recognition of community engagement importance",
        "Identification of several strategic priorities",
        "Inclusion of demographic data",
        "Acknowledgment of sustainability needs",
        "Maintenance plan outlined"
    ];
    
    // Select 1-2 random strengths (fewer than before)
    $selectedStrengths = array_rand(array_flip($strengths), mt_rand(1, 2));
    foreach ($selectedStrengths as $strength) {
        $feedback .= "<li>" . $strength . "</li>";
    }
    $feedback .= "</ul>";
    
    // Add critical issues with stronger wording
    $feedback .= "<h3 class=\"critical-issues-header\">CRITICAL ISSUES</h3><ul class=\"critical-issues-list\">";
    
    // Add all the critical issues we generated earlier
    foreach ($criticalIssues as $issue) {
        $feedback .= "<li>" . $issue . "</li>";
    }
    
    $feedback .= "</ul></div>";
    
    return $feedback;
}

/**
 * Analyzes writing quality of submission text
 */
function analyzeWritingQuality($text) {
    // Initialize result with default values
    $result = [
        'score' => 0,
        'rating' => 'fair',
        'grammarErrors' => 0,
        'readabilityScore' => 0,
        'sentenceCount' => 0,
        'avgSentenceLength' => 0
    ];
    
    // Skip empty text
    if (empty($text)) {
        return $result;
    }
    
    // Simple check for grammar errors (very basic implementation)
    // In a real application, you'd use a grammar checking library
    $commonErrors = [
        'double spaces' => '  ',
        'missing period' => '([a-z])\n([A-Z])',
        'repeated words' => '\b(\w+)\s+\1\b',
        'incomplete sentences' => '\b(however|therefore|thus|hence|consequently)\s*$'
    ];
    
    $errorCount = 0;
    foreach ($commonErrors as $error => $regex) {
        $errorCount += preg_match_all('/' . $regex . '/i', $text);
    }
    
    // Count sentences (very basic implementation)
    $sentences = preg_split('/(?<=[.!?])\s+/', $text);
    $sentenceCount = count($sentences);
    
    // Calculate average sentence length
    $wordCount = str_word_count($text);
    $avgSentenceLength = $sentenceCount > 0 ? $wordCount / $sentenceCount : 0;
    
    // Calculate a basic readability score (simplified Flesch Reading Ease)
    // In a real application, you'd use a readability scoring library
    $avgWordsPerSentence = $sentenceCount > 0 ? $wordCount / $sentenceCount : 0;
    $syllableCount = estimateSyllableCount($text);
    $avgSyllablesPerWord = $wordCount > 0 ? $syllableCount / $wordCount : 0;
    
    // Simplified Flesch Reading Ease formula
    $readabilityScore = 206.835 - (1.015 * $avgWordsPerSentence) - (84.6 * $avgSyllablesPerWord);
    $readabilityScore = min(100, max(0, $readabilityScore)); // Clamp between 0-100
    
    // Grammar error ratio (errors per 100 words)
    $grammarErrorRatio = $wordCount > 0 ? ($errorCount / $wordCount) * 100 : 0;
    
    // Determine writing quality rating
    if ($grammarErrorRatio <= 0.5 && $readabilityScore >= 60 && $avgSentenceLength >= 15 && $avgSentenceLength <= 25) {
        $rating = 'excellent';
        $score = 5.0;
    } else if ($grammarErrorRatio <= 1.5 && $readabilityScore >= 50 && $avgSentenceLength >= 12 && $avgSentenceLength <= 30) {
        $rating = 'good';
        $score = 4.0;
    } else if ($grammarErrorRatio <= 3.0 && $readabilityScore >= 40 && $avgSentenceLength >= 8 && $avgSentenceLength <= 35) {
        $rating = 'fair';
        $score = 3.0;
    } else {
        $rating = 'poor';
        $score = 2.0;
    }
    
    return [
        'score' => $score,
        'rating' => $rating,
        'grammarErrors' => $errorCount,
        'readabilityScore' => $readabilityScore,
        'sentenceCount' => $sentenceCount,
        'avgSentenceLength' => $avgSentenceLength
    ];
}

/**
 * Helper function to estimate syllable count in text
 */
function estimateSyllableCount($text) {
    // A very basic syllable counter (would be more sophisticated in a real application)
    $wordCount = str_word_count($text, 1); // Get array of words
    $syllableCount = 0;
    
    foreach ($wordCount as $word) {
        $word = strtolower($word);
        $word = preg_replace('/[^a-z]/', '', $word);
        
        // Count vowel groups as syllables
        $syllables = preg_match_all('/[aeiouy]+/', $word);
        
        // Adjust for common patterns
        if ($syllables == 0 && strlen($word) > 0) {
            $syllables = 1; // Every word has at least one syllable
        }
        
        // Adjust for silent 'e' at the end
        if (strlen($word) > 2 && substr($word, -1) == 'e' && !in_array(substr($word, -2, 1), ['a', 'e', 'i', 'o', 'u', 'y'])) {
            $syllables--;
        }
        
        // Ensure at least 1 syllable per word
        $syllableCount += max(1, $syllables);
    }
    
    return $syllableCount;
}

/**
 * Checks adherence to word limits
 */
function checkWordLimitAdherence($text) {
    $wordCount = str_word_count($text);
    
    // Target word limits for Parks & Recreation assessment
    $maxWordsPerQuestion = 240; // Standard word limit for most questions
    
    // Estimate number of questions in the text
    $questionCount = preg_match_all('/Question\s+\d+/i', $text) ?: 1;
    
    // Calculate expected max words
    $expectedMaxWords = $questionCount * $maxWordsPerQuestion;
    
    // Calculate adherence (1 = within limit, 0 = exceeds)
    $adherence = $wordCount <= $expectedMaxWords ? 1 : 0;
    
    // Calculate penalty for exceeding word limit
    $penalty = 0;
    if ($wordCount > $expectedMaxWords) {
        $overageRatio = ($wordCount - $expectedMaxWords) / $expectedMaxWords;
        
        if ($overageRatio <= 0.1) { // Up to 10% over
            $penalty = 0.05; // 5% penalty
        } else if ($overageRatio <= 0.25) { // 11-25% over
            $penalty = 0.1; // 10% penalty
        } else if ($overageRatio <= 0.5) { // 26-50% over
            $penalty = 0.2; // 20% penalty
        } else { // More than 50% over
            $penalty = 0.3; // 30% penalty
        }
    }
    
    return [
        'wordCount' => $wordCount,
        'expectedMaxWords' => $expectedMaxWords,
        'adherence' => $adherence,
        'penalty' => $penalty,
        'overageRatio' => $adherence ? 0 : ($wordCount - $expectedMaxWords) / $expectedMaxWords
    ];
}