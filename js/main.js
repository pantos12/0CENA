$(document).ready(function() {
    // Initialize falling leaves animation
    initFallingLeaves();
    
    // Handle tab switching
    $('.tab-btn').on('click', function() {
        const tabId = $(this).data('tab');
        
        // Update active tab button
        $('.tab-btn').removeClass('active');
        $(this).addClass('active');
        
        // Show the selected tab content
        $('.tab-pane').removeClass('active');
        $('#' + tabId).addClass('active');
    });
    
    // File upload handling
    const fileInput = $('#file-input');
    const dropzone = $('.file-dropzone');
    const pageDropzone = $('#page-dropzone');
    const fileList = $('.file-list');
    const submitButton = $('#submit-files');
    let uploadedFiles = [];
    
    // Browse link click
    $('.browse-link').on('click', function() {
        fileInput.click();
    });
    
    // File input change
    fileInput.on('change', function(e) {
        handleFiles(e.target.files);
    });
    
    // Drag and drop events for the small dropzone
    dropzone.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('drag-over');
    });
    
    dropzone.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
    });
    
    dropzone.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
        pageDropzone.removeClass('drag-over');
        
        const dt = e.originalEvent.dataTransfer;
        if (dt.files.length) {
            handleFiles(dt.files);
        }
    });
    
    // Drag and drop events for the entire page
    pageDropzone.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('drag-over');
    });
    
    pageDropzone.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Only remove drag-over if leaving the page entirely
        // (not just moving between elements)
        const rect = this.getBoundingClientRect();
        if (
            e.originalEvent.clientX <= rect.left ||
            e.originalEvent.clientX >= rect.right ||
            e.originalEvent.clientY <= rect.top ||
            e.originalEvent.clientY >= rect.bottom
        ) {
            $(this).removeClass('drag-over');
        }
    });
    
    pageDropzone.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
        dropzone.removeClass('drag-over');
        
        const dt = e.originalEvent.dataTransfer;
        if (dt.files.length) {
            handleFiles(dt.files);
        }
    });
    
    // Handle the selected files
    function handleFiles(files) {
        // Check for acceptable file types
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const extension = file.name.split('.').pop().toLowerCase();
            
            if (['doc', 'docx', 'pdf', 'txt'].includes(extension)) {
                // Check if file already exists in the list
                if (!uploadedFiles.some(f => f.name === file.name)) {
                    uploadedFiles.push(file);
                    addFileToList(file);
                }
            } else {
                alert(`File "${file.name}" is not a valid document type. Please upload .doc, .docx, .pdf, or .txt files.`);
            }
        }
        
        // Enable submit button if files are uploaded
        updateSubmitButton();
    }
    
    // Add file to the visual list
    function addFileToList(file) {
        const fileItem = $('<div class="file-item"></div>');
        fileItem.html(`
            <span>${file.name} (${formatFileSize(file.size)})</span>
            <button type="button" class="remove-file" data-filename="${file.name}">×</button>
        `);
        
        fileList.append(fileItem);
    }
    
    // Remove file from list
    $(document).on('click', '.remove-file', function() {
        const filename = $(this).data('filename');
        uploadedFiles = uploadedFiles.filter(file => file.name !== filename);
        $(this).parent().remove();
        updateSubmitButton();
    });
    
    // Update submit button state
    function updateSubmitButton() {
        if (uploadedFiles.length > 0) {
            submitButton.prop('disabled', false);
        } else {
            submitButton.prop('disabled', true);
        }
    }
    
    // Format file size for display
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    // Handle file submission
    submitButton.on('click', function() {
        if (uploadedFiles.length === 0) {
            alert('Please select files to grade.');
            return;
        }
        
        // Start loading state
        $(this).prop('disabled', true);
        $('.btn-text').text('Processing...');
        $('.loading-spinner').removeClass('hidden');
        
        // Create FormData to send files to the server
        const formData = new FormData();
        
        uploadedFiles.forEach(file => {
            formData.append('files[]', file);
        });
        
        // Send files to PHP endpoint for processing
        $.ajax({
            url: 'process_files.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json', // Explicitly tell jQuery to expect JSON
            success: function(result) {
                // Result is already parsed as JSON by jQuery
                if (result.error) {
                    alert(result.error);
                } else {
                    // Process successful results
                    processResults(result.submissions);
                    
                    // Switch to results tab
                    $('.tab-btn[data-tab="results"]').click();
                    
                    // Reset file upload
                    uploadedFiles = [];
                    fileList.empty();
                    fileInput.val('');
                }
            },
            error: function(xhr, status, error) {
                console.error('Response:', xhr.responseText);
                alert('Error communicating with the server: ' + error);
            },
            complete: function() {
                // Reset button state
                submitButton.prop('disabled', false);
                $('.btn-text').text('Grade Submissions');
                $('.loading-spinner').addClass('hidden');
            }
        });
    });
    
    // Process and display results
    function processResults(submissions) {
        const resultsTable = $('#results-body');
        const noResults = $('#no-results');
        
        if (submissions.length > 0) {
            noResults.hide();
            resultsTable.empty(); // Clear any existing results
            
            submissions.forEach(sub => {
                // Create a new row for each submission
                const row = $('<tr></tr>');
                
                if (sub.error) {
                    row.html(`
                        <td>${sub.fileName}</td>
                        <td colspan="3" class="text-danger">${sub.error}</td>
                        <td>N/A</td>
                    `);
                } else {
                    // Check for dimensional scores
                    let dimensionalScores = '';
                    if (sub.dimensionalScores) {
                        const ds = sub.dimensionalScores;
                        dimensionalScores = `
                            <div class="score-breakdown">
                                <div class="dimension-score" title="Content quality and strategies">
                                    <span class="dimension-label">Content</span>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: ${ds.content}%">${ds.content}</div>
                                    </div>
                                </div>
                                <div class="dimension-score" title="Organization and clarity">
                                    <span class="dimension-label">Org</span>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: ${ds.organization}%">${ds.organization}</div>
                                    </div>
                                </div>
                                <div class="dimension-score" title="Evidence and data">
                                    <span class="dimension-label">Evid</span>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: ${ds.evidence}%">${ds.evidence}</div>
                                    </div>
                                </div>
                                <div class="dimension-score" title="Innovation">
                                    <span class="dimension-label">Innov</span>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: ${ds.innovation}%">${ds.innovation}</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    
                    // Check for writing quality
                    let writingQuality = '';
                    if (sub.writingQuality) {
                        const wq = sub.writingQuality;
                        const ratingClass = {
                            'excellent': 'excellent-rating',
                            'good': 'good-rating',
                            'fair': 'fair-rating',
                            'poor': 'poor-rating'
                        }[wq.rating] || '';
                        
                        writingQuality = `
                            <div class="writing-quality ${ratingClass}">
                                <span title="Writing Quality Rating">${wq.rating.charAt(0).toUpperCase() + wq.rating.slice(1)}</span>
                            </div>
                        `;
                    }
                    
                    // Format confidence - now using 1-100 scale directly
                    const confidenceValue = parseInt(sub.confidence);
                    const confidenceClass = confidenceValue >= 85 ? 'high-confidence' : (confidenceValue >= 70 ? 'medium-confidence' : 'low-confidence');
                    
                    // Check if we have word count details
                    let wordCountDetail = '';
                    if (sub.wordCountDetails && sub.wordCountDetails.pacing) {
                        wordCountDetail = ` <span title="Text has ${sub.wordCountDetails.paragraphCount} paragraphs, avg ${sub.wordCountDetails.wordsPerParagraph} words per paragraph">(${sub.wordCountDetails.pacing})</span>`;
                    }
                    
                    row.html(`
                        <td>${sub.fileName}</td>
                        <td>${sub.wordCount || 'N/A'}${wordCountDetail}</td>
                        <td>
                            <strong class="score">${sub.score || 'N/A'}</strong>
                            <div class="confidence ${confidenceClass}" title="AI Confidence Level">
                                ${confidenceValue}%
                            </div>
                            ${dimensionalScores}
                            ${writingQuality}
                        </td>
                        <td>
                            <button class="view-feedback" data-feedback="${encodeURIComponent(sub.feedback)}">
                                View Details
                            </button>
                        </td>
                    `);
                }
                
                resultsTable.append(row);
            });
        } else {
            noResults.show();
        }
    }
    
    // Handle feedback modal
    $(document).on('click', '.view-feedback', function() {
        const feedback = decodeURIComponent($(this).data('feedback'));
        $('#feedback-content').html(feedback);
        $('#feedback-modal').css('display', 'block');
    });
    
    // Close modal
    $('.close-modal').on('click', function() {
        $('#feedback-modal').css('display', 'none');
    });
    
    // Also close modal when clicking outside of it
    $(window).on('click', function(e) {
        const modal = $('#feedback-modal');
        if (e.target === modal[0]) {
            modal.css('display', 'none');
        }
    });
    
    // Initialize falling leaves animation
    function initFallingLeaves() {
        const leavesContainer = $('#leaves-container');
        const leafImages = [
            'url("images/leaf1.png")',
            'url("images/leaf2.png")',
            'url("images/leaf3.png")'
        ];
        
        // Create leaves
        for (let i = 0; i < 15; i++) {
            createLeaf(leavesContainer, leafImages);
        }
    }
    
    // Create leaf element
    function createLeaf(container, leafImages) {
        const leaf = $('<div class="leaf"></div>');
        const randomLeaf = leafImages[Math.floor(Math.random() * leafImages.length)];
        const startPosition = Math.random() * 100; // Random horizontal position (0-100%)
        const scale = 0.5 + Math.random() * 0.5; // Random size between 0.5 and 1
        const rotationSpeed = 2 + Math.random() * 5; // Rotation speed
        const fallDuration = 10 + Math.random() * 20; // Fall duration between 10 and 30 seconds
        
        leaf.css({
            'left': startPosition + '%',
            'transform': 'scale(' + scale + ')',
            'background-image': randomLeaf,
            'animation-duration': fallDuration + 's',
            'animation-delay': Math.random() * 15 + 's'
        });
        
        container.append(leaf);
        
        // When animation ends, remove the leaf and create a new one
        leaf.on('animationend', function() {
            $(this).remove();
            createLeaf(container, leafImages);
        });
    }
}); 