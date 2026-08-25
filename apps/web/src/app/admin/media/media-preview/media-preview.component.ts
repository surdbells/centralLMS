import { decodeSessionBlob } from '../../../core/utils/session-blob';
import {Component, Inject, OnInit} from '@angular/core';
import { TuiDialogContext } from '@taiga-ui/core';
import {POLYMORPHEUS_CONTEXT} from '@taiga-ui/polymorpheus';
import {CommonModule, NgSwitch, NgSwitchCase} from '@angular/common';
import {DomSanitizer, SafeResourceUrl} from '@angular/platform-browser';
import {ScormAPI} from '../../../scorm-api';
import {MediaUploadComponent} from '../upload/media-upload.component';
import {MediaData} from '../../../services/media.service';
import {HotToastService} from '@ngneat/hot-toast';

export type MediaType = 'VIDEO' | 'AUDIO' | 'PDF' | 'SCORM';

export interface MediaPreviewData {
  type: MediaType;
  title: string;
  url: string;
  id: number;
}

@Component({
  selector: 'app-media-preview-component',
  templateUrl: './media-preview.component.html',
  styleUrls: ['./media-preview.component.css'],
  imports: [CommonModule, MediaUploadComponent],
  standalone: true
})
export class MediaPreviewDialogComponent implements OnInit{
  public data!: MediaPreviewData;
  public safeUrl!: SafeResourceUrl;

  session_data: any = ""
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
  }

  constructor(
    @Inject(POLYMORPHEUS_CONTEXT)
    private readonly context: TuiDialogContext<void, MediaPreviewData>,
    private readonly sanitizer: DomSanitizer,
    private toast: HotToastService,

  ) {
    this.data = this.context.data;
    // 🔥 Inject BOTH APIs
    const api = new ScormAPI();
    (window as any).API = api;            // SCORM 1.2
    (window as any).API_1484_11 = api;    // SCORM 2004
    // Trust iframe URL
    this.safeUrl = this.sanitizer.bypassSecurityTrustResourceUrl(
      this.data.url
    );
  }

  // Optional: for uploading new versions
  parentMediaId: number | null = null;

  // Uploaded files list
  uploadedFiles: MediaData[] = [];

  // Loading state
  isUploading: boolean = false;
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  /**
   * Called when a single file upload completes successfully
   */
  onFileUploaded(media: MediaData): void {
    console.log('File uploaded successfully:', media);
    this.uploadedFiles.push(media);
  }

  /**
   * Called when a file upload fails
   */
  onUploadError(error: { file: File; error: string }): void {
    console.error('Upload failed:', error.file.name, error.error);
    this.error_notification(`Failed to upload ${error.file.name}: ${error.error}`)
  }

  /**
   * Called when all files in the queue have been processed
   */
  onAllUploadsComplete(mediaList: MediaData[]): void {
    console.log('All uploads complete:', mediaList);
    this.isUploading = false;
    if (mediaList.length > 0) {
      this.success_notification(`Media uploaded successfully " ${mediaList.length} file(s)`);
    }
  }

  /**
   * Remove a file from the uploaded list
   */
  removeUploadedFile(index: number): void {
    this.uploadedFiles.splice(index, 1);
  }
  clearUploadedFiles(): void {
    this.uploadedFiles = [];
  }
}
