import { decodeSessionBlob } from '../../core/utils/session-blob';
import {Component, inject, Inject, OnInit, TemplateRef, ViewChild} from '@angular/core';
import {SideComponent} from "../../partials/side/side.component";
import {TopComponent} from "../../partials/top/top.component";
import {TuiButton, TuiDialogService, TuiIcon, TuiLoader} from "@taiga-ui/core";
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {CommonModule} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {MediaUploadComponent} from './upload/media-upload.component';
import {MediaData} from '../../services/media.service';
import {GlobalComponent} from '../../global-component';
import {MediaReadRequest, MediaItem, Pagination} from '../../media.interfaces';
import {MediaPreviewDialogComponent} from './media-preview/media-preview.component';
import {PolymorpheusComponent} from '@taiga-ui/polymorpheus';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import {TUI_CONFIRM, TuiConfirmData} from '@taiga-ui/kit';
@Component({
  selector: 'app-media',
  standalone: true,
  imports: [
    SideComponent,
    TopComponent,
    TuiIcon,
    CommonModule,
    FormsModule,
    MediaUploadComponent,
    TuiLoader,
    AdmintopComponent,
    TuiButton
  ],
  templateUrl: './media.component.html',
  styleUrl: './media.component.css'
})
export class MediaComponent implements OnInit {
  private readonly dialogs = inject(TuiDialogService);
  @ViewChild('deleteDialog')
  deleteDialog!: TemplateRef<any>;
  mediaList: MediaItem[] = [];
  pagination: Pagination = { total: 0, page: 1, per_page: 0, total_pages: 0 };
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
    private readonly dialogService: TuiDialogService

  ) {
  }
  ui_controls = {
    is_loading: false,
    no_recent: false,
    viewing_dashboard: true,
    viewing_recent_orders: false,
    viewing_upload: false
  };
  initial = {
    user_id: 0,
    tenant_id: 0,
    token: ""
  }
  delete = {
    user_id: 0,
    tenant_id: 0,
    token: "",
    media_id: 0,
    delete_all_versions: false
  }
  request: MediaReadRequest = {
    token: '',
    user_id: 0,
    tenant_id: 0,
    page: 0,
    per_page: 0,
    media_type: '',
    latest_only: true,
    sort_by: 'created_at',
    sort_order: 'DESC'
  };
  tenant_storage_response = {
    code: 0,
    status: "",
    data: {
      storage: {
        tenant_id: 0,
        storage_limit: 0,
        storage_limit_formatted: "",
        total_used: 0,
        total_used_formatted: "",
        total_files: 5,
        usage_percentage: 0,
        breakdown: {
          video: { used: 0, used_formatted: "", count: 0 },
          audio: { used: 0, used_formatted: "", count: 0 },
          pdf: { used: 0, used_formatted: "", count: 0 },
          scorm: { used: 0, used_formatted: "", count: 0 }
        }
      }
    }
  };
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
    this.storageStatistics();
    this.list_media();
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  sign_out(): void {
    this.success_notification("User logged out successfully.");
    this.router.navigate(['/']).then(r => console.log(r));
  }
  goBack() {
    this.router.navigate(['/administration']).then(r => console.log(r));
  }
  // Optional: for uploading new versions
  parentMediaId: number | null = null;

  // Uploaded files list
  uploadedFiles: MediaData[] = [];

  // Loading state
  isUploading: boolean = false;

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


  storageStatistics() {
    this.initial.user_id = this.user_session.id;
    this.initial.tenant_id = this.user_session.tenant_id;
    this.initial.token = this.user_session.token;

    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.MediaStorage)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.tenant_storage_response = response;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  list_media(page: number =  1, per_page: number = 8) {
    this.request.user_id = this.user_session.id;
    this.request.token = this.user_session.token;
    this.request.tenant_id = this.user_session.tenant_id;
    this.request.per_page = per_page;
    this.request.page = page;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.request, GlobalComponent.MediaList)
      .subscribe(({ next: (response) => {
          if (response.status === "success") {
            this.mediaList = response.data.media;
            this.pagination = response.data.meta;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  delete_media(media: number) {
    this.delete.user_id = this.user_session.id;
    this.delete.token = this.user_session.token;
    this.delete.tenant_id = this.user_session.tenant_id;
    this.delete.media_id = media;
    this.crudService.post_request(this.delete, GlobalComponent.deleteMedia)
      .subscribe(({ next: (response) => {
          if (response.status === "success") {
            this.success_notification(response.message);
            this.list_media();
          }
        }
      }))
  }
  openPreview(media: {
    type: 'VIDEO' | 'AUDIO' | 'PDF' | 'SCORM' | '';
    title: string;
    url: string;
    id: number;
  }) {
    this.dialogService.open(
      new PolymorpheusComponent(MediaPreviewDialogComponent),
      { data: media, size: 'page', closeable: true}
    ).subscribe();
    console.log(media);
  }
  paginate() {
    this.list_media(1, this.request.per_page)
  }

  confirmDelete(media_id: number, media_name: string): void {
    this.dialogs
      .open<boolean>(this.deleteDialog, { label: 'Are you sure you want to delete: ' + media_name })
      .subscribe((confirmed: boolean) => {
        if (confirmed) {
          this.delete_media(media_id);
        }
      });
  }

  private deleteItem(): void {
    console.log('Item deleted');
    // call API here
  }
}
