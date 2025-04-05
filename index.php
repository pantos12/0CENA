<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>0CENA | Document Assessment System</title>
    <link rel="stylesheet" href="css/styles.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <div id="page-dropzone" class="wrapper">
        <div class="background-video">
            <video autoplay muted loop playsinline>
                <source src="videos/droneCLEV.mp4" type="video/mp4">
                Your browser does not support the video tag.
            </video>
        </div>
        
        <div id="leaves-container"></div>
        
        <div class="container">
            <header>
                <div class="logo-container">
                    <img src="images/logo.png" alt="0CENA Logo" class="logo">
                </div>
                <h1>0CENA</h1>
                <p>Document Assessment System</p>
            </header>
            
            <div class="content">
                <div class="card">
                    <div class="tabs">
                        <div class="tab-header">
                            <button class="tab-btn active" data-tab="upload">Upload</button>
                            <button class="tab-btn" data-tab="results">Results</button>
                        </div>
                        
                        <div class="tab-pane active" id="upload">
                            <div class="file-dropzone">
                                <div class="dropzone-inner">
                                    <div class="upload-icon"></div>
                                    <div class="dropzone-message">
                                        Drag &amp; drop files here or 
                                        <span class="browse-link">browse</span>
                                    </div>
                                    <div class="file-info-footer">
                                        Accepted formats: .doc, .docx, .pdf, .txt
                                    </div>
                                </div>
                            </div>
                            
                            <input type="file" id="file-input" multiple accept=".doc,.docx,.pdf,.txt" class="hidden">
                            
                            <div class="file-list"></div>
                            
                            <button id="submit-files" class="btn primary-btn" disabled>
                                <span class="btn-text">Grade Submissions</span>
                                <div class="loading-spinner hidden"></div>
                            </button>
                        </div>
                        
                        <div class="tab-pane" id="results">
                            <table class="results-table">
                                <thead>
                                    <tr>
                                        <th>File Name</th>
                                        <th>Word Count</th>
                                        <th>Assessment</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody id="results-body"></tbody>
                            </table>
                            <div id="no-results">No submissions graded yet.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="feedback-modal" class="modal">
            <div class="modal-content">
                <span class="close-modal">&times;</span>
                <div id="feedback-content"></div>
            </div>
        </div>
    </div>
    
    <script src="js/main.js"></script>
</body>
</html> 