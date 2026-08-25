<?php
/**
 * Media Class
 * Handles media file uploads with version control
 * Supports VIDEO, AUDIO, PDF, SCORM files
 */

class Media
{
    // User's pagination/sorting properties
    public int $page;
    public int $per_page;
    public string $sort_by;
    public string $sort_dir;
    
    private $conn;
    private $table = 'media';
    private $uploadDir;

    // Properties
    public $media_id;
    public $tenant_id;
    public $uploaded_by;
    public $media_type;
    public $title;
    public $description;
    public $original_filename;
    public $stored_filename;
    public $file_path;
    public $file_url;
    public $file_size;
    public $mime_type;
    public $extension;
    public $duration;
    public $width;
    public $height;
    public $page_count;
    public $scorm_version;
    public $launch_url;
    public $metadata_json;
    public $md5_hash;
    public $sha256_hash;
    public $root_parent_id;
    public $parent_media_id;
    public $version_number;
    public $version_label;
    public $version_notes;
    public $is_latest_version;
    public $is_active;
    public $created_at;

    // Allowed file types configuration
    private $allowedTypes = [
        'VIDEO' => [
            'extensions' => ['mp4', 'webm', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'm4v'],
            'mimes' => [
                'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo',
                'video/x-matroska', 'video/x-ms-wmv', 'video/x-flv', 'video/x-m4v'
            ],
            'max_size' => 2147483648, // 2GB
        ],
        'AUDIO' => [
            'extensions' => ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac', 'wma'],
            'mimes' => [
                'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/aac',
                'audio/x-m4a', 'audio/flac', 'audio/x-ms-wma', 'audio/mp4'
            ],
            'max_size' => 524288000, // 500MB
        ],
        'PDF' => [
            'extensions' => ['pdf'],
            'mimes' => ['application/pdf'],
            'max_size' => 104857600, // 100MB
        ],
        'SCORM' => [
            'extensions' => ['zip'],
            'mimes' => ['application/zip', 'application/x-zip-compressed'],
            'max_size' => 1073741824, // 1GB
        ],
    ];

    private $scormIndicators = ['imsmanifest.xml', 'tincan.xml', 'cmi5.xml'];

    public function __construct($db)
    {
        $this->conn = $db;

        // Upload directory - must be within open_basedir allowed paths
        // Priority: 1. Constant, 2. Environment variable, 3. Default (inside project root)
        if (defined('UPLOAD_DIR')) {
            $this->uploadDir = UPLOAD_DIR;
        } elseif (getenv('UPLOAD_DIR')) {
            $this->uploadDir = getenv('UPLOAD_DIR');
        } else {
            // Default: /www/wwwroot/api.centrallms.com/uploads
            $this->uploadDir = dirname(__DIR__) . '/uploads';
        }

        $this->ensureUploadDirectories();
    }

    /**
     * Upload media file
     */
    public function upload(array $file): array
    {
        try {
            // Validate file upload
            $this->validateUpload($file);

            // Detect media type
            $this->media_type = $this->detectMediaType($file);

            // Validate against type-specific rules
            $this->validateFileType($file);

            // Handle versioning
            $versionInfo = $this->handleVersioning();

            // Generate secure filename and path
            $fileInfo = $this->generateFilePath($file);

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $fileInfo['full_path'])) {
                throw new Exception('Failed to move uploaded file');
            }

            // Extract metadata based on type
            $extractedMeta = $this->extractMetadata($fileInfo['full_path'], $file);

            // Handle SCORM package extraction
            if ($this->media_type === 'SCORM') {
                $scormInfo = $this->processScormPackage($fileInfo['full_path'], $fileInfo['directory'], $fileInfo['scorm_dir']);
                $extractedMeta = array_merge($extractedMeta, $scormInfo);
            }

            // Set properties from extracted metadata
            $this->setPropertiesFromMetadata($fileInfo, $extractedMeta, $versionInfo);

            // Save to database
            if (!$this->create()) {
                throw new Exception('Failed to save media record');
            }

            $this->media_id = $this->conn->insert_id;

            // If this is a new version, update the parent's latest version reference
            if ($versionInfo['parent_media_id']) {
                $this->updateParentLatestVersion($versionInfo['parent_media_id']);
            }

            // Update tenant storage
            $this->updateTenantStorageAdd($this->media_type, $this->file_size);

            // Return success response
            return [
                'success' => true,
                'data' => [
                    'media_id' => $this->media_id,
                    'tenant_id' => $this->tenant_id,
                    'media_type' => $this->media_type,
                    'title' => $this->title,
                    'file_name' => $this->stored_filename,
                    'file_path' => $this->file_path,
                    'file_url' => $this->file_url,
                    'file_size' => $this->file_size,
                    'file_size_formatted' => $this->formatFileSize($this->file_size),
                    'mime_type' => $this->mime_type,
                    'version' => [
                        'version_number' => $this->version_number,
                        'version_label' => $this->version_label,
                        'is_latest' => true,
                        'parent_media_id' => $versionInfo['parent_media_id'],
                        'version_notes' => $this->version_notes
                    ],
                    'metadata' => $extractedMeta,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ]
            ];

        } catch (Exception $e) {
            // Clean up file if exists
            if (isset($fileInfo['full_path']) && file_exists($fileInfo['full_path'])) {
                unlink($fileInfo['full_path']);
            }

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create media record in database
     */
    public function create(): bool
    {
        $query = "INSERT INTO " . $this->table . " (
            tenant_id, uploaded_by, media_type, title, description,
            original_filename, stored_filename, file_path, file_url,
            file_size, mime_type, extension, duration, width, height,
            page_count, scorm_version, launch_url, metadata_json,
            md5_hash, sha256_hash,
            root_parent_id, version_number, version_label, version_notes,
            is_latest_version, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())";

        $stmt = $this->conn->prepare($query);

        $stmt->bind_param(
            "iisssssssissiiissssssisss",
            $this->tenant_id,
            $this->uploaded_by,
            $this->media_type,
            $this->title,
            $this->description,
            $this->original_filename,
            $this->stored_filename,
            $this->file_path,
            $this->file_url,
            $this->file_size,
            $this->mime_type,
            $this->extension,
            $this->duration,
            $this->width,
            $this->height,
            $this->page_count,
            $this->scorm_version,
            $this->launch_url,
            $this->metadata_json,
            $this->md5_hash,
            $this->sha256_hash,
            $this->root_parent_id,
            $this->version_number,
            $this->version_label,
            $this->version_notes
        );

        return $stmt->execute();
    }

    /**
     * Read single media record
     */
    public function read_single(): ?array
    {
        $query = "SELECT * FROM " . $this->table . " 
                  WHERE media_id = ? AND tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->media_id, $this->tenant_id);
        $stmt->execute();

        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Read all media for tenant with pagination, search, and sorting
     */
    public function read(int $page = 1, int $per_page = 10, ?string $media_type = null, bool $latest_only = true, ?string $search = null, string $sort_by = 'created_at', string $sort_order = 'DESC'): array
    {
        $offset = ($page - 1) * $per_page;

        $where = ["tenant_id = ?", "is_active = 1"];
        $types = "i";
        $params = [$this->tenant_id];

        if ($latest_only) {
            $where[] = "is_latest_version = 1";
        }

        if ($media_type) {
            $where[] = "media_type = ?";
            $types .= "s";
            $params[] = $media_type;
        }

        if ($search) {
            $where[] = "(title LIKE ? OR original_filename LIKE ?)";
            $types .= "ss";
            $searchTerm = "%" . $search . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $where);

        // Validate sort options
        $allowedSort = ['created_at', 'title', 'file_size', 'updated_at', 'media_type'];
        if (!in_array($sort_by, $allowedSort)) {
            $sort_by = 'created_at';
        }
        $sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM " . $this->table . " WHERE " . $whereClause;
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        // Get records
        $query = "SELECT * FROM " . $this->table . " WHERE " . $whereClause . " ORDER BY " . $sort_by . " " . $sort_order . " LIMIT ? OFFSET ?";
        $types .= "ii";
        $params[] = $per_page;
        $params[] = $offset;

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $result = $stmt->get_result();
        $media = [];
        while ($row = $result->fetch_assoc()) {
            // Format metadata
            if (!empty($row['metadata_json'])) {
                $row['metadata'] = json_decode($row['metadata_json'], true);
            }
            $row['file_size_formatted'] = $this->formatFileSize((int) $row['file_size']);
            $media[] = $row;
        }

        return [
            'data' => $media,
            'meta' => [
                'total' => (int) $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => (int) ceil($total / $per_page)
            ]
        ];
    }

    /**
     * Get version history for a media file
     */
    public function getVersionHistory(): array
    {
        $media = $this->read_single();
        if (!$media) {
            return [];
        }

        $rootId = $media['root_parent_id'] ?? $this->media_id;

        $query = "SELECT m.media_id, m.title, m.version_number, m.version_label, m.version_notes,
                         m.file_size, m.file_url, m.is_latest_version, m.uploaded_by, m.created_at,
                         u.first_name, u.last_name, u.email
                  FROM " . $this->table . " m
                  LEFT JOIN users u ON m.uploaded_by = u.user_id
                  WHERE (m.media_id = ? OR m.root_parent_id = ?)
                  AND m.tenant_id = ? AND m.is_active = 1
                  ORDER BY m.version_number DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $rootId, $rootId, $this->tenant_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $versions = [];
        while ($row = $result->fetch_assoc()) {
            $versions[] = $row;
        }

        return $versions;
    }

    /**
     * Delete media (soft delete)
     */
    public function delete(): array
    {
        // Get media info first for storage update
        $media = $this->read_single();
        
        if (!$media) {
            return [
                'success' => false,
                'error' => 'Media not found'
            ];
        }

        $query = "UPDATE " . $this->table . " SET is_active = 0, deleted_at = NOW() 
                  WHERE media_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->media_id, $this->tenant_id);

        if ($stmt->execute()) {
            // Update tenant storage
            $this->updateTenantStorageRemove($media['media_type'], (int) $media['file_size']);
            
            return [
                'success' => true,
                'message' => 'Media deleted successfully',
                'data' => [
                    'media_id' => $this->media_id,
                    'title' => $media['title']
                ]
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Failed to delete media'
        ];
    }

    /**
     * Delete all versions of a media file
     */
    public function deleteAllVersions(): array
    {
        $media = $this->read_single();
        
        if (!$media) {
            return [
                'success' => false,
                'error' => 'Media not found'
            ];
        }

        $rootId = $media['root_parent_id'] ?? $this->media_id;

        // Get total size of all versions for storage update
        $query = "SELECT SUM(file_size) as total_size, COUNT(*) as count, media_type 
                  FROM " . $this->table . " 
                  WHERE tenant_id = ? AND (media_id = ? OR root_parent_id = ?) AND is_active = 1
                  GROUP BY media_type";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $this->tenant_id, $rootId, $rootId);
        $stmt->execute();
        $sizeInfo = $stmt->get_result()->fetch_assoc();

        // Soft delete all versions
        $query = "UPDATE " . $this->table . " SET is_active = 0, deleted_at = NOW() 
                  WHERE tenant_id = ? AND (media_id = ? OR root_parent_id = ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $this->tenant_id, $rootId, $rootId);

        if ($stmt->execute()) {
            $deletedCount = $stmt->affected_rows;
            
            // Update tenant storage
            if ($sizeInfo) {
                $this->updateTenantStorageRemove($sizeInfo['media_type'], (int) $sizeInfo['total_size'], (int) $sizeInfo['count']);
            }
            
            return [
                'success' => true,
                'message' => "Deleted {$deletedCount} version(s) successfully",
                'data' => [
                    'root_media_id' => $rootId,
                    'deleted_count' => $deletedCount
                ]
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Failed to delete media versions'
        ];
    }

    /**
     * Update media metadata
     */
    public function update(): array
    {
        $media = $this->read_single();
        
        if (!$media) {
            return [
                'success' => false,
                'error' => 'Media not found'
            ];
        }

        $updates = [];
        $types = "";
        $params = [];

        if ($this->title !== null) {
            $updates[] = "title = ?";
            $types .= "s";
            $params[] = $this->title;
        }

        if ($this->description !== null) {
            $updates[] = "description = ?";
            $types .= "s";
            $params[] = $this->description;
        }

        if (empty($updates)) {
            return [
                'success' => false,
                'error' => 'No fields to update'
            ];
        }

        $query = "UPDATE " . $this->table . " SET " . implode(", ", $updates) . ", updated_at = NOW() 
                  WHERE media_id = ? AND tenant_id = ?";
        $types .= "ii";
        $params[] = $this->media_id;
        $params[] = $this->tenant_id;

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            // Return updated media
            $updated = $this->read_single();
            return [
                'success' => true,
                'data' => $updated
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to update media'
        ];
    }

    /**
     * Get latest version of a media file
     */
    public function getLatestVersion(): ?array
    {
        $media = $this->read_single();
        if (!$media) {
            return null;
        }

        $rootId = $media['root_parent_id'] ?? $this->media_id;

        $query = "SELECT * FROM " . $this->table . "
                  WHERE tenant_id = ?
                  AND (media_id = ? OR root_parent_id = ?)
                  AND is_latest_version = 1
                  AND is_active = 1
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $this->tenant_id, $rootId, $rootId);
        $stmt->execute();

        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Compare two versions of a media file
     */
    public function compareVersions(int $compareWithId): array
    {
        $v1 = $this->read_single();

        // Temporarily change media_id to get the second version
        $originalId = $this->media_id;
        $this->media_id = $compareWithId;
        $v2 = $this->read_single();
        $this->media_id = $originalId;

        if (!$v1 || !$v2) {
            return [
                'success' => false,
                'error' => 'One or both media versions not found'
            ];
        }

        // Ensure they're versions of the same file
        $root1 = $v1['root_parent_id'] ?? $originalId;
        $root2 = $v2['root_parent_id'] ?? $compareWithId;

        if ($root1 !== $root2 && $originalId !== $root2 && $compareWithId !== $root1) {
            return [
                'success' => false,
                'error' => 'These files are not versions of the same media'
            ];
        }

        return [
            'success' => true,
            'data' => [
                'version_1' => [
                    'media_id' => (int) $originalId,
                    'version_number' => (int) $v1['version_number'],
                    'version_label' => $v1['version_label'],
                    'file_size' => (int) $v1['file_size'],
                    'file_size_formatted' => $this->formatFileSize((int) $v1['file_size']),
                    'uploaded_at' => $v1['created_at']
                ],
                'version_2' => [
                    'media_id' => (int) $compareWithId,
                    'version_number' => (int) $v2['version_number'],
                    'version_label' => $v2['version_label'],
                    'file_size' => (int) $v2['file_size'],
                    'file_size_formatted' => $this->formatFileSize((int) $v2['file_size']),
                    'uploaded_at' => $v2['created_at']
                ],
                'differences' => [
                    'file_size_diff' => (int) $v2['file_size'] - (int) $v1['file_size'],
                    'file_size_diff_formatted' => $this->formatFileSize(abs((int) $v2['file_size'] - (int) $v1['file_size'])),
                    'title_changed' => $v1['title'] !== $v2['title'],
                    'duration_diff' => ($v2['duration'] ?? 0) - ($v1['duration'] ?? 0),
                    'page_count_diff' => ($v2['page_count'] ?? 0) - ($v1['page_count'] ?? 0)
                ]
            ]
        ];
    }

    /**
     * Revert to this version (make it the latest)
     */
    public function revertToVersion(): array
    {
        $media = $this->read_single();

        if (!$media) {
            return [
                'success' => false,
                'error' => 'Media not found'
            ];
        }

        if ($media['is_latest_version']) {
            return [
                'success' => false,
                'error' => 'This is already the latest version'
            ];
        }

        $rootId = $media['root_parent_id'] ?? $this->media_id;

        $this->conn->begin_transaction();

        try {
            // Mark all versions as not latest
            $query = "UPDATE " . $this->table . " SET is_latest_version = 0
                      WHERE tenant_id = ? AND (media_id = ? OR root_parent_id = ?)";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $this->tenant_id, $rootId, $rootId);
            $stmt->execute();

            // Mark target version as latest
            $query = "UPDATE " . $this->table . " SET is_latest_version = 1
                      WHERE media_id = ? AND tenant_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $this->media_id, $this->tenant_id);
            $stmt->execute();

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Successfully reverted to version ' . $media['version_number'],
                'data' => [
                    'media_id' => $this->media_id,
                    'version_number' => (int) $media['version_number'],
                    'version_label' => $media['version_label']
                ]
            ];

        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'error' => 'Failed to revert version: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get tenant storage usage
     */
    public function getStorageUsage(): ?array
    {
        $query = "SELECT * FROM tenant_storage WHERE tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if (!$result) {
            return null;
        }

        // Format the response
        return [
            'tenant_id' => (int) $result['tenant_id'],
            'storage_limit' => (int) $result['storage_limit'],
            'storage_limit_formatted' => $result['storage_limit'] > 0 ? $this->formatFileSize((int) $result['storage_limit']) : 'Unlimited',
            'total_used' => (int) $result['total_used'],
            'total_used_formatted' => $this->formatFileSize((int) $result['total_used']),
            'total_files' => (int) $result['total_files'],
            'usage_percentage' => $result['storage_limit'] > 0 ? round(($result['total_used'] / $result['storage_limit']) * 100, 2) : 0,
            'breakdown' => [
                'video' => [
                    'used' => (int) $result['video_used'],
                    'used_formatted' => $this->formatFileSize((int) $result['video_used']),
                    'count' => (int) $result['video_count']
                ],
                'audio' => [
                    'used' => (int) $result['audio_used'],
                    'used_formatted' => $this->formatFileSize((int) $result['audio_used']),
                    'count' => (int) $result['audio_count']
                ],
                'pdf' => [
                    'used' => (int) $result['pdf_used'],
                    'used_formatted' => $this->formatFileSize((int) $result['pdf_used']),
                    'count' => (int) $result['pdf_count']
                ],
                'scorm' => [
                    'used' => (int) $result['scorm_used'],
                    'used_formatted' => $this->formatFileSize((int) $result['scorm_used']),
                    'count' => (int) $result['scorm_count']
                ]
            ],
            'updated_at' => $result['updated_at']
        ];
    }

    /**
     * Recalculate tenant storage from media table
     */
    public function recalculateStorage(): array
    {
        try {
            // Calculate totals from media table
            $query = "SELECT 
                        COALESCE(SUM(file_size), 0) as total_used,
                        COALESCE(SUM(CASE WHEN media_type = 'VIDEO' THEN file_size ELSE 0 END), 0) as video_used,
                        COALESCE(SUM(CASE WHEN media_type = 'AUDIO' THEN file_size ELSE 0 END), 0) as audio_used,
                        COALESCE(SUM(CASE WHEN media_type = 'PDF' THEN file_size ELSE 0 END), 0) as pdf_used,
                        COALESCE(SUM(CASE WHEN media_type = 'SCORM' THEN file_size ELSE 0 END), 0) as scorm_used,
                        COUNT(*) as total_files,
                        SUM(CASE WHEN media_type = 'VIDEO' THEN 1 ELSE 0 END) as video_count,
                        SUM(CASE WHEN media_type = 'AUDIO' THEN 1 ELSE 0 END) as audio_count,
                        SUM(CASE WHEN media_type = 'PDF' THEN 1 ELSE 0 END) as pdf_count,
                        SUM(CASE WHEN media_type = 'SCORM' THEN 1 ELSE 0 END) as scorm_count
                      FROM media
                      WHERE tenant_id = ? AND is_active = 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $this->tenant_id);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_assoc();

            // Update or insert storage record
            $query = "INSERT INTO tenant_storage (
                        tenant_id, total_used, video_used, audio_used, pdf_used, scorm_used,
                        total_files, video_count, audio_count, pdf_count, scorm_count
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE
                        total_used = VALUES(total_used),
                        video_used = VALUES(video_used),
                        audio_used = VALUES(audio_used),
                        pdf_used = VALUES(pdf_used),
                        scorm_used = VALUES(scorm_used),
                        total_files = VALUES(total_files),
                        video_count = VALUES(video_count),
                        audio_count = VALUES(audio_count),
                        pdf_count = VALUES(pdf_count),
                        scorm_count = VALUES(scorm_count),
                        updated_at = NOW()";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param(
                "iiiiiiiiiii",
                $this->tenant_id,
                $data['total_used'],
                $data['video_used'],
                $data['audio_used'],
                $data['pdf_used'],
                $data['scorm_used'],
                $data['total_files'],
                $data['video_count'],
                $data['audio_count'],
                $data['pdf_count'],
                $data['scorm_count']
            );
            $stmt->execute();

            // Get updated storage
            $storage = $this->getStorageUsage();

            return [
                'success' => true,
                'data' => $storage
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to recalculate storage: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update tenant storage after upload
     */
    public function updateTenantStorageAdd(string $mediaType, int $fileSize): void
    {
        try {
            // Insert or update tenant storage record
            $query = "INSERT INTO tenant_storage (tenant_id, total_used, total_files)
                      VALUES (?, ?, 1)
                      ON DUPLICATE KEY UPDATE
                        total_used = total_used + ?,
                        total_files = total_files + 1,
                        updated_at = NOW()";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $this->tenant_id, $fileSize, $fileSize);
            $stmt->execute();

            // Update type-specific columns
            $typeColumn = strtolower($mediaType) . '_used';
            $countColumn = strtolower($mediaType) . '_count';
            
            $query = "UPDATE tenant_storage SET 
                        {$typeColumn} = {$typeColumn} + ?,
                        {$countColumn} = {$countColumn} + 1
                      WHERE tenant_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $fileSize, $this->tenant_id);
            $stmt->execute();
        } catch (Exception $e) {
            // Log error but don't fail the upload
            error_log("Failed to update tenant storage: " . $e->getMessage());
        }
    }

    /**
     * Update tenant storage after delete
     */
    public function updateTenantStorageRemove(string $mediaType, int $fileSize, int $count = 1): void
    {
        try {
            $query = "UPDATE tenant_storage SET 
                        total_used = GREATEST(0, total_used - ?),
                        total_files = GREATEST(0, total_files - ?),
                        updated_at = NOW()
                      WHERE tenant_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $fileSize, $count, $this->tenant_id);
            $stmt->execute();

            // Update type-specific columns
            $typeColumn = strtolower($mediaType) . '_used';
            $countColumn = strtolower($mediaType) . '_count';
            
            $query = "UPDATE tenant_storage SET 
                        {$typeColumn} = GREATEST(0, {$typeColumn} - ?),
                        {$countColumn} = GREATEST(0, {$countColumn} - ?)
                      WHERE tenant_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $fileSize, $count, $this->tenant_id);
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Failed to update tenant storage: " . $e->getMessage());
        }
    }

    /**
     * Set properties from extracted metadata
     */
    private function setPropertiesFromMetadata(array $fileInfo, array $meta, array $versionInfo): void
    {
        $this->original_filename = $fileInfo['original_name'];
        $this->stored_filename = $fileInfo['stored_name'];
        $this->file_path = $fileInfo['relative_path'];
        $this->file_url = $this->generateFileUrl($fileInfo['relative_path']);
        $this->file_size = $meta['file_size'];
        $this->mime_type = $meta['mime_type'];
        $this->extension = $meta['extension'];
        $this->duration = $meta['duration'] ?? null;
        $this->width = $meta['width'] ?? null;
        $this->height = $meta['height'] ?? null;
        $this->page_count = $meta['page_count'] ?? null;
        $this->scorm_version = $meta['scorm_version'] ?? null;
        $this->launch_url = $meta['launch_url'] ?? null;
        $this->metadata_json = json_encode($meta);
        $this->md5_hash = $meta['md5_hash'] ?? null;
        $this->sha256_hash = $meta['sha256_hash'] ?? null;
        $this->root_parent_id = $versionInfo['parent_media_id'];
        $this->version_number = $versionInfo['version_number'];

        if (!$this->title) {
            $this->title = pathinfo($fileInfo['original_name'], PATHINFO_FILENAME);
        }

        if (!$this->version_label) {
            $this->version_label = 'v' . $versionInfo['version_number'] . '.0';
        }
    }

    /**
     * Handle version control logic
     */
    private function handleVersioning(): array
    {
        $parentMediaId = $this->parent_media_id;
        $versionNumber = 1;

        if ($parentMediaId) {
            $this->media_id = $parentMediaId;
            $parent = $this->read_single();

            if (!$parent) {
                throw new Exception('Parent media not found');
            }

            if ($parent['tenant_id'] != $this->tenant_id) {
                throw new Exception('Parent media belongs to different tenant');
            }

            if ($parent['media_type'] !== $this->media_type) {
                throw new Exception('New version must be same media type as parent (' . $parent['media_type'] . ')');
            }

            $rootParentId = $parent['root_parent_id'] ?? $parentMediaId;
            $versionNumber = $this->getNextVersionNumber($rootParentId);
            $parentMediaId = $rootParentId;
        }

        return [
            'parent_media_id' => $parentMediaId,
            'version_number' => $versionNumber,
        ];
    }

    /**
     * Get next version number
     */
    private function getNextVersionNumber(int $rootParentId): int
    {
        $query = "SELECT MAX(version_number) as max_version FROM " . $this->table . "
                  WHERE (media_id = ? OR root_parent_id = ?) AND tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $rootParentId, $rootParentId, $this->tenant_id);
        $stmt->execute();

        $result = $stmt->get_result()->fetch_assoc();
        return ($result['max_version'] ?? 0) + 1;
    }

    /**
     * Update parent's latest version reference
     */
    private function updateParentLatestVersion(int $rootParentId): void
    {
        $query = "UPDATE " . $this->table . " SET is_latest_version = 0
                  WHERE (media_id = ? OR root_parent_id = ?) AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $rootParentId, $rootParentId, $this->tenant_id);
        $stmt->execute();

        $query = "UPDATE " . $this->table . " SET is_latest_version = 1 WHERE media_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->media_id);
        $stmt->execute();
    }

    /**
     * Validate basic upload requirements
     */
    private function validateUpload(array $file): void
    {
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            throw new Exception('No file uploaded');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception($this->getUploadErrorMessage($file['error']));
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Invalid file upload');
        }
        if ($file['size'] <= 0) {
            throw new Exception('File is empty');
        }
    }

    /**
     * Detect media type from file
     */
    private function detectMediaType(array $file): string
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType = $this->getSecureMimeType($file['tmp_name']);

        foreach ($this->allowedTypes as $type => $config) {
            if (in_array($extension, $config['extensions']) && in_array($mimeType, $config['mimes'])) {
                if ($type === 'SCORM') {
                    if ($this->isScormPackage($file['tmp_name'])) {
                        return 'SCORM';
                    }
                    throw new Exception('ZIP file is not a valid SCORM package');
                }
                return $type;
            }
        }

        throw new Exception('File type not allowed. Supported types: VIDEO, AUDIO, PDF, SCORM');
    }

    /**
     * Validate file against type-specific rules
     */
    private function validateFileType(array $file): void
    {
        $config = $this->allowedTypes[$this->media_type];

        if ($file['size'] > $config['max_size']) {
            throw new Exception(sprintf(
                '%s files cannot exceed %s',
                $this->media_type,
                $this->formatFileSize($config['max_size'])
            ));
        }
    }

    /**
     * Get secure MIME type using fileinfo
     */
    private function getSecureMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mimeType;
    }

    /**
     * Check if ZIP file is a SCORM package
     */
    private function isScormPackage(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }

        $isScorm = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            foreach ($this->scormIndicators as $indicator) {
                if (basename($name) === $indicator) {
                    $isScorm = true;
                    break 2;
                }
            }
        }

        $zip->close();
        return $isScorm;
    }

    /**
     * Generate secure file path and name
     */
    private function generateFilePath(array $file): array
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        
        // Sanitize filename
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
        $safeName = substr($safeName, 0, 100);
        
        // Generate unique hash
        $hash = substr(md5(uniqid(mt_rand(), true)), 0, 12);
        
        // Build stored filename
        $storedName = $safeName . '_' . $hash . '.' . $extension;
        
        // Directory structure: tenant_id/media_type/year/month/
        $typeDir = strtolower($this->media_type);
        $dateDir = date('Y/m');
        $relativeDir = "tenant_{$this->tenant_id}/{$typeDir}/{$dateDir}";
        $fullDir = $this->uploadDir . '/' . $relativeDir;
        
        // Create directory if not exists
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }
        
        // SCORM extract directory
        $scormDir = pathinfo($storedName, PATHINFO_FILENAME);
        
        return [
            'original_name' => $file['name'],
            'stored_name' => $storedName,
            'relative_path' => $relativeDir . '/' . $storedName,
            'full_path' => $fullDir . '/' . $storedName,
            'directory' => $fullDir,
            'scorm_dir' => $scormDir
        ];
    }

    /**
     * Extract metadata based on media type
     */
    private function extractMetadata(string $filePath, array $file): array
    {
        $meta = [
            'original_name' => $file['name'],
            'file_size' => filesize($filePath),
            'mime_type' => $this->getSecureMimeType($filePath),
            'extension' => strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)),
            'md5_hash' => md5_file($filePath),
            'sha256_hash' => hash_file('sha256', $filePath),
        ];

        switch ($this->media_type) {
            case 'VIDEO':
                $meta = array_merge($meta, $this->extractVideoMetadata($filePath));
                break;
            case 'AUDIO':
                $meta = array_merge($meta, $this->extractAudioMetadata($filePath));
                break;
            case 'PDF':
                $meta = array_merge($meta, $this->extractPdfMetadata($filePath));
                break;
            case 'SCORM':
                $meta = array_merge($meta, $this->extractScormMetadata($filePath));
                break;
        }

        return $meta;
    }

    /**
     * Extract video metadata using ffprobe
     */
    private function extractVideoMetadata(string $filePath): array
    {
        $meta = [
            'duration' => null,
            'duration_formatted' => null,
            'width' => null,
            'height' => null,
            'resolution' => null,
            'bitrate' => null,
            'codec' => null,
            'framerate' => null,
        ];

        if ($this->isCommandAvailable('ffprobe')) {
            $cmd = sprintf(
                'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>&1',
                escapeshellarg($filePath)
            );
            $output = $this->executeCommand($cmd);
            
            if ($output) {
                $data = json_decode($output, true);

                if ($data) {
                    if (isset($data['format'])) {
                        $meta['duration'] = (float) ($data['format']['duration'] ?? 0);
                        $meta['duration_formatted'] = $this->formatDuration($meta['duration']);
                        $meta['bitrate'] = (int) ($data['format']['bit_rate'] ?? 0);
                    }

                    foreach ($data['streams'] ?? [] as $stream) {
                        if ($stream['codec_type'] === 'video') {
                            $meta['width'] = (int) ($stream['width'] ?? 0);
                            $meta['height'] = (int) ($stream['height'] ?? 0);
                            $meta['resolution'] = $meta['width'] . 'x' . $meta['height'];
                            $meta['codec'] = $stream['codec_name'] ?? null;

                            if (isset($stream['r_frame_rate'])) {
                                $parts = explode('/', $stream['r_frame_rate']);
                                if (count($parts) === 2 && $parts[1] > 0) {
                                    $meta['framerate'] = round($parts[0] / $parts[1], 2);
                                }
                            }
                            break;
                        }
                    }
                }
            }
        }

        return $meta;
    }

    /**
     * Extract audio metadata using ffprobe
     */
    private function extractAudioMetadata(string $filePath): array
    {
        $meta = [
            'duration' => null,
            'duration_formatted' => null,
            'bitrate' => null,
            'sample_rate' => null,
            'channels' => null,
            'codec' => null,
        ];

        if ($this->isCommandAvailable('ffprobe')) {
            $cmd = sprintf(
                'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>&1',
                escapeshellarg($filePath)
            );
            $output = $this->executeCommand($cmd);
            
            if ($output) {
                $data = json_decode($output, true);

                if ($data) {
                    if (isset($data['format'])) {
                        $meta['duration'] = (float) ($data['format']['duration'] ?? 0);
                        $meta['duration_formatted'] = $this->formatDuration($meta['duration']);
                        $meta['bitrate'] = (int) ($data['format']['bit_rate'] ?? 0);
                    }

                    foreach ($data['streams'] ?? [] as $stream) {
                        if ($stream['codec_type'] === 'audio') {
                            $meta['sample_rate'] = (int) ($stream['sample_rate'] ?? 0);
                            $meta['channels'] = (int) ($stream['channels'] ?? 0);
                            $meta['codec'] = $stream['codec_name'] ?? null;
                            break;
                        }
                    }
                }
            }
        }

        return $meta;
    }

    /**
     * Extract PDF metadata using pdfinfo
     */
    private function extractPdfMetadata(string $filePath): array
    {
        $meta = [
            'page_count' => null,
            'pdf_version' => null,
            'author' => null,
            'title' => null,
            'creator' => null,
        ];

        if ($this->isCommandAvailable('pdfinfo')) {
            $cmd = sprintf('pdfinfo %s 2>&1', escapeshellarg($filePath));
            $output = $this->executeCommand($cmd);

            if ($output) {
                if (preg_match('/Pages:\s*(\d+)/i', $output, $matches)) {
                    $meta['page_count'] = (int) $matches[1];
                }
                if (preg_match('/PDF version:\s*([\d.]+)/i', $output, $matches)) {
                    $meta['pdf_version'] = $matches[1];
                }
                if (preg_match('/Author:\s*(.+)/i', $output, $matches)) {
                    $meta['author'] = trim($matches[1]);
                }
                if (preg_match('/Title:\s*(.+)/i', $output, $matches)) {
                    $meta['title'] = trim($matches[1]);
                }
                if (preg_match('/Creator:\s*(.+)/i', $output, $matches)) {
                    $meta['creator'] = trim($matches[1]);
                }
            }
        }

        return $meta;
    }

    /**
     * Extract SCORM package metadata
     */
    private function extractScormMetadata(string $filePath): array
    {
        $meta = [
            'scorm_version' => null,
            'manifest_identifier' => null,
            'organization_title' => null,
            'launch_url' => null,
            'total_items' => 0,
        ];

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return $meta;
        }

        $manifestContent = null;
        $manifestPath = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (basename($name) === 'imsmanifest.xml') {
                $manifestContent = $zip->getFromIndex($i);
                $manifestPath = dirname($name);
                break;
            }
        }

        $zip->close();

        if ($manifestContent) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($manifestContent);

            if ($xml) {
                $namespaces = $xml->getNamespaces(true);
                $meta['manifest_identifier'] = (string) ($xml['identifier'] ?? '');

                foreach ($namespaces as $prefix => $uri) {
                    if (strpos($uri, 'adlcp') !== false) {
                        $meta['scorm_version'] = strpos($uri, '2004') !== false ? 'SCORM 2004' : 'SCORM 1.2';
                        break;
                    }
                }

                $orgs = $xml->organizations->organization ?? [];
                foreach ($orgs as $org) {
                    $meta['organization_title'] = (string) ($org->title ?? '');
                    $meta['total_items'] = count($org->item ?? []);
                    break;
                }

                $resources = $xml->resources->resource ?? [];
                foreach ($resources as $resource) {
                    $href = (string) ($resource['href'] ?? '');
                    if ($href) {
                        $meta['launch_url'] = $manifestPath && $manifestPath !== '.' ? $manifestPath . '/' . $href : $href;
                        break;
                    }
                }
            }
        }

        return $meta;
    }

    /**
     * Process and extract SCORM package
     */
    private function processScormPackage(string $zipPath, string $directory, string $folder): array
    {
        $extractDir = $directory . '/' . $folder;

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new Exception('Failed to open SCORM package');
        }

        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        $zip->extractTo($extractDir);
        $totalFiles = $zip->numFiles;
        $zip->close();

        $extractedSize = $this->getDirectorySize($extractDir);

        return [
            'extracted_path' => $extractDir,
            'extracted_files' => $totalFiles,
            'extracted_size' => $extractedSize,
            'extracted_size_formatted' => $this->formatFileSize($extractedSize),
        ];
    }

    /**
     * Ensure upload directories exist
     */
    private function ensureUploadDirectories(): void
    {
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $htaccess = $this->uploadDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.php$\">\n    Deny from all\n</FilesMatch>");
        }
    }

    /**
     * Generate public file URL
     */
    private function generateFileUrl(string $relativePath): string
    {
        $baseUrl = rtrim($_ENV['APP_URL'] ?? 'https://api.centrallms.com', '/');
        return $baseUrl . '/uploads/' . $relativePath;
    }

    /**
     * Check if a command is available
     * Returns false if shell functions are disabled
     */
    private function isCommandAvailable(string $command): bool
    {
        // Check if shell functions are disabled
        $disabled = explode(',', ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        if (in_array('shell_exec', $disabled) || in_array('exec', $disabled)) {
            return false;
        }

        // Try using exec instead of shell_exec
        $output = [];
        $returnVar = 1;
        @exec(sprintf('which %s 2>/dev/null', escapeshellarg($command)), $output, $returnVar);

        return $returnVar === 0 && !empty($output);
    }

    /**
     * Execute shell command safely
     * Returns null if shell functions are disabled
     */
    private function executeCommand(string $command): ?string
    {
        // Check if shell functions are disabled
        $disabled = explode(',', ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        if (in_array('shell_exec', $disabled) && in_array('exec', $disabled)) {
            return null;
        }

        // Try exec first (often less restricted than shell_exec)
        if (!in_array('exec', $disabled)) {
            $output = [];
            @exec($command, $output);
            return implode("\n", $output);
        }

        // Fallback to shell_exec
        if (!in_array('shell_exec', $disabled)) {
            return @shell_exec($command);
        }

        return null;
    }

    /**
     * Get directory size recursively
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    /**
     * Format file size to human readable
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Format duration to HH:MM:SS
     */
    private function formatDuration(?float $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%02d:%02d', $minutes, $secs);
    }

    /**
     * Get upload error message
     */
    private function getUploadErrorMessage(int $error): string
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
        ];

        return $messages[$error] ?? 'Unknown upload error';
    }
}
