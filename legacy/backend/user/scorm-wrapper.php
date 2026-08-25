<?php
/**
 * SCORM Content Wrapper
 *
 * This wrapper page is served from the same origin as the API,
 * allowing SCORM content to access the LMS API via postMessage.
 *
 * Usage: scorm-wrapper.php?content_url=path/to/scorm/index.html&enrollment_id=123&lesson_id=456
 */

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: ALLOWALL');

$contentUrl = $_GET['content_url'] ?? '';
$enrollmentId = $_GET['enrollment_id'] ?? 0;
$lessonId = $_GET['lesson_id'] ?? 0;
$userId = $_GET['user_id'] ?? 0;
$userName = $_GET['user_name'] ?? '';

// Validate content URL
if (empty($contentUrl)) {
    http_response_code(400);
    echo 'Missing content_url parameter';
    exit;
}

// Build full URL if relativecentral_lxp
if (!preg_match('/^https?:\/\//', $contentUrl)) {
    $baseUrl = dirname($_SERVER['REQUEST_URI']);
    $contentUrl = $baseUrl . '/' . ltrim($contentUrl, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCORM Content</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; height: 100%; overflow: hidden; }
        #scorm-frame { width: 100%; height: 100%; border: none; }
        .loading {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            font-family: Arial, sans-serif;
        }
        .loading.hidden { display: none; }
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #c9a227;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div id="loading" class="loading">
    <div class="spinner"></div>
    <p>Loading SCORM content...</p>
</div>

<iframe id="scorm-frame" src="" style="display:none;"></iframe>

<script>
    (function() {
        'use strict';

        // Configuration from PHP
        const config = {
            contentUrl: <?php echo json_encode($contentUrl); ?>,
            enrollmentId: <?php echo (int)$enrollmentId; ?>,
            lessonId: <?php echo (int)$lessonId; ?>,
            userId: <?php echo json_encode((string)$userId); ?>,
            userName: <?php echo json_encode($userName); ?>
        };

        console.log('[SCORM Wrapper] Config:', config);

        // SCORM Data Store
        const cmiData = {
            'cmi.core.lesson_status': 'not attempted',
            'cmi.core.lesson_location': '',
            'cmi.core.score.raw': '',
            'cmi.core.score.min': '0',
            'cmi.core.score.max': '100',
            'cmi.core.session_time': '0000:00:00',
            'cmi.core.total_time': '0000:00:00',
            'cmi.suspend_data': '',
            'cmi.core.exit': '',
            'cmi.core.entry': 'ab-initio',
            'cmi.core.credit': 'credit',
            'cmi.core.lesson_mode': 'normal',
            'cmi.student_id': config.userId,
            'cmi.student_name': config.userName
        };

        let isDirty = false;
        let isInitialized = false;
        const parentOrigin = '*'; // Accept from any origin, we validate via tokens

        // Send message to parent window
        function sendToParent(type, data) {
            if (window.parent !== window) {
                window.parent.postMessage({ type, ...data }, parentOrigin);
            }
        }

        // Commit data to server
        function commitData() {
            if (!isDirty) return;

            sendToParent('scorm_commit', {
                enrollmentId: config.enrollmentId,
                lessonId: config.lessonId,
                cmiData: cmiData
            });

            isDirty = false;
        }

        // SCORM 1.2 API
        window.API = {
            LMSInitialize: function(param) {
                console.log('[SCORM 1.2] LMSInitialize');
                isInitialized = true;
                sendToParent('scorm_initialize', { lessonId: config.lessonId });
                return 'true';
            },

            LMSFinish: function(param) {
                console.log('[SCORM 1.2] LMSFinish');
                commitData();
                sendToParent('scorm_finish', {
                    lessonId: config.lessonId,
                    status: cmiData['cmi.core.lesson_status'],
                    score: cmiData['cmi.core.score.raw'],
                    cmiData: cmiData
                });
                isInitialized = false;
                return 'true';
            },

            LMSGetValue: function(element) {
                console.log('[SCORM 1.2] LMSGetValue:', element);

                if (element === 'cmi.core._children') {
                    return 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,exit,session_time,lesson_mode';
                }
                if (element === 'cmi.core.score._children') {
                    return 'raw,min,max';
                }

                return cmiData[element] !== undefined ? String(cmiData[element]) : '';
            },

            LMSSetValue: function(element, value) {
                console.log('[SCORM 1.2] LMSSetValue:', element, '=', value);

                const readOnly = ['cmi.core._children', 'cmi.student_id', 'cmi.student_name',
                    'cmi.core.credit', 'cmi.core.entry', 'cmi.core.lesson_mode', 'cmi.core.total_time'];
                if (readOnly.includes(element)) {
                    return 'false';
                }

                cmiData[element] = value;
                isDirty = true;

                // Notify parent of status change
                if (element === 'cmi.core.lesson_status') {
                    sendToParent('scorm_status_change', {
                        status: value,
                        score: cmiData['cmi.core.score.raw'],
                        lessonId: config.lessonId
                    });
                }

                return 'true';
            },

            LMSCommit: function(param) {
                console.log('[SCORM 1.2] LMSCommit');
                commitData();
                return 'true';
            },

            LMSGetLastError: function() { return '0'; },
            LMSGetErrorString: function(code) { return 'No Error'; },
            LMSGetDiagnostic: function(code) { return 'No Error'; }
        };

        // SCORM 2004 API
        window.API_1484_11 = {
            Initialize: function(param) {
                console.log('[SCORM 2004] Initialize');
                return window.API.LMSInitialize(param);
            },
            Terminate: function(param) {
                console.log('[SCORM 2004] Terminate');
                return window.API.LMSFinish(param);
            },
            GetValue: function(element) {
                // Map 2004 to 1.2
                const map = {
                    'cmi.completion_status': 'cmi.core.lesson_status',
                    'cmi.success_status': 'cmi.core.lesson_status',
                    'cmi.location': 'cmi.core.lesson_location',
                    'cmi.score.raw': 'cmi.core.score.raw',
                    'cmi.score.min': 'cmi.core.score.min',
                    'cmi.score.max': 'cmi.core.score.max',
                    'cmi.session_time': 'cmi.core.session_time',
                    'cmi.total_time': 'cmi.core.total_time',
                    'cmi.exit': 'cmi.core.exit',
                    'cmi.entry': 'cmi.core.entry',
                    'cmi.learner_id': 'cmi.student_id',
                    'cmi.learner_name': 'cmi.student_name'
                };
                return window.API.LMSGetValue(map[element] || element);
            },
            SetValue: function(element, value) {
                const map = {
                    'cmi.completion_status': 'cmi.core.lesson_status',
                    'cmi.success_status': 'cmi.core.lesson_status',
                    'cmi.location': 'cmi.core.lesson_location',
                    'cmi.score.raw': 'cmi.core.score.raw',
                    'cmi.score.min': 'cmi.core.score.min',
                    'cmi.score.max': 'cmi.core.score.max',
                    'cmi.session_time': 'cmi.core.session_time',
                    'cmi.exit': 'cmi.core.exit'
                };
                return window.API.LMSSetValue(map[element] || element, value);
            },
            Commit: function(param) { return window.API.LMSCommit(param); },
            GetLastError: function() { return '0'; },
            GetErrorString: function(code) { return 'No Error'; },
            GetDiagnostic: function(code) { return 'No Error'; }
        };

        // Listen for messages from parent
        window.addEventListener('message', function(event) {
            const data = event.data;

            if (data.type === 'scorm_load_data') {
                // Parent is sending existing SCORM data
                console.log('[SCORM Wrapper] Received existing data:', data.cmiData);
                Object.assign(cmiData, data.cmiData);
                if (cmiData['cmi.core.lesson_location']) {
                    cmiData['cmi.core.entry'] = 'resume';
                }
            }
        });

        // Auto-commit periodically
        setInterval(function() {
            if (isDirty) {
                commitData();
            }
        }, 30000);

        // Commit on unload
        window.addEventListener('beforeunload', function() {
            commitData();
        });

        // Load SCORM content after API is ready
        console.log('[SCORM Wrapper] API ready, loading content:', config.contentUrl);

        const frame = document.getElementById('scorm-frame');
        const loading = document.getElementById('loading');

        frame.onload = function() {
            loading.classList.add('hidden');
            frame.style.display = 'block';
            console.log('[SCORM Wrapper] Content loaded');
        };

        frame.onerror = function() {
            loading.innerHTML = '<p style="color:red;">Failed to load SCORM content</p>';
        };

        // Small delay to ensure API is exposed before content loads
        setTimeout(function() {
            frame.src = config.contentUrl;
        }, 50);

        // Notify parent that wrapper is ready
        sendToParent('scorm_wrapper_ready', { lessonId: config.lessonId });

        console.log('[SCORM Wrapper] Initialized, waiting for content...');
    })();
</script>
</body>
</html>